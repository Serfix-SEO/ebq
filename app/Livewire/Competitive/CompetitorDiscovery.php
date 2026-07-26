<?php

namespace App\Livewire\Competitive;

use App\Models\CompetitorDiscoveryRun;
use App\Models\RankTrackingKeyword;
use App\Models\Website;
use App\Services\Competitive\CompetitorDiscoveryService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Auto-discovers a website's organic competitors. Shows the last persisted run
 * immediately, dispatches a fresh fan-out on demand, and polls the run row
 * until it finishes. Falls back to a manual seed textarea when the site has no
 * Search Console data to seed from.
 */
class CompetitorDiscovery extends Component
{
    /** Manual seed keywords (newline/comma separated) — used when no GSC. */
    public string $seedsInput = '';

    public bool $includeGiants = false;

    public ?string $runId = null;

    public string $status = '';

    public ?string $errorMessage = null;

    public ?string $notice = null;

    private function website(): ?Website
    {
        $id = session('current_website_id');

        // Gate on access — Livewire actions don't re-run the route middleware that
        // validates current_website_id, so trust the session id only if the current
        // user can still view it (mirrors every other website-scoped component).
        if (($id === null || $id === '') || ! Auth::user()?->canViewWebsiteId($id)) {
            return null;
        }

        return Website::find($id);
    }

    public function discover(CompetitorDiscoveryService $service): void
    {
        $this->reset(['errorMessage', 'notice', 'runId', 'status']);

        $website = $this->website();
        if ($website === null) {
            $this->errorMessage = 'Select a website first.';

            return;
        }

        $seeds = $this->parseSeeds($this->seedsInput);
        if (! $website->hasGsc() && $seeds === []) {
            $this->errorMessage = 'This website has no Search Console data yet — enter a few seed keywords to discover competitors.';

            return;
        }

        $run = $service->queueRunIfStale($website, Auth::id(), $seeds, force: true);
        if ($run === null) {
            $this->notice = 'No keywords were available to sample. Add Search Console or enter seed keywords.';

            return;
        }

        $this->runId = $run->run_id;
        $this->status = $run->status;
    }

    /** Polled by the view while a discovery run is in flight. */
    public function poll(): void
    {
        if ($this->runId === null) {
            return;
        }

        $run = CompetitorDiscoveryRun::query()->where('run_id', $this->runId)->first();
        if ($run === null) {
            $this->runId = null;

            return;
        }

        $this->status = $run->status;
        if (! $run->isFinished()) {
            return;
        }

        if ($run->status === CompetitorDiscoveryRun::STATUS_FAILED) {
            $this->errorMessage = $run->error ?: 'Competitor discovery failed. Please try again.';
        }
        $this->runId = null;
    }

    public function isPolling(): bool
    {
        return $this->runId !== null
            && in_array($this->status, [CompetitorDiscoveryRun::STATUS_QUEUED, CompetitorDiscoveryRun::STATUS_RUNNING], true);
    }

    /** Push the top-N discovered domains into the rank tracker's competitor set. */
    public function trackTop(CompetitorDiscoveryService $service): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }

        $domains = $service->resultsFor($website->id)
            ->take(5)
            ->pluck('competitor_domain')
            ->all();
        if ($domains === []) {
            $this->notice = 'No competitors to track yet — run discovery first.';

            return;
        }

        $updated = 0;
        RankTrackingKeyword::query()
            ->where('website_id', $website->id)
            ->where('is_active', true)
            ->each(function (RankTrackingKeyword $kw) use ($domains, &$updated): void {
                $existing = is_array($kw->competitors) ? $kw->competitors : [];
                $merged = array_values(array_unique([...$existing, ...$domains]));
                if ($merged !== $existing) {
                    $kw->forceFill(['competitors' => $merged])->save();
                    $updated++;
                }
            });

        $this->notice = $updated > 0
            ? "Added the top competitors to {$updated} tracked keyword(s)."
            : 'No active tracked keywords to update (add keywords in Rank Tracking first).';
    }

    /** Whether an opt-in topical classification is in flight (drives the poll). */
    public bool $topicPending = false;

    /**
     * Opt-in: classify the discovered competitors' topics via the LLM. Gated
     * behind this explicit click (never run during discovery) so the Site
     * Explorer never racks up per-domain LLM spend automatically. Cached topics
     * make a repeat click nearly free.
     */
    public function classifyTopics(): void
    {
        $this->reset(['errorMessage', 'notice']);

        $website = $this->website();
        if ($website === null) {
            return;
        }

        $hasCompetitors = \App\Models\DiscoveredCompetitor::query()
            ->forWebsite($website->id)->exists();
        if (! $hasCompetitors) {
            $this->notice = 'Run discovery first, then classify topics.';

            return;
        }

        \App\Jobs\Competitive\ClassifyCompetitorTopicJob::dispatch($website->id);
        $this->topicPending = true;
        $this->notice = 'Classifying competitor topics — this updates in a moment.';
    }

    /** Poll target while a topical classification is running. */
    public function refreshTopics(): void
    {
        if (! $this->topicPending) {
            return;
        }
        $website = $this->website();
        if ($website === null) {
            $this->topicPending = false;

            return;
        }
        // Cleared once any competitor has been tagged (or after the poll's own
        // client-side attempts elapse — a failed LLM leaves the flag, but the
        // notice already told the user, and a manual refresh clears it).
        $tagged = \App\Models\DiscoveredCompetitor::query()
            ->forWebsite($website->id)
            ->whereNotNull('topic')
            ->exists();
        if ($tagged) {
            $this->topicPending = false;
        }
    }

    /** @return list<string> */
    private function parseSeeds(string $raw): array
    {
        $parts = preg_split('/[\n,]+/', $raw) ?: [];
        $seen = [];
        $out = [];
        foreach ($parts as $p) {
            $s = trim($p);
            if ($s === '') {
                continue;
            }
            $key = mb_strtolower($s);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $s;
        }

        return $out;
    }

    public function export(CompetitorDiscoveryService $service)
    {
        $website = $this->website();
        $rows = $website ? $service->resultsFor($website->id) : collect();
        $filename = 'competitors-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Domain', 'Score', 'Appearances', 'Keywords sampled', 'Avg position', 'Best position', 'Domain authority', 'Page authority', 'Referring domains', 'Backlinks', 'Topic']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->competitor_domain, $r->score, $r->appearances, $r->keywords_sampled,
                    $r->avg_position, $r->best_position, $r->domain_authority,
                    $r->page_authority, $r->referring_domains, $r->backlinks, $r->topic,
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render(CompetitorDiscoveryService $service, \App\Services\Competitive\CompetitorEnricher $enricher)
    {
        $website = $this->website();
        $competitors = $website ? $service->resultsFor($website->id) : collect();
        $lastRun = $website ? $service->latestRun($website->id) : null;

        // Organic-traffic chart: the site's own monthly series, plus the top-3
        // competitors for optional overlay (all read from the shared cache — no
        // API cost at render time).
        $siteSeries = $website ? $enricher->trafficSeries((string) $website->domain) : [];
        $competitorSeries = $competitors->take(3)
            ->mapWithKeys(fn ($c) => [$c->competitor_domain => $enricher->trafficSeries($c->competitor_domain)])
            ->filter(fn ($s) => $s !== [])
            ->all();

        return view('livewire.competitive.competitor-discovery', [
            'website' => $website,
            'competitors' => $competitors,
            'lastRun' => $lastRun,
            'hasGsc' => (bool) $website?->hasGsc(),
            'siteSeries' => $siteSeries,
            'competitorSeries' => $competitorSeries,
        ]);
    }
}
