<?php

namespace App\Services\Demo;

use App\Enums\BacklinkType;
use App\Models\AiInsight;
use App\Models\AnalyticsData;
use App\Models\Backlink;
use App\Models\BrandVoiceProfile;
use App\Models\ClientActivity;
use App\Models\CustomPageAudit;
use App\Models\KeywordMetric;
use App\Models\PageAuditReport;
use App\Models\PageIndexingStatus;
use App\Models\RankTrackingKeyword;
use App\Models\RedirectSuggestion;
use App\Models\ReportBranding;
use App\Models\User;
use App\Models\Website;
use App\Models\WriterProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generates (and removes) a complete demo dataset for sales demos and
 * marketing capture. Everything hangs off one website (domain ebq.io)
 * owned by user id 1, and spans every SEO surface the dashboard renders.
 *
 * Identity contract: the demo website is the row where
 *   domain = 'ebq.io' AND user_id = 1
 * `clear()` only ever touches that website (and the global keyword_metrics
 * rows tagged data_source = 'ebq_demo'), so it can never delete real
 * customer data.
 *
 * Re-running seed() is safe: it calls clear() first, then rebuilds.
 */
class DemoDataSeeder
{
    public const DEMO_DOMAIN = 'ebq.io';
    public const DEMO_USER_ID = 1;
    public const DEMO_KW_SOURCE = 'ebq_demo';

    private const TARGET_DOMAIN = 'ebq.io';

    /** Pages on the demo site (an SEO-tool product site). */
    private const PAGES = [
        '/',
        '/features',
        '/pricing',
        '/features/rank-tracking',
        '/features/keyword-research',
        '/features/site-audit',
        '/features/backlinks',
        '/blog/seo-audit-checklist',
        '/blog/keyword-research-guide',
        '/guide',
    ];

    /** Page that cannibalization queries also rank on (competes with the primary). */
    private const CANNIBAL_SECOND_PAGE = '/';

    /**
     * Demo queries with an explicit role so the insight engines light up.
     * role: normal | cannibal | striking | decay | quickwin
     * page: primary page path; cannibal queries also seed CANNIBAL_SECOND_PAGE.
     *
     * All keywords are SEO-tool / SaaS terms — ebq.io is a connected SEO suite.
     *
     * @var list<array{q:string, page:string, role:string, vol:int, comp:float, cpc:float}>
     */
    private const QUERIES = [
        ['q' => 'ebq seo',                       'page' => '/',                          'role' => 'normal',   'vol' => 1300, 'comp' => 0.42, 'cpc' => 6.50],
        ['q' => 'ebq seo plugin',                'page' => '/features',                  'role' => 'normal',   'vol' => 880,  'comp' => 0.38, 'cpc' => 5.80],
        ['q' => 'connected seo suite',           'page' => '/features',                  'role' => 'normal',   'vol' => 590,  'comp' => 0.33, 'cpc' => 7.20],
        ['q' => 'wordpress seo dashboard',       'page' => '/',                          'role' => 'normal',   'vol' => 2400, 'comp' => 0.55, 'cpc' => 8.10],
        ['q' => 'seo reporting tool',            'page' => '/pricing',                   'role' => 'normal',   'vol' => 3600, 'comp' => 0.61, 'cpc' => 9.40],
        ['q' => 'rank tracking software',        'page' => '/features/rank-tracking',    'role' => 'normal',   'vol' => 4800, 'comp' => 0.58, 'cpc' => 11.20],
        ['q' => 'keyword research tool',         'page' => '/features/keyword-research', 'role' => 'normal',   'vol' => 9900, 'comp' => 0.65, 'cpc' => 7.70],
        ['q' => 'backlink checker',              'page' => '/features/backlinks',        'role' => 'normal',   'vol' => 6600, 'comp' => 0.49, 'cpc' => 6.55],
        ['q' => 'seo audit tool',                'page' => '/features/site-audit',       'role' => 'normal',   'vol' => 8100, 'comp' => 0.52, 'cpc' => 8.40],

        // Cannibalization: same query splits clicks between a blog post and the homepage.
        ['q' => 'seo audit checklist',           'page' => '/blog/seo-audit-checklist',  'role' => 'cannibal', 'vol' => 5400, 'comp' => 0.31, 'cpc' => 3.50],
        ['q' => 'keyword research guide',        'page' => '/blog/keyword-research-guide','role' => 'cannibal', 'vol' => 3300, 'comp' => 0.29, 'cpc' => 4.10],

        // Striking distance: position 11-20 with strong impressions.
        ['q' => 'technical seo audit',           'page' => '/features/site-audit',       'role' => 'striking', 'vol' => 2900, 'comp' => 0.40, 'cpc' => 9.10],
        ['q' => 'serp position tracker',         'page' => '/features/rank-tracking',    'role' => 'striking', 'vol' => 1700, 'comp' => 0.35, 'cpc' => 8.20],
        ['q' => 'content optimization tool',     'page' => '/features/keyword-research', 'role' => 'striking', 'vol' => 2200, 'comp' => 0.44, 'cpc' => 7.50],

        // Content decay: was strong, declining the last 28 days.
        ['q' => 'google search console alternative','page' => '/features',               'role' => 'decay',    'vol' => 1900, 'comp' => 0.38, 'cpc' => 6.40],
        ['q' => 'best seo software',             'page' => '/pricing',                   'role' => 'decay',    'vol' => 14000,'comp' => 0.70, 'cpc' => 12.00],

        // Quick wins: high volume, low competition, ranking deep (>10).
        ['q' => 'free keyword research tool',    'page' => '/features/keyword-research', 'role' => 'quickwin', 'vol' => 8800, 'comp' => 0.33, 'cpc' => 5.20],
        ['q' => 'ai content writer for seo',     'page' => '/blog/keyword-research-guide','role' => 'quickwin', 'vol' => 2600, 'comp' => 0.31, 'cpc' => 6.10],
        ['q' => 'local rank tracker',            'page' => '/features/rank-tracking',    'role' => 'quickwin', 'vol' => 1400, 'comp' => 0.28, 'cpc' => 7.80],
    ];

