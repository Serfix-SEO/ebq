<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Seeds the five canonical plan rows for the 2026-06-26 5-tier rework.
 *
 * Tiers: trial / solo / pro / agency / enterprise
 *
 * The four old rows (free/pro/startup/agency) were renamed to legacy_*
 * and deactivated by migration 2026_06_26_000100_rename_legacy_plan_slugs.
 * Existing subscribers who hold a Stripe price ID on a legacy row are
 * unaffected — effectivePlan() resolves via stripe_price_id_yearly first.
 *
 * Idempotent: uses updateOrCreate keyed by slug, so re-running won't
 * duplicate rows or trample manually-set Stripe IDs (those are omitted
 * from the seed array deliberately).
 *
 * Unenforced limits (seeded for future enforcement):
 *   api_limits.keyword_research.*  — no monthly counter today
 *   api_limits.ai_studio.*         — distinct from enforced mistral cap
 *   api_limits.long_form.*         — no article-count metering today
 *   plan_features.scheduled_reports — feature doesn't exist yet
 *
 * Enforced limits:
 *   max_websites                            — frozenWebsiteIds()
 *   max_seats                               — WebsiteTeam::inviteMember()
 *   max_crawl_pages                         — Website::crawlPageCap()
 *   api_limits.rank_tracker.max_active_keywords — RankTrackingKeywordObserver
 *   api_limits.quick_win_finder.results_shown   — QuickWinsCard
 *   plan_features.report_whitelabel             — ReportBrandingResolver
 */
class PlanSeeder extends Seeder
{
    private const FEATURE_VIDEOS = [
        '4'  => 'https://youtu.be/bfo2ei66Pts',
        '5'  => 'https://youtu.be/MHa027Tq9sQ',
        '16' => 'https://youtu.be/Rzme7QvSbLE',
    ];

    private function features(string $websitesLine, string $keywordsLine): array
    {
        return [
            $websitesLine,
            'Search Console performance + indexing',
            'Detailed Audits',
            'AI Studio (47 AI writer tools)',
            'Long Form AI writer',
            'Cannibalization Tracking',
            'Striking Distance tracker',
            'Content Decay tracker',
            'Keyword Quick Win Finder',
            'Page Speed Insights',
            'Detailed Google Search Console Report',
            'Detailed Google Analytics Report',
            'Keywords Report',
            'Pages Report',
            'Team Access',
            $keywordsLine,
            'WordPress plugin (full)',
        ];
    }

