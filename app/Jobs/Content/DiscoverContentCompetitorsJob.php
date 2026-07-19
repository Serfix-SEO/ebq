<?php

namespace App\Jobs\Content;

use App\Models\ContentPlan;
use App\Models\Website;
use App\Services\Reports\ReportEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * SERP fallback competitor discovery for the Content Autopilot wizard: when a
 * site has no backlink footprint (fresh/low-authority), its backlink report
 * finds no competitors. This job takes the content plan's TOP TARGET KEYWORDS —
 * genuine searches the site wants to rank for — and asks
 * {@see ReportEnrichmentService::discoverCompetitorsFor()} who currently ranks
 * for them. Those are the real competitors.
 *
 * Runs ASYNC so it never blocks the wizard render / re-bills on the 5s poll.
 * Result is cached under `content:serp-competitors:<websiteId>` (read by
 * {@see \App\Services\Content\ContentSetupInsights::build()}); the
 * `content:serp-comp:<websiteId>` flag (set by ensureGenerating) is cleared here
 * so the wizard stops showing the "generating" state.
 */
class DiscoverContentCompetitorsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $websiteId)
    {
        $this->onQueue('content');
    }

    public function handle(ReportEnrichmentService $enrichment): void
    {
        $flag = 'content:serp-comp:'.$this->websiteId;

        try {
            $website = Website::query()->find($this->websiteId);
            if ($website === null) {
                return;
            }
            $domain = $website->normalized_domain ?: $website->domain;
            $plan = ContentPlan::query()->where('website_id', $this->websiteId)->first();
            if ($plan === null || ! $domain) {
                return;
            }

            $keywords = $plan->topics()
                ->whereNotNull('target_keyword')->where('target_keyword', '!=', '')
                ->orderByDesc('keyword_volume')
                ->limit(20)
                ->pluck('target_keyword')
                ->map(static fn ($k) => trim((string) $k))
                ->filter()
                ->unique()
                ->take(12)
                ->map(static fn ($k) => ['keyword' => $k])
                ->values()
                ->all();
            if ($keywords === []) {
                return;
            }

            // Non-admin sites bill real SERP spend (same policy as the report);
            // admins sandbox. billedUserId scopes the metered usage.
            $billedUserId = $website->user?->is_admin ? null : $website->user_id;

            $result = $enrichment->discoverCompetitorsFor((string) $domain, $keywords, $billedUserId);
            $competitors = array_values(array_filter((array) ($result['competitors'] ?? []), 'is_array'));

            if ($competitors !== []) {
                Cache::put('content:serp-competitors:'.$this->websiteId, $competitors, now()->addDays(30));
            }
        } catch (\Throwable) {
            // Fail soft — the step falls back to its empty state.
        } finally {
            // Let the wizard re-read (build() picks up the new competitors) and
            // stop polling.
            Cache::forget('content:setup-insights:v1:'.$this->websiteId);
            Cache::forget($flag);
        }
    }
}
