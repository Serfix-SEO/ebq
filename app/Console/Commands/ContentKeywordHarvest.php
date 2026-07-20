<?php

namespace App\Console\Commands;

use App\Jobs\Content\ClassifyPlanKeywordsJob;
use App\Jobs\Content\HarvestDomainKeywordsJob;
use App\Models\ContentPlan;
use App\Models\DomainKeywordHarvest;
use App\Services\Content\ContentSetupInsights;
use Illuminate\Console\Command;

/**
 * Monthly accumulation for the DataForSEO keyword gap: for every active content
 * plan, harvest +1,000 keywords for its client domain + top-3 competitors. Keyed
 * per DOMAIN (shared across plans) and skipped when already harvested this month
 * or exhausted. When a competitor is exhausted its slot is filled by the next
 * competitor in the authority order automatically (top-3 non-exhausted).
 *
 * See DATAFORSEO_KEYWORD_GAP_PLAN.md.
 */
class ContentKeywordHarvest extends Command
{
    protected $signature = 'ebq:content-keyword-harvest {--limit=1000 : keywords per domain per run}';

    protected $description = 'Accumulate competitor keyword rankings from DataForSEO Labs for the content gap';

    private const MAX_COMPETITORS = 3;

    public function handle(ContentSetupInsights $setup): int
    {
        $month = now()->format('Y-m');
        $limit = max(1, (int) $this->option('limit'));
        $seen = [];       // domain|country → dispatched this run
        $dispatched = 0;

        ContentPlan::query()
            ->whereNotNull('website_id')
            ->with('website.user')
            ->chunkById(200, function ($plans) use ($setup, $month, $limit, &$seen, &$dispatched) {
                foreach ($plans as $plan) {
                    $website = $plan->website;
                    if ($website === null) {
                        continue;
                    }
                    $country = strtolower(trim((string) $plan->country)) ?: 'global';
                    $sandbox = (bool) $website->user?->is_admin;

                    $domains = [];
                    $own = $this->host((string) ($website->normalized_domain ?: $website->domain));
                    if ($own !== '') {
                        $domains[$own] = true;
                    }
                    // Top-3 NON-exhausted competitors (authority order); exhausted
                    // ones are skipped so the window slides to the next competitor.
                    try {
                        $insights = $setup->competitorAuthority($website);
                    } catch (\Throwable) {
                        $insights = null;
                    }
                    $picked = 0;
                    foreach ((array) ($insights['competitors'] ?? []) as $c) {
                        if ($picked >= self::MAX_COMPETITORS) {
                            break;
                        }
                        $d = $this->host((string) ($c['domain'] ?? ''));
                        if ($d === '' || $d === $own) {
                            continue;
                        }
                        $h = DomainKeywordHarvest::query()->where('domain', $d)->where('country', $country)->first();
                        if ($h !== null && $h->exhausted) {
                            continue; // exhausted → doesn't consume a slot, try next
                        }
                        $domains[$d] = true;
                        $picked++;
                    }

                    foreach (array_keys($domains) as $domain) {
                        $key = $domain.'|'.$country;
                        if (isset($seen[$key])) {
                            continue; // shared: harvest a domain once per run
                        }
                        $seen[$key] = true;
                        $h = DomainKeywordHarvest::query()->where('domain', $domain)->where('country', $country)->first();
                        if ($h !== null && ($h->exhausted || ($h->last_run_at !== null && $h->last_run_at->format('Y-m') === $month))) {
                            continue;
                        }
                        HarvestDomainKeywordsJob::dispatch($domain, $country, $limit, $sandbox);
                        $dispatched++;
                    }

                    // Re-classify the plan's new keyword band AFTER the harvests land
                    // (incremental append; delayed so the fetch completes first).
                    ClassifyPlanKeywordsJob::dispatch($plan->id)->delay(now()->addMinutes(10));
                }
            });

        $this->info("Dispatched {$dispatched} keyword-harvest job(s).");

        return self::SUCCESS;
    }

    private function host(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        $host = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST) ?: $domain;

        return strtolower(preg_replace('/^www\./', '', (string) $host) ?: (string) $host);
    }
}
