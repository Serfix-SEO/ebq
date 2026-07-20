<?php

namespace App\Jobs\Content;

use App\Models\DomainKeywordHarvest;
use App\Models\KeywordMetric;
use App\Services\DataForSeoBacklinkClient;
use App\Services\Reports\DataForSeoSpendMeter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Harvest ONE page (≤1,000) of a domain's ranked keywords from DataForSEO Labs
 * and fold them into the shared assets: keyword facts → keyword_metrics, this
 * domain's rankings → domain_keyword_rankings. Advances the per-domain volume
 * cursor so the NEXT run pulls only lower-volume (new) keywords — cheap, dupe-free
 * month-over-month accumulation. Keyed per DOMAIN (shared across plans/users).
 *
 * See DATAFORSEO_KEYWORD_GAP_PLAN.md.
 */
class HarvestDomainKeywordsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const SOURCE = 'dfs_labs';

    private const TTL_DAYS = 30;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public string $domain,
        public string $country = 'global',
        public int $limit = 1000,
        public bool $sandbox = false,
    ) {
        $this->onQueue('content');
        $this->onConnection('redis-long');
    }

    public function handle(DataForSeoBacklinkClient $dfs, DataForSeoSpendMeter $spend): void
    {
        $domain = $this->normalizeHost($this->domain);
        if ($domain === '' || ! $dfs->isConfigured()) {
            return;
        }

        $harvest = DomainKeywordHarvest::query()->firstOrNew([
            'domain' => $domain, 'country' => $this->country,
        ]);
        if ($harvest->exhausted) {
            return; // no more keywords for this domain
        }
        if (! $this->sandbox && $spend->exhausted()) {
            return; // monthly breaker tripped
        }

        [$locationCode, $language] = $this->geoFor($this->country);

        $dfs->resetCost();
        $rows = $dfs->useSandbox($this->sandbox)->rankedKeywords($domain, [
            'limit' => $this->limit,
            'volume_cursor' => $harvest->volume_cursor,
            'location_code' => $locationCode,
            'language_name' => $language,
        ]);
        $dfs->useSandbox(false);
        if (! $this->sandbox) {
            $spend->add($dfs->totalCost());
        }

        // An EMPTY response is ambiguous: it can mean "no more keywords" OR a
        // transient API blip (rate limit / hiccup). Never mark the domain
        // exhausted on it — doing so permanently stopped harvesting a live
        // domain after one bad response (broke prod 2026-07-20). Record the run
        // only; the next cycle retries. `exhausted` is set solely on the success
        // path below, where we actually received rows but fewer than the limit.
        if ($rows === [] || $this->sandbox) {
            $harvest->fill(['last_run_at' => now()])->save();

            return; // sandbox mock data is never persisted to the shared asset
        }

        $this->persist($domain, $rows);

        $minVolume = min(array_map(static fn ($r) => (int) ($r['search_volume'] ?? 0), $rows));
        $harvest->fill([
            'volume_cursor' => $minVolume > 0 ? $minVolume : $harvest->volume_cursor,
            'keywords_fetched' => (int) $harvest->keywords_fetched + count($rows),
            'exhausted' => count($rows) < $this->limit,
            'last_run_at' => now(),
        ])->save();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function persist(string $domain, array $rows): void
    {
        $now = now();
        $country = $this->country;

        // ── keyword_metrics: insert only keywords we don't already have (shared
        // cache; first write wins, volumes are ~monthly-stable).
        $byHash = [];
        foreach ($rows as $r) {
            $byHash[KeywordMetric::hashKeyword($r['keyword'])] = $r;
        }
        $existing = KeywordMetric::query()
            ->where('country', $country)->where('data_source', self::SOURCE)
            ->whereIn('keyword_hash', array_keys($byHash))
            ->pluck('keyword_hash')->all();
        $missing = array_diff(array_keys($byHash), $existing);
        $kmInsert = [];
        foreach ($missing as $hash) {
            $r = $byHash[$hash];
            $kmInsert[] = [
                'id' => (string) Str::ulid(),
                'keyword' => $r['keyword'],
                'keyword_hash' => $hash,
                'country' => $country,
                'data_source' => self::SOURCE,
                'search_volume' => $r['search_volume'],
                'cpc' => $r['cpc'],
                'competition' => $r['competition'],
                'keyword_difficulty' => $r['keyword_difficulty'],
                'search_intent' => $r['search_intent'],
                'fetched_at' => $now,
                'expires_at' => $now->copy()->addDays(self::TTL_DAYS),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($kmInsert, 500) as $chunk) {
            KeywordMetric::query()->insert($chunk);
        }

        // ── domain_keyword_rankings: upsert this domain's ranking per keyword.
        $dkr = [];
        foreach ($rows as $r) {
            $dkr[] = [
                'domain' => $domain,
                'keyword_hash' => KeywordMetric::hashKeyword($r['keyword']),
                'keyword' => $r['keyword'],
                'country' => $country,
                'rank_absolute' => $r['rank_absolute'],
                'se_type' => $r['se_type'],
                'page_url' => $r['page_url'],
                'etv' => $r['etv'],
                'search_volume' => $r['search_volume'],
                'previous_rank' => $r['previous_rank'],
                'is_new' => $r['is_new'],
                'is_up' => $r['is_up'],
                'is_down' => $r['is_down'],
                'is_lost' => false,
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($dkr, 500) as $chunk) {
            DB::table('domain_keyword_rankings')->upsert(
                $chunk,
                ['domain', 'keyword_hash', 'country'],
                ['keyword', 'rank_absolute', 'se_type', 'page_url', 'etv', 'search_volume',
                    'previous_rank', 'is_new', 'is_up', 'is_down', 'is_lost', 'fetched_at', 'updated_at'],
            );
        }
    }

    /** country code → [location_code, language_name] for DataForSEO Labs. */
    private function geoFor(string $country): array
    {
        return match (strtolower(trim($country))) {
            'gb', 'uk' => [2826, 'English'],
            'in' => [2356, 'English'],
            'ca' => [2124, 'English'],
            'au' => [2036, 'English'],
            'de' => [2276, 'German'],
            'fr' => [2250, 'French'],
            'es' => [2724, 'Spanish'],
            'ae' => [2784, 'English'],
            'sa' => [2682, 'Arabic'],
            default => [2840, 'English'], // US / global
        };
    }

    private function normalizeHost(string $domain): string
    {
        $domain = trim($domain);
        if ($domain === '') {
            return '';
        }
        $host = parse_url(str_contains($domain, '://') ? $domain : 'https://'.$domain, PHP_URL_HOST) ?: $domain;

        return strtolower(preg_replace('/^www\./', '', (string) $host) ?: (string) $host);
    }
}