    private const COUNTRIES = ['USA', 'GBR'];
    private const DEVICES = ['DESKTOP', 'MOBILE'];

    public function seed(): Website
    {
        $user = User::find(self::DEMO_USER_ID);
        if (! $user) {
            throw new RuntimeException(
                'User id '.self::DEMO_USER_ID.' does not exist. Create that user before seeding demo data.'
            );
        }

        return DB::transaction(function (): Website {
            $this->clear();

            // Skip the model `created` event so we don't dispatch the 365-day
            // GSC/GA backfill jobs (they'd no-op without a Google account but
            // would clutter the queue).
            $website = Website::withoutEvents(fn () => Website::create([
                'user_id' => self::DEMO_USER_ID,
                'domain' => self::DEMO_DOMAIN,
                'gsc_site_url' => 'sc-domain:'.self::DEMO_DOMAIN,
                'ga_property_id' => 'properties/000000001',
                'feature_flags' => null,
                'gsc_keyword_lookback_days' => 28,
                'report_recipients' => [self::DEMO_USER_ID],
                'last_analytics_sync_at' => now(),
                'last_search_console_sync_at' => now(),
            ]));

            // Redundant with websites.user_id but keeps the team UI consistent.
            DB::table('website_user')->updateOrInsert(
                ['website_id' => $website->id, 'user_id' => self::DEMO_USER_ID],
                ['role' => 'owner', 'permissions' => null, 'created_at' => now(), 'updated_at' => now()],
            );

            $this->seedSearchConsole($website->id);
            $this->seedAnalytics($website->id);
            $this->seedKeywordMetrics();
            $this->seedRankTracking($website->id);
            $this->seedIndexingStatuses($website->id);
            $this->seedBacklinks($website->id);
            $this->seedPageAudits($website->id);
            $this->seedWriterProjects($website->id);
            $this->seedBrandVoice($website->id);
            $this->seedClientActivities($website->id);
            $this->seedRedirectSuggestions($website->id);
            $this->seedAiInsights($website->id);
            $this->seedReportBranding($website->id);

            return $website;
        });
    }