    public function run(): void
    {
        $plans = [
            [
                'slug'               => 'trial',
                'name'               => 'Trial',
                'tagline'            => 'Get started — no credit card required.',
                'price_monthly_usd'  => 0,
                'price_yearly_usd'   => 0,
                'trial_days'         => 0,
                'max_websites'       => 1,
                'max_seats'          => 1,
                'extra_seat_price_usd' => null,
                'max_crawl_pages'    => 20000,
                'display_order'      => 1,
                'is_highlighted'     => false,
                'is_active'          => true,
                'features'           => $this->features('1 website', '20 tracked keywords'),
                'feature_videos'     => self::FEATURE_VIDEOS,
                'plan_features'      => [
                    'chatbot'           => false,
                    'ai_writer'         => true,
                    'ai_inline'         => true,
                    'live_audit'        => true,
                    'hq'                => true,
                    'redirects'         => true,
                    'dashboard_widget'  => true,
                    'post_column'       => true,
                    'report_whitelabel' => false,
                    'scheduled_reports' => false,
                ],
                'api_limits'         => [
                    'rank_tracker'      => ['max_active_keywords'  => 20],
                    'keyword_research'  => ['monthly_searches'     => 50, 'max_results_per_search' => 1000],
                    'ai_studio'         => ['monthly_tokens'       => 25000],
                    'long_form'         => ['monthly_articles'     => 2],
                    'quick_win_finder'  => ['results_shown'        => 5],
                ],
            ],
            [
                'slug'               => 'solo',
                'name'               => 'Solo',
                'tagline'            => 'For one site you actively grow.',
                'price_monthly_usd'  => 19,
                'price_yearly_usd'   => 168,
                'trial_days'         => 0,
                'max_websites'       => 3,
                'max_seats'          => 1,
                'extra_seat_price_usd' => 10,
                'max_crawl_pages'    => 100000,
                'display_order'      => 2,
                'is_highlighted'     => false,
                'is_active'          => true,
                'features'           => $this->features('3 websites', '100 tracked keywords'),
                'feature_videos'     => self::FEATURE_VIDEOS,
                'plan_features'      => [
                    'chatbot'           => false,
                    'ai_writer'         => true,
                    'ai_inline'         => true,
                    'live_audit'        => true,
                    'hq'                => true,
                    'redirects'         => true,
                    'dashboard_widget'  => true,
                    'post_column'       => true,
                    'report_whitelabel' => false,
                    'scheduled_reports' => false,
                ],
                'api_limits'         => [
                    'rank_tracker'      => ['max_active_keywords'  => 100],
                    'keyword_research'  => ['monthly_searches'     => 250, 'max_results_per_search' => 5000],
                    'ai_studio'         => ['monthly_tokens'       => 60000],
                    'long_form'         => ['monthly_articles'     => 5],
                    'quick_win_finder'  => ['results_shown'        => 10],
                ],
            ],
            [
                'slug'               => 'pro',
                'name'               => 'Pro',
                'tagline'            => 'For growing teams and agencies.',
                'price_monthly_usd'  => 49,
                'price_yearly_usd'   => 444,
                'trial_days'         => 0,
                'max_websites'       => 10,
                'max_seats'          => 3,
                'extra_seat_price_usd' => 10,
                'max_crawl_pages'    => 300000,
                'display_order'      => 3,
                'is_highlighted'     => true,
                'is_active'          => true,
                'features'           => $this->features('10 websites', '500 tracked keywords'),
                'feature_videos'     => self::FEATURE_VIDEOS,
                'plan_features'      => [
                    'chatbot'           => true,
                    'ai_writer'         => true,
                    'ai_inline'         => true,
                    'live_audit'        => true,
                    'hq'                => true,
                    'redirects'         => true,
                    'dashboard_widget'  => true,
                    'post_column'       => true,
                    'report_whitelabel' => false,
                    'scheduled_reports' => true,
                ],
                'api_limits'         => [
                    'rank_tracker'      => ['max_active_keywords'  => 500],
                    'keyword_research'  => ['monthly_searches'     => 1000, 'max_results_per_search' => 10000],
                    'ai_studio'         => ['monthly_tokens'       => 150000],
                    'long_form'         => ['monthly_articles'     => 15],
                    'quick_win_finder'  => ['results_shown'        => 20],
                ],
            ],
            [
                'slug'               => 'agency',
                'name'               => 'Agency',
                'tagline'            => 'For agencies managing many clients.',
                'price_monthly_usd'  => 99,
                'price_yearly_usd'   => 888,
                'trial_days'         => 0,
                'max_websites'       => 30,
                'max_seats'          => 10,
                'extra_seat_price_usd' => 8,
                'max_crawl_pages'    => 1000000,
                'display_order'      => 4,
                'is_highlighted'     => false,
                'is_active'          => true,
                'features'           => $this->features('30 websites', '2000 tracked keywords'),
                'feature_videos'     => self::FEATURE_VIDEOS,
                'plan_features'      => [
                    'chatbot'           => true,
                    'ai_writer'         => true,
                    'ai_inline'         => true,
                    'live_audit'        => true,
                    'hq'                => true,
                    'redirects'         => true,
                    'dashboard_widget'  => true,
                    'post_column'       => true,
                    'report_whitelabel' => true,
                    'scheduled_reports' => true,
                ],
                'api_limits'         => [
                    'rank_tracker'      => ['max_active_keywords'  => 2000],
                    'keyword_research'  => ['monthly_searches'     => 4000, 'max_results_per_search' => 30000],
                    'ai_studio'         => ['monthly_tokens'       => 600000],
                    'long_form'         => ['monthly_articles'     => 50],
                    'quick_win_finder'  => ['results_shown'        => 30],
                ],
            ],
            [
                'slug'               => 'enterprise',
                'name'               => 'Enterprise',
                'tagline'            => 'Custom scale. Contact us.',
                'price_monthly_usd'  => 0,
                'price_yearly_usd'   => 0,      // no self-serve checkout; isCheckoutReady() = false (price>0 not met)
                'trial_days'         => 0,
                'max_websites'       => null,   // unlimited
                'max_seats'          => null,   // unlimited
                'extra_seat_price_usd' => null,
                'max_crawl_pages'    => null,   // unlimited
                'display_order'      => 5,
                'is_highlighted'     => false,
                'is_active'          => true,
                'features'           => $this->features('Unlimited websites', 'Unlimited tracked keywords'),
                'feature_videos'     => self::FEATURE_VIDEOS,
                'plan_features'      => [
                    'chatbot'           => true,
                    'ai_writer'         => true,
                    'ai_inline'         => true,
                    'live_audit'        => true,
                    'hq'                => true,
                    'redirects'         => true,
                    'dashboard_widget'  => true,
                    'post_column'       => true,
                    'report_whitelabel' => true,
                    'scheduled_reports' => true,
                ],
                'api_limits'         => null,   // unlimited everything
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
