<?php

namespace App\Livewire\Content;

use App\Jobs\Content\AuditAeoReadinessJob;
use App\Models\ContentAeoAudit;
use App\Models\ContentIntegration;
use App\Models\Website;
use App\Services\Content\Aeo\AeoSignalReader;
use App\Services\Content\ContentEntitlements;
use App\Support\Aeo\AeoSampleData;
use App\Support\Aeo\AiAgents;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * /content/ai-visibility — "are the AI answers finding you?"
 *
 * Three honest signals, and the page is careful about which is which:
 *   - readiness: can the engines read the site (robots.txt per AI agent,
 *     llms.txt, structured data). Always available.
 *   - crawler hits: who actually fetched pages. Only for sites running our
 *     plugin or kit — absence is "not installed", never "no crawlers came".
 *   - AI referrals: people who arrived from an AI answer. Needs GA connected.
 *
 * Client-copy invariant: no internal states, no reason codes, and nothing that
 * implies we can see inside ChatGPT. We report what the site allows, what
 * fetched it, and who arrived.
 *
 * **Paid-only, with an example for everyone else.** Free signups see a sample
 * report labelled as one — loudly, at the top of the page and again on every
 * panel — plus the \$1 first-month offer. Their own site is never checked and
 * never read here: the teaser is built entirely from
 * {@see AeoSampleData}, so no real figure can hide behind a
 * "sample" label and no outbound request is made on a free account's behalf.
 * The gate is {@see ContentEntitlements::hasPaidContentAccess()} — the free
 * article trial does not open this page's real data.
 */
class AiVisibility extends Component
{
    public ?string $websiteId = null;

    /** Set for this request only, after the client asks for a fresh check. */
    public bool $checkQueued = false;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id')
            ?: Auth::user()?->accessibleWebsitesQuery()->value('id');
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->checkQueued = false;
    }

    /**
     * Re-run the readiness check now. The job is ShouldBeUnique, so a second
     * press while one is running is a no-op — the button promises "queued",
     * not "done".
     */
    public function recheck(): void
    {
        $website = $this->website();
        // A free account's button is decorative on purpose: the check costs us
        // two outbound requests, and the numbers it would produce are not the
        // ones they are being shown.
        if ($website === null || ! $this->paid()) {
            return;
        }
        AuditAeoReadinessJob::dispatch((string) $website->id);
        $this->checkQueued = true;
    }

    /** Paying (or comped) — the \$1 first month counts, the free trial does not. */
    private function paid(): bool
    {
        $user = Auth::user();

        return $user !== null && app(ContentEntitlements::class)->hasPaidContentAccess($user);
    }

    private function website(): ?Website
    {
        if (! $this->websiteId) {
            return null;
        }

        return Auth::user()?->accessibleWebsitesQuery()->whereKey($this->websiteId)->first();
    }

    /**
     * One row per agent, merging "are you allowed in" with "did you come".
     *
     * Policy-only tokens (Google-Extended, Applebot-Extended) never fetch
     * anything, so their hit column reads "—", not "never seen". Shared by the
     * real and sample paths so the teaser cannot drift from the real page —
     * the whole promise of a teaser is that the paid version looks like it.
     */
    private function agentRows(?ContentAeoAudit $audit, array $hits): array
    {
        $hitsByBot = collect($hits['bots'] ?? [])->keyBy('bot');

        $rows = [];
        foreach (AiAgents::all() as $id => $agent) {
            $rows[] = [
                'id' => $id,
                'label' => $agent['label'],
                'engine' => $agent['engine'],
                'kind' => $agent['kind'],
                'crawls' => $agent['crawls'],
                'access' => (string) (($audit->robots_ai ?? [])[$id] ?? 'unknown'),
                'hits' => (int) ($hitsByBot[$id]['hits'] ?? 0),
                'pages' => (int) ($hitsByBot[$id]['pages'] ?? 0),
                'last_seen' => $hitsByBot[$id]['last_seen'] ?? null,
            ];
        }

        return $rows;
    }

    /** Where the teaser's button goes: the $1 first month on the monthly plan. */
    private function checkoutUrl(): string
    {
        return route('content.billing.checkout', array_filter([
            'interval' => 'monthly',
            'website' => $this->websiteId,
        ]));
    }

    public function render()
    {
        $website = $this->website();
        $reader = app(AeoSignalReader::class);
        $paid = $this->paid();

        if ($website === null) {
            return view('livewire.content.ai-visibility', [
                'website' => null, 'audit' => null, 'agents' => [], 'sample' => false,
                'hits' => ['instrumented' => false, 'stale' => false, 'last_report' => null, 'total' => 0, 'bots' => []],
                'referrals' => ['connected' => false, 'total' => 0, 'engines' => [], 'series' => []],
                'canInstrument' => false, 'checkoutUrl' => $this->checkoutUrl(),
            ]);
        }

        // Free signup: everything below comes from the sample set, and nothing
        // is read from this website. Their own figures are not shown behind a
        // "sample" label, and no query goes looking for them.
        if (! $paid) {
            return view('livewire.content.ai-visibility', [
                'website' => $website,
                'sample' => true,
                'audit' => AeoSampleData::audit(),
                'agents' => $this->agentRows(AeoSampleData::audit(), AeoSampleData::crawlerHits()),
                'hits' => AeoSampleData::crawlerHits(),
                'referrals' => AeoSampleData::referrals(),
                'canInstrument' => false,
                'checkoutUrl' => $this->checkoutUrl(),
            ]);
        }

        $audit = $reader->latestAudit($website);
        $hits = $reader->crawlerHits($website);

        return view('livewire.content.ai-visibility', [
            'website' => $website,
            'sample' => false,
            'audit' => $audit,
            'agents' => $this->agentRows($audit, $hits),
            'hits' => $hits,
            'referrals' => $reader->referrals($website),
            // Only the platforms we ship code for can report crawler hits;
            // everywhere else the panel must say so instead of nagging.
            'canInstrument' => ContentIntegration::query()
                ->where('website_id', $website->id)
                ->whereIn('platform', [
                    ContentIntegration::PLATFORM_WORDPRESS,
                    ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD,
                    ContentIntegration::PLATFORM_WEBHOOK,
                ])
                ->exists(),
        ]);
    }
}