    public function clear(): void
    {
        $demo = Website::where('domain', self::DEMO_DOMAIN)
            ->where('user_id', self::DEMO_USER_ID)
            ->first();

        // Global, marker-scoped table — always safe to clear by source tag.
        KeywordMetric::where('data_source', self::DEMO_KW_SOURCE)->delete();

        if (! $demo) {
            return;
        }

        // client_activities.website_id is nullOnDelete, so deleting the
        // website would orphan these. Remove them explicitly first.
        ClientActivity::where('website_id', $demo->id)->delete();
        ReportBranding::where('website_id', $demo->id)->delete();

        // Cascades: search_console_data, analytics_data, rank_tracking_keywords
        // (+snapshots), page_indexing_statuses, backlinks, page_audit_reports
        // (+custom_page_audits), writer_projects, brand_voice_profiles,
        // redirect_suggestions, ai_insights, website_user pivot.
        $demo->delete();
    }

    /* ───────────────────────── generators ───────────────────────── */

    private function seedSearchConsole(int $websiteId): void
    {
        $end = Carbon::yesterday(config('app.timezone'));
        $days = 90;
        $rows = [];
        $now = now()->toDateTimeString();

        foreach (self::QUERIES as $entry) {
            $pages = [$entry['page']];
            // Cannibalization queries also rank on a competing page.
            if ($entry['role'] === 'cannibal') {
                $pages[] = self::CANNIBAL_SECOND_PAGE;
            }

            foreach ($pages as $pi => $page) {
                foreach (self::COUNTRIES as $country) {
                    // GB gets a thinner slice; US is the bulk.
                    if ($country === 'GBR' && $entry['role'] !== 'normal') {
                        continue;
                    }
                    foreach (self::DEVICES as $device) {
                        for ($d = 0; $d < $days; $d++) {
                            $date = $end->copy()->subDays($days - 1 - $d);
                            [$clicks, $impr, $pos] = $this->scdPoint($entry, $pi, $country, $device, $d, $days);
                            if ($impr <= 0) {
                                continue;
                            }
                            $ctr = $impr > 0 ? round($clicks / $impr, 4) : 0.0;
                            $rows[] = [
                                'website_id' => $websiteId,
                                'date' => $date->toDateString(),
                                'query' => $entry['q'],
                                'page' => 'https://'.self::DEMO_DOMAIN.$page,
                                'clicks' => $clicks,
                                'impressions' => $impr,
                                'position' => $pos,
                                'country' => $country,
                                'device' => $device,
                                'ctr' => $ctr,
                                'keyword_id' => null,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                            if (count($rows) >= 500) {
                                DB::table('search_console_data')->insert($rows);
                                $rows = [];
                            }
                        }
                    }
                }
            }
        }

        if ($rows !== []) {
            DB::table('search_console_data')->insert($rows);
        }
    }

    /**
     * Deterministic daily metrics for one query/page/country/device.
     *
     * @param  array{q:string, page:string, role:string, vol:int, comp:float, cpc:float}  $entry
     * @return array{0:int,1:int,2:float}  [clicks, impressions, position]
     */
    private function scdPoint(array $entry, int $pageIndex, string $country, string $device, int $dayIndex, int $days): array
    {
        $seed = $entry['q'].'|'.$pageIndex.'|'.$country.'|'.$device;
        $base = $this->rand($seed, 40, 220); // base daily impressions
        if ($country === 'GBR') {
            $base = (int) round($base * 0.35);
        }
        if ($device === 'MOBILE') {
            $base = (int) round($base * 1.2);
        }

        // Linear growth across the window + weekly seasonality + noise.
        $progress = $dayIndex / max(1, $days - 1);
        $growth = 1 + 0.6 * $progress;
        $dow = $dayIndex % 7;
        $weekend = ($dow === 5 || $dow === 6) ? 0.75 : 1.0;
        $noise = 0.85 + ($this->rand($seed.'|'.$dayIndex, 0, 30) / 100); // 0.85–1.15

        $impr = (int) round($base * $growth * $weekend * $noise);

        // Position by role.
        $pos = match ($entry['role']) {
            'striking' => 11 + ($this->rand($seed, 0, 8)),                 // 11–19
            'quickwin' => 13 + ($this->rand($seed, 0, 10)),               // 13–23
            'cannibal' => $pageIndex === 0 ? 6 + $this->rand($seed, 0, 3) : 8 + $this->rand($seed, 0, 4),
            'decay' => 4 + $this->rand($seed, 0, 2),
            default => 2 + $this->rand($seed, 0, 5),                        // 2–7
        };
        $posF = (float) $pos;

        // Healthy queries improve over time; decay queries worsen in the
        // last 28 days; others drift slightly.
        if ($entry['role'] === 'normal' || $entry['role'] === 'cannibal') {
            $posF = max(1.0, $posF - 1.5 * $progress);
        } elseif ($entry['role'] === 'decay') {
            $daysFromEnd = ($days - 1) - $dayIndex;
            if ($daysFromEnd < 28) {
                $posF += (28 - $daysFromEnd) * 0.12; // sink as we approach today
            }
        }
        $posF = round($posF + ($this->rand($seed.'|p|'.$dayIndex, -5, 5) / 10), 1);
        $posF = max(1.0, $posF);

        // Clicks via a CTR curve that decays with position; decay queries
        // also lose CTR in the recent window.
        $ctr = match (true) {
            $posF <= 3 => 0.28,
            $posF <= 7 => 0.12,
            $posF <= 10 => 0.06,
            $posF <= 20 => 0.02,
            default => 0.008,
        };
        if ($entry['role'] === 'decay') {
            $daysFromEnd = ($days - 1) - $dayIndex;
            if ($daysFromEnd < 28) {
                $ctr *= 0.5;
            }
        }
        $clicks = (int) round($impr * $ctr);

        return [$clicks, $impr, $posF];
    }

    private function seedAnalytics(int $websiteId): void
    {
        $end = Carbon::yesterday(config('app.timezone'));
        $days = 90;
        $sources = [
            'organic' => 600,
            'direct' => 180,
            'referral' => 90,
            'social' => 60,
        ];
        $rows = [];
        $now = now()->toDateTimeString();

        for ($d = 0; $d < $days; $d++) {
            $date = $end->copy()->subDays($days - 1 - $d);
            $progress = $d / max(1, $days - 1);
            $dow = $d % 7;
            $weekend = ($dow === 5 || $dow === 6) ? 0.7 : 1.0;
            foreach ($sources as $source => $baseUsers) {
                $noise = 0.85 + ($this->rand($source.$d, 0, 30) / 100);
                $users = (int) round($baseUsers * (1 + 0.5 * $progress) * $weekend * $noise);
                $sessions = (int) round($users * (1.1 + $this->rand($source.$d.'s', 0, 30) / 100));
                $rows[] = [
                    'website_id' => $websiteId,
                    'date' => $date->toDateString(),
                    'users' => $users,
                    'sessions' => $sessions,
                    'source' => $source,
                    'bounce_rate' => round(35 + $this->rand($source.$d.'b', 0, 35), 2),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('analytics_data')->insert($chunk);
        }
    }

    private function seedKeywordMetrics(): void
    {
        $now = now();
        foreach (self::QUERIES as $entry) {
            KeywordMetric::create([
                'keyword' => $entry['q'],
                'keyword_hash' => hash('sha256', mb_strtolower($entry['q']).'|global'),
                'country' => 'global',
                'data_source' => self::DEMO_KW_SOURCE,
                'search_volume' => $entry['vol'],
                'cpc' => $entry['cpc'],
                'currency' => 'USD',
                'competition' => $entry['comp'],
                'trend_12m' => $this->trend12m($entry['q'], $entry['vol']),
                'fetched_at' => $now,
                'expires_at' => $now->copy()->addDays(30),
            ]);
        }
    }

    /**
     * @return list<array{month:string, volume:int}>
     */
    private function trend12m(string $key, int $vol): array
    {
        $out = [];
        $month = Carbon::now()->startOfMonth();
        for ($i = 11; $i >= 0; $i--) {
            $m = $month->copy()->subMonths($i);
            $factor = 0.7 + ($this->rand($key.$i, 0, 60) / 100); // 0.7–1.3
            $out[] = ['month' => $m->format('Y-m'), 'volume' => (int) round($vol * $factor)];
        }
        return $out;
    }

    private function seedRankTracking(int $websiteId): void
    {
        $now = Carbon::now();
        // Track the first 15 demo queries.
        $tracked = array_slice(self::QUERIES, 0, 15);

        foreach ($tracked as $entry) {
            $current = match ($entry['role']) {
                'striking' => $this->rand($entry['q'].'cur', 11, 18),
                'quickwin' => $this->rand($entry['q'].'cur', 13, 22),
                'decay' => $this->rand($entry['q'].'cur', 6, 12),
                'cannibal' => $this->rand($entry['q'].'cur', 5, 9),
                default => $this->rand($entry['q'].'cur', 1, 6),
            };
            $initial = $current + $this->rand($entry['q'].'init', 3, 14);
            $best = max(1, min($current, $initial) - $this->rand($entry['q'].'best', 0, 3));
            $device = self::DEVICES[$this->rand($entry['q'].'dev', 0, 1)];
            $country = ['us', 'gb'][$this->rand($entry['q'].'cty', 0, 1)];

            $kw = RankTrackingKeyword::create([
                'website_id' => $websiteId,
                'user_id' => self::DEMO_USER_ID,
                'keyword' => $entry['q'],
                'keyword_hash' => hash('sha256', mb_strtolower($entry['q'])),
                'target_domain' => self::TARGET_DOMAIN,
                'target_url' => 'https://'.self::DEMO_DOMAIN.$entry['page'],
                'search_engine' => 'google',
                'search_type' => 'organic',
                'country' => $country,
                'language' => 'en',
                'location' => null,
                'device' => strtolower($device),
                'depth' => 100,
                'autocorrect' => true,
                'safe_search' => false,
                'competitors' => ['competitor-a.com', 'competitor-b.com'],
                'tags' => $entry['role'] === 'normal' ? ['brand'] : ['blog'],
                'check_interval_hours' => 12,
                'is_active' => true,
                'last_checked_at' => $now->copy()->subHours($this->rand($entry['q'].'lc', 1, 11)),
                'next_check_at' => $now->copy()->addHours($this->rand($entry['q'].'nc', 1, 11)),
                'last_status' => 'ok',
                'current_position' => $current,
                'best_position' => $best,
                'initial_position' => $initial,
                'position_change' => $initial - $current,
                'current_url' => 'https://'.self::DEMO_DOMAIN.$entry['page'],
            ]);

            // 60-day snapshot history converging on current_position.
            $snapDays = 60;
            $snapRows = [];
            $nowStr = now()->toDateTimeString();
            for ($d = 0; $d < $snapDays; $d++) {
                $progress = $d / max(1, $snapDays - 1);
                $pos = (int) round($initial + ($current - $initial) * $progress
                    + $this->rand($entry['q'].'snp'.$d, -2, 2));
                $pos = max(1, $pos);
                $checkedAt = $now->copy()->subDays($snapDays - 1 - $d);
                $snapRows[] = [
                    'rank_tracking_keyword_id' => $kw->id,
                    'checked_at' => $checkedAt->toDateTimeString(),
                    'position' => $pos,
                    'url' => 'https://'.self::DEMO_DOMAIN.$entry['page'],
                    'title' => Str::title(str_replace('-', ' ', trim($entry['page'], '/'))) ?: 'EBQ',
                    'snippet' => null,
                    'total_results' => $this->rand($entry['q'].'tr'.$d, 1_000_000, 90_000_000),
                    'search_time' => round($this->rand($entry['q'].'st'.$d, 20, 90) / 100, 3),
                    // Raw query-builder insert bypasses Eloquent casts, so JSON
                    // columns must be encoded by hand.
                    'serp_features' => json_encode($d % 5 === 0 ? ['featured_snippet', 'people_also_ask'] : ['people_also_ask']),
                    'competitor_positions' => null,
                    'top_results' => null,
                    'related_searches' => null,
                    'people_also_ask' => null,
                    'status' => 'ok',
                    'error' => null,
                    'forced' => false,
                    'created_at' => $nowStr,
                    'updated_at' => $nowStr,
                ];
            }
            foreach (array_chunk($snapRows, 500) as $chunk) {
                DB::table('rank_tracking_snapshots')->insert($chunk);
            }
        }
    }

    private function seedIndexingStatuses(int $websiteId): void
    {
        $now = Carbon::now();
        foreach (self::PAGES as $i => $page) {
            // 2 failures on pages that DO get traffic (blog posts) so the
            // "indexing fails with traffic" insight fires.
            $fail = in_array($page, ['/blog/seo-audit-checklist', '/blog/keyword-research-guide'], true);
            PageIndexingStatus::create([
                'website_id' => $websiteId,
                'page' => 'https://'.self::DEMO_DOMAIN.$page,
                'google_verdict' => $fail ? 'NEUTRAL' : 'PASS',
                'google_coverage_state' => $fail ? 'Crawled - currently not indexed' : 'Submitted and indexed',
                'google_indexing_state' => $fail ? 'INDEXING_ALLOWED' : 'INDEXING_ALLOWED',
                'google_last_crawl_at' => $now->copy()->subDays($this->rand($page.'crawl', 1, 20)),
                'last_google_status_checked_at' => $now->copy()->subDays($this->rand($page.'chk', 0, 3)),
                'google_status_payload' => ['demo' => true],
            ]);
        }
    }

    private function seedBacklinks(int $websiteId): void
    {
        $types = BacklinkType::cases();
        $referrers = [
            'searchengineland.com', 'moz.com', 'ahrefs.com', 'backlinko.com',
            'wpbeginner.com', 'kinsta.com', 'cloudways.com', 'reddit.com',
            'producthunt.com', 'medium.com', 'dev.to', 'hackernews.com',
        ];
        $anchors = ['EBQ SEO', 'connected SEO suite', 'this SEO tool', 'keyword research tool', 'rank tracking software', 'backlink checker', 'EBQ', 'SEO dashboard'];

        for ($i = 0; $i < 40; $i++) {
            $ref = $referrers[$i % count($referrers)];
            $da = $this->rand('da'.$i, 20, 92);
            $spam = $this->rand('spam'.$i, 0, 18);
            $status = ['active', 'active', 'active', 'broken', 'redirect'][$this->rand('st'.$i, 0, 4)];
            Backlink::create([
                'website_id' => $websiteId,
                'tracked_date' => Carbon::now()->subDays($this->rand('bd'.$i, 0, 120))->toDateString(),
                'referring_page_url' => 'https://'.$ref.'/post/'.($i + 1),
                'target_page_url' => 'https://'.self::DEMO_DOMAIN.self::PAGES[$i % count(self::PAGES)],
                'domain_authority' => $da,
                'spam_score' => $spam,
                'anchor_text' => $anchors[$i % count($anchors)],
                'type' => $types[$i % count($types)],
                'is_dofollow' => $this->rand('df'.$i, 0, 10) > 2,
                'audit_status' => $status,
                'audit_checked_at' => Carbon::now()->subDays($this->rand('ac'.$i, 0, 7)),
                'audit_result' => ['http_status' => $status === 'broken' ? 404 : 200],
            ]);
        }
    }

    private function seedPageAudits(int $websiteId): void
    {
        $auditPages = array_slice(self::PAGES, 0, 8);
        foreach ($auditPages as $i => $page) {
            $score = $this->rand('score'.$page, 58, 96);
            $report = PageAuditReport::create([
                'website_id' => $websiteId,
                'page' => 'https://'.self::DEMO_DOMAIN.$page,
                'page_hash' => hash('sha256', 'https://'.self::DEMO_DOMAIN.$page),
                'primary_keyword' => self::QUERIES[$i]['q'] ?? null,
                'primary_keyword_source' => 'gsc',
                'status' => 'completed',
                'audited_at' => Carbon::now()->subDays($this->rand('au'.$page, 0, 14)),
                'http_status' => 200,
                'response_time_ms' => $this->rand('rt'.$page, 180, 920),
                'page_size_bytes' => $this->rand('ps'.$page, 240_000, 2_400_000),
                'result' => $this->auditResult($page, $score),
            ]);

            // Link a couple as user-triggered audits.
            if ($i < 3) {
                CustomPageAudit::create([
                    'website_id' => $websiteId,
                    'user_id' => self::DEMO_USER_ID,
                    'source' => 'custom',
                    'page_url' => 'https://'.self::DEMO_DOMAIN.$page,
                    'page_url_hash' => hash('sha256', 'https://'.self::DEMO_DOMAIN.$page),
                    'target_keyword' => self::QUERIES[$i]['q'] ?? null,
                    'serp_sample_gl' => 'us',
                    'page_audit_report_id' => $report->id,
                    'status' => 'completed',
                    'queued_at' => $report->audited_at,
                    'started_at' => $report->audited_at,
                    'finished_at' => $report->audited_at,
                    'attempts' => 1,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function auditResult(string $page, int $score): array
    {
        return [
            'seo_score' => $score,
            'core_web_vitals' => [
                'lcp_ms' => $this->rand('lcp'.$page, 1200, 3800),
                'cls' => round($this->rand('cls'.$page, 1, 24) / 100, 2),
                'inp_ms' => $this->rand('inp'.$page, 90, 420),
                'tbt_ms' => $this->rand('tbt'.$page, 50, 600),
                'fcp_ms' => $this->rand('fcp'.$page, 700, 2400),
                'ttfb_ms' => $this->rand('ttfb'.$page, 120, 800),
            ],
            'content' => [
                'word_count' => $this->rand('wc'.$page, 420, 2600),
                'reading_grade' => round($this->rand('rg'.$page, 60, 110) / 10, 1),
                'top_keywords' => ['seo audit', 'keyword research', 'rank tracking'],
            ],
            'issues' => [
                ['severity' => 'warning', 'label' => 'Meta description slightly long', 'detail' => '162 characters (sweet spot 120–158).'],
                ['severity' => 'info', 'label' => 'Add one more internal link', 'detail' => 'This page links out to 2 internal pages.'],
                ['severity' => $score < 70 ? 'error' : 'info', 'label' => 'Largest Contentful Paint', 'detail' => 'Optimise the hero image.'],
            ],
        ];
    }

    private function seedWriterProjects(int $websiteId): void
    {
        $specs = [
            ['title' => 'The Complete SEO Audit Checklist for 2026', 'kw' => 'seo audit checklist', 'step' => 'completed'],
            ['title' => 'How to Do Keyword Research That Actually Ranks', 'kw' => 'keyword research guide', 'step' => 'brief'],
            ['title' => 'Rank Tracking 101: The Metrics That Matter', 'kw' => 'serp position tracker', 'step' => 'topic'],
        ];
        foreach ($specs as $spec) {
            WriterProject::create([
                'external_id' => (string) Str::uuid(),
                'website_id' => $websiteId,
                'user_id' => self::DEMO_USER_ID,
                'title' => $spec['title'],
                'focus_keyword' => $spec['kw'],
                'additional_keywords' => ['technical seo', 'on-page seo'],
                'lsi_keywords' => ['search intent', 'serp features', 'core web vitals'],
                'country' => 'us',
                'language' => 'en',
                'tone' => 'professional',
                'audience' => 'marketers and SEOs',
                'step' => $spec['step'],
                'brief' => $spec['step'] !== 'topic' ? ['outline' => ['Intro', 'What to check', 'Step by step', 'FAQ']] : null,
                'generated_html' => $spec['step'] === 'completed'
                    ? '<h2>Introduction</h2><p>A regular SEO audit keeps your site healthy and surfaces quick wins before rankings slip...</p>'
                    : null,
                'credits_used' => $spec['step'] === 'completed' ? 38 : 0,
            ]);
        }
    }

    private function seedBrandVoice(int $websiteId): void
    {
        BrandVoiceProfile::create([
            'website_id' => $websiteId,
            'samples_count' => 3,
            'fingerprint' => [
                'summary' => 'Clear, practical, expert SEO voice that favours short actionable sentences and data-backed claims.',
                'tone' => 'Professional',
                'person' => 'Second person',
                'avg_sentence_words' => 16,
                'formality_score' => 55,
                'vocabulary_band' => 'Plain English',
            ],
            'sample_excerpt' => 'Run an audit before every content refresh. It surfaces the quick wins that move rankings without new pages.',
            'last_extracted_at' => Carbon::now()->subDays(2),
        ]);
    }

    private function seedClientActivities(int $websiteId): void
    {
        $providers = [
            'keywords_everywhere' => ['rank_check', 1],
            'serp_api' => ['rank_check', 1],
            'mistral' => ['ai_write', 1],
            'audit' => ['page_audit', 1],
        ];
        $monthStart = Carbon::now()->startOfMonth();
        for ($i = 0; $i < 60; $i++) {
            $provider = array_keys($providers)[$i % count($providers)];
            [$type, $unit] = $providers[$provider];
            ClientActivity::create([
                'user_id' => self::DEMO_USER_ID,
                'actor_user_id' => self::DEMO_USER_ID,
                'website_id' => $websiteId,
                'type' => $type,
                'provider' => $provider,
                'meta' => ['demo' => true],
                'units_consumed' => $unit * $this->rand('units'.$i, 1, 40),
            ])->forceFill([
                'created_at' => $monthStart->copy()->addDays($this->rand('cad'.$i, 0, 27))->addHours($this->rand('cah'.$i, 0, 23)),
            ])->save();
        }
    }

    private function seedRedirectSuggestions(int $websiteId): void
    {
        $pairs = [
            ['/old-seo-audit-post', '/blog/seo-audit-checklist'],
            ['/keyword-tool-2024', '/features/keyword-research'],
            ['/rank-tracker', '/features/rank-tracking'],
            ['/backlinks-checker', '/features/backlinks'],
            ['/seo-guide', '/blog/keyword-research-guide'],
        ];
        foreach ($pairs as $i => [$from, $to]) {
            RedirectSuggestion::create([
                'website_id' => $websiteId,
                'source_path' => $from,
                'source_path_hash' => hash('sha256', $from),
                'suggested_destination' => $to,
                'confidence' => $this->rand('conf'.$i, 62, 96),
                'status' => 'pending',
                'rationale' => 'Slug and title closely match the destination page.',
                'hits_30d' => $this->rand('hits'.$i, 12, 340),
                'first_seen_at' => Carbon::now()->subDays(30),
                'last_seen_at' => Carbon::now()->subDays($this->rand('ls'.$i, 0, 3)),
            ]);
        }
    }

    private function seedAiInsights(int $websiteId): void
    {
        $pages = array_slice(self::PAGES, 3, 6);
        foreach ($pages as $i => $page) {
            AiInsight::create([
                'website_id' => $websiteId,
                'date' => Carbon::now()->subDays($this->rand('aid'.$page, 0, 14))->toDateString(),
                'page' => 'https://'.self::DEMO_DOMAIN.$page,
                'payload' => [
                    'headline' => 'Add an FAQ section targeting "'.(self::QUERIES[$i]['q'] ?? 'seo audit').'"',
                    'impact' => 'medium',
                    'detail' => 'This page ranks on the fold but misses the PAA box.',
                ],
            ]);
        }
    }

    private function seedReportBranding(int $websiteId): void
    {
        ReportBranding::create([
            'user_id' => null,
            'website_id' => $websiteId,
            'company_name' => 'EBQ Demo',
            'logo_path' => null,
            'accent_color' => '#4f46e5',
            'footer_text' => 'Generated by EBQ — connected SEO suite.',
            'contact_email' => 'demo@ebq.io',
            'contact_phone' => null,
            'contact_address' => null,
            'reply_to_email' => 'demo@ebq.io',
        ]);
    }

    /* ───────────────────────── helpers ───────────────────────── */

    /**
     * Deterministic pseudo-random integer in [min, max] from a string key,
     * so re-running the seeder produces the same dataset.
     */
    private function rand(string $key, int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        $h = crc32($key);
        return $min + ($h % ($max - $min + 1));
    }
}
