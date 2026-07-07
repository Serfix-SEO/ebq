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
    public function run(): void
    {
        $plans = [
            [
                'slug'               => 'trial',
                'name'               => 'Trial',
                'tagline'            => 'Get started — no credit card required.',
                'price_monthly_usd'  => 0,
                'price_yearly_usd'   => 0,
                // Trial length for ebq:trial-cleanup (expiry emails + data
                // deletion 3 days after expiry). 0 disables the cleanup.
                'trial_days'         => 14,
                'max_websites'       => 1,
                'max_seats'          => 1,
                'extra_seat_price_usd' => null,
                'max_crawl_pages'    => 20000,
                'display_order'      => 1,
                'is_highlighted'     => false,
                'is_active'          => true,
                'features'           => [
                    '1 website',
                    '1 team seat',
                    '20,000 page crawl budget',
                    '20 tracked keywords',
                    '50 keyword searches / mo',
                    '25,000 AI Studio tokens / mo',
                    '2 long-form articles / mo',
                    'Backlink & SERP analysis',
                    'WordPress plugin (full)',
                    'GA4 + GSC integration',
                ],
                'feature_videos'     => [],
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
                'features'           => [
                    '3 websites',
                    '1 team seat',
                    '100,000 page crawl budget',
                    '100 tracked keywords',
                    '250 keyword searches / mo',
                    '60,000 AI Studio tokens / mo',
                    '5 long-form articles / mo',
                    'Backlink & SERP analysis',
                    'WordPress plugin (full)',
                    'GA4 + GSC integration',
                ],
                'feature_videos'     => [],
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
                'features'           => [
                    '10 websites',
                    '3 team seats',
                    '300,000 page crawl budget',
                    '500 tracked keywords',
                    '1,000 keyword searches / mo',
                    '150,000 AI Studio tokens / mo',
                    '15 long-form articles / mo',
                    'Scheduled reports',
                    'Backlink & SERP analysis',
                    'WordPress plugin (full)',
                    'GA4 + GSC integration',
                ],
                'feature_videos'     => [],
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
                'features'           => [
                    '30 websites',
                    '10 team seats',
                    '1,000,000 page crawl budget',
                    '2,000 tracked keywords',
                    '4,000 keyword searches / mo',
                    '600,000 AI Studio tokens / mo',
                    '50 long-form articles / mo',
                    'White-label reports',
                    'Scheduled reports',
                    'Backlink & SERP analysis',
                    'WordPress plugin (full)',
                    'GA4 + GSC integration',
                ],
                'feature_videos'     => [],
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
                'price_yearly_usd'   => 0,
                'trial_days'         => 0,
                'max_websites'       => null,
                'max_seats'          => null,
                'extra_seat_price_usd' => null,
                'max_crawl_pages'    => null,
                'display_order'      => 5,
                'is_highlighted'     => false,
                'is_active'          => true,
                'features'           => [
                    'Unlimited websites',
                    'Unlimited team seats',
                    'Custom crawl budget',
                    'Unlimited tracked keywords',
                    'Unlimited keyword searches',
                    'Unlimited AI tokens & articles',
                    'White-label reports',
                    'Scheduled & automated reports',
                    'SSO & custom integrations',
                    'Dedicated support + SLA',
                    'WordPress plugin (full)',
                    'GA4 + GSC integration',
                ],
                'feature_videos'     => [],
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
                'api_limits'         => null,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
