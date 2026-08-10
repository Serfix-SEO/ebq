<?php

use App\Http\Controllers\Admin\ActivityController as AdminActivityController;
use App\Http\Controllers\Admin\ArtisanCommandsController as AdminArtisanCommandsController;
use App\Http\Controllers\Admin\BacklinkExplorerController;
use App\Http\Controllers\Admin\BillingController as AdminBillingController;
use App\Http\Controllers\Admin\BugReportController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\ClientImpersonationController;
use App\Http\Controllers\Admin\ContentFeedbackController;
use App\Http\Controllers\Admin\CrawlerController;
use App\Http\Controllers\Admin\DbFleetController;
use App\Http\Controllers\Admin\DomainMetricsController;
use App\Http\Controllers\Admin\FleetController;
use App\Http\Controllers\Admin\FleetTestController;
use App\Http\Controllers\Admin\KeywordApiServerController as AdminKeywordApiServerController;
use App\Http\Controllers\Admin\LeadController;
use App\Http\Controllers\Admin\LifecycleController as AdminLifecycleController;
use App\Http\Controllers\Admin\LinkGraphController;
use App\Http\Controllers\Admin\MarketingController as AdminMarketingController;
use App\Http\Controllers\Admin\OpsController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\PlatformSettingsController as AdminPlatformSettingsController;
use App\Http\Controllers\Admin\PluginAdoptionController as AdminPluginAdoptionController;
use App\Http\Controllers\Admin\PluginReleaseController as AdminPluginReleaseController;
use App\Http\Controllers\Admin\SiteExplorerUsageController as AdminSiteExplorerUsageController;
use App\Http\Controllers\Admin\UsageController as AdminUsageController;
use App\Http\Controllers\Admin\WebsiteFeatureController as AdminWebsiteFeatureController;
use App\Http\Controllers\AiStudioController;
use App\Http\Controllers\AiStudioWriterController;
use App\Http\Controllers\Api\V1\PricingController;
use App\Http\Controllers\BacklinksController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ClientReportExportController;
use App\Http\Controllers\CompetitorsController;
use App\Http\Controllers\Content\PublicOnboardingStartController;
use App\Http\Controllers\ContentBillingController;
use App\Http\Controllers\EmailUnsubscribeController;
use App\Http\Controllers\GoogleCapController;
use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\GuestAuditController;
use App\Http\Controllers\GuestKeywordVolumeController;
use App\Http\Controllers\GuestPageSpeedController;
use App\Http\Controllers\GuestRankCheckController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MicrosoftOAuthController;
use App\Http\Controllers\PageAuditController;
use App\Http\Controllers\PublicReportController;
use App\Http\Controllers\ReportDisavowController;
use App\Http\Controllers\ReportShareController;
use App\Http\Controllers\ReportViewController;
use App\Http\Controllers\SiteAuditExportController;
use App\Http\Controllers\SocialShareOAuthController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\ToolPreviewController;
use App\Http\Controllers\Webhooks\KeywordFinderWebhookController;
use App\Http\Controllers\WebsiteAnalyzeController;
use App\Http\Controllers\WebsiteOverviewController;
use App\Http\Controllers\WordPressConnectController;
use App\Http\Controllers\WordPressEmbedController;
use App\Http\Controllers\WordPressPluginDownloadController;
use App\Http\Controllers\WordPressPluginVersionController;
use App\Livewire\Content\PublicOnboarding;
use Illuminate\Support\Facades\Route;

// SEO-platform marketing pages, behind the runtime kill-switch
// (config/features.php `seo_platform_ui`). Names STAY REGISTERED in both
// states — they are referenced across dozens of blades and in middleware
// allowlists, and firstAccessibleRoute() must never resolve an unknown name.
// When the switch is off, `/` serves the Content Autopilot landing (the root
// URL carries the product page) and the SEO pages 302 home — 302, not 301,
// because the switch is reversible and a cached 301 would outlive it.
Route::get('/', fn () => config('features.seo_platform_ui')
    ? view('landing')
    : view('content-landing'))->name('landing');
Route::get('/features', fn () => config('features.seo_platform_ui')
    ? view('features')
    : redirect()->route('landing'))->name('features');
Route::get('/wordpress-plugin', fn () => config('features.seo_platform_ui')
    ? view('wordpress-plugin')
    : redirect()->route('landing'))->name('wordpress-plugin');
Route::get('/pricing', fn () => config('features.seo_platform_ui')
    ? view('pricing')
    : redirect()->route('content.pricing'))->name('pricing');
Route::get('/trust-score', fn () => config('features.seo_platform_ui')
    ? view('trust-score')
    : redirect()->route('landing'))->name('trust-score');
// With the SEO UI off, `/` serves this same page — one canonical URL, so the
// old address 302s home instead of serving a duplicate.
Route::get('/content-autopilot', fn () => config('features.seo_platform_ui')
    ? view('content-landing')
    : redirect()->route('landing'))->name('content.landing');
// Content AI Autopilot is a separately-billed product, so it gets its own
// pricing page — /pricing prices the SEO platform only (they used to share
// one grid behind a toggle nobody noticed).
Route::view('/content-autopilot/pricing', 'content-pricing')->name('content.pricing');
// Landing form posts here: verify + create the provisional site, then hand off
// to the wizard (Business step). Keeps the domain question on the landing only.
Route::post('/content-autopilot/start', PublicOnboardingStartController::class)
    ->name('content.onboarding.begin');
Route::get('/content-autopilot/start', PublicOnboarding::class)
    ->name('content.onboarding');
// "Continue with Google" from the onboarding account step (reuses the whitelisted
// SSO callback via a session intent flag).
Route::get('/content-autopilot/auth/google', [GoogleOAuthController::class, 'contentOnboardingRedirect'])
    ->name('content.onboarding.google');
Route::get('/website-revamp', fn () => config('features.seo_platform_ui')
    ? view('website-revamp')
    : redirect()->route('landing'))->name('website-revamp');
Route::view('/contact', 'contact')->name('contact');
Route::view('/terms-conditions', 'legal.terms')->name('terms-conditions');
Route::view('/privacy-policy', 'legal.privacy')->name('privacy-policy');
Route::view('/refund-policy', 'legal.refund-policy')->name('refund-policy');
Route::get('/guide', fn () => config('features.seo_platform_ui')
    ? view('guide')
    : redirect()->route('landing'))->name('guide');

Route::get('/locale/{locale}', [LocaleController::class, 'set'])->name('locale.set');

// Public, no-auth shared backlink report. High-entropy token → cached snapshot.
// Bad/revoked/expired tokens 404 (never 403). Throttled against enumeration.
Route::get('/r/{token}', [PublicReportController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('report.public');

// Homepage "Analyze website" funnel. Anonymous submit calls NO backlink API —
// it validates the URL (throttle + SSRF + reCAPTCHA) and returns require:signup.
// Only a signed-in submit generates the report. See WebsiteAnalyzeController.
Route::post('/analyze', [WebsiteAnalyzeController::class, 'store'])->name('analyze.store');

// Report view. Guests see a blurred MOCK teaser + signup modal (no API call);
// authed (NOT verified-gated) users see the real report — first value lands
// right after signup, before email verification.
Route::get('/report/status', [ReportViewController::class, 'status'])
    ->middleware(['auth', 'throttle:60,1'])
    ->name('report.status');
Route::get('/report/disavow', ReportDisavowController::class)
    ->middleware(['auth', 'throttle:20,1'])
    ->name('report.disavow');
Route::get('/report/anchor-links', [ReportViewController::class, 'anchorLinks'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('report.anchor-links');
Route::get('/report/view', [ReportViewController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('report.view');

// Blurred teaser preview for the public tools (anonymous): renders the tool's
// real result view with sample data behind a signup/login modal. No API.
Route::get('/tools/preview/{tool}', [ToolPreviewController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('tool.preview');

// Public, no-signup SEO audit launched from the landing-page hero. Anonymous —
// no GSC/GA, no paid SERP/CWV — and rate-limited + reCAPTCHA-gated in the
// controller. POST keeps default `web` CSRF protection (the form ships a token).
Route::post('/audit', [GuestAuditController::class, 'store'])->name('guest-audit.store');
Route::get('/audit/{guestPageAudit}/status', [GuestAuditController::class, 'status'])->name('guest-audit.status');
Route::get('/audit/{guestPageAudit}', [GuestAuditController::class, 'show'])->name('guest-audit.show');

// Dedicated public SEO-audit tool page (same flow as the landing hero).
Route::get('/free-audit', fn () => config('features.seo_platform_ui')
    ? view('tools.audit')
    : redirect()->route('landing'))->name('tools.audit');

// Public, no-signup PageSpeed test tool — same progressive friction as the
// guest audit (1st free on-screen, 2nd by email, 3rd → signup). Runs the
// self-hosted Lighthouse; rate-limited + reCAPTCHA-gated in the controller.
Route::get('/pagespeed-test', fn () => config('features.seo_platform_ui')
    ? view('tools.page-speed')
    : redirect()->route('landing'))->name('tools.pagespeed');
Route::post('/pagespeed-test', [GuestPageSpeedController::class, 'store'])->name('guest-pagespeed.store');
Route::get('/pagespeed-test/{guestPageSpeed}/status', [GuestPageSpeedController::class, 'status'])->name('guest-pagespeed.status');
Route::get('/pagespeed-test/{guestPageSpeed}', [GuestPageSpeedController::class, 'show'])->name('guest-pagespeed.show');

// Public, no-signup keyword rank tracker — same progressive friction as the
// guest audit / PageSpeed test (1st free on-screen, 2nd by email, 3rd → signup).
// Runs a single Serper organic lookup; rate-limited + reCAPTCHA-gated in the controller.
Route::get('/rank-tracker', fn () => config('features.seo_platform_ui')
    ? view('tools.rank-tracker')
    : redirect()->route('landing'))->name('tools.rank-tracker');
Route::post('/rank-tracker', [GuestRankCheckController::class, 'store'])->name('guest-rank.store');
Route::get('/rank-tracker/{guestRankCheck}/status', [GuestRankCheckController::class, 'status'])->name('guest-rank.status');
Route::get('/rank-tracker/{guestRankCheck}', [GuestRankCheckController::class, 'show'])->name('guest-rank.show');

// Public, no-signup keyword search-volume finder — same progressive friction
// (1st free on-screen, 2nd by email, 3rd → signup). One keyword per check;
// DB-first against the shared keyword_metrics cache, so it only calls Keywords
// Everywhere on a cache miss. Rate-limited + reCAPTCHA-gated in the controller.
// Distinct public path so it doesn't collide with the authenticated portal
// finder at /keyword-volume (mirrors /pagespeed vs /pagespeed-test).
Route::get('/keyword-volume-checker', fn () => config('features.seo_platform_ui')
    ? view('tools.keyword-volume')
    : redirect()->route('landing'))->name('tools.keyword-volume');
Route::post('/keyword-volume-checker', [GuestKeywordVolumeController::class, 'store'])->name('guest-volume.store');
Route::get('/keyword-volume-checker/{guestKeywordVolume}/status', [GuestKeywordVolumeController::class, 'status'])->name('guest-volume.status');
Route::get('/keyword-volume-checker/{guestKeywordVolume}', [GuestKeywordVolumeController::class, 'show'])->name('guest-volume.show');

// Always-fresh download of the latest packaged WP plugin — bypasses public/ caching.
Route::get('/wordpress/plugin.zip', WordPressPluginDownloadController::class)->name('wordpress.plugin.download');
Route::get('/wordpress/plugin/version', WordPressPluginVersionController::class)->name('wordpress.plugin.version');

// Public pricing API — anonymous, drives the WordPress plugin's setup
// wizard pricing step + any external integration that needs the plan
// list. No identifier captured (matches /wordpress/plugin/version).
Route::get('/api/v1/plans', [PricingController::class, 'public'])
    ->name('api.v1.plans');

// Stripe webhook — extends Cashier's controller. CSRF-exempted in
// bootstrap/app.php. Stripe signs the request body; the controller
// verifies via STRIPE_WEBHOOK_SECRET so no extra auth needed.
Route::post('/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');

// Self-hosted keyword API result callback. Server-to-server; CSRF-exempted in
// bootstrap/app.php. The body is HMAC-signed with the originating server's
// webhook_secret — the controller verifies it.
Route::post('/webhooks/keyword-finder', KeywordFinderWebhookController::class)
    ->name('webhooks.keyword-finder');

// Billing — Stripe Checkout + Customer Portal redirects. Auth required
// because we need the user context to resolve which Website is being
// billed. The /checkout endpoint mints a Stripe Hosted Checkout session
// and redirects; /success and /cancel handle the post-checkout return.
Route::middleware(['web', 'auth'])->group(function (): void {
    // Subscription management page — current plan, plan grid, cancel,
    // resume, recent invoices. Lives at /billing under sidebar nav.
    Route::get('/billing', [BillingController::class, 'show'])
        ->name('billing.show');
    Route::post('/billing/swap', [BillingController::class, 'swap'])
        ->name('billing.swap');
    Route::post('/billing/cancel', [BillingController::class, 'cancelSubscription'])
        ->name('billing.cancel-subscription');
    Route::post('/billing/resume', [BillingController::class, 'resume'])
        ->name('billing.resume');

    // Stripe Hosted Checkout flow — first-time purchase or post-cancel
    // re-subscribe. swap() is preferred for active subscribers.
    Route::get('/billing/checkout', [BillingController::class, 'checkout'])
        ->name('billing.checkout');
    Route::get('/billing/success', [BillingController::class, 'success'])
        ->name('billing.success');
    // Renamed from `billing.cancel` to free that name for the
    // subscription-cancel POST above. This handles "user backed out
    // of Stripe Checkout" only, not in-app cancellation.
    Route::get('/billing/cancel-checkout', [BillingController::class, 'cancel'])
        ->name('billing.cancel-checkout');
    Route::get('/billing/portal', [BillingController::class, 'portal'])
        ->name('billing.portal');

    // Content Autopilot product — its own Stripe named subscription (`content`)
    // + per-website addon. Separate controller so the dashboard `default` flow
    // (checkout guard, swap, plan-slug sync) is never touched.
    Route::get('/content/billing/checkout', [ContentBillingController::class, 'checkout'])
        ->name('content.billing.checkout');
    Route::get('/content/billing/success', [ContentBillingController::class, 'success'])
        ->name('content.billing.success');
    Route::get('/content/billing/cancel-checkout', [ContentBillingController::class, 'cancelCheckout'])
        ->name('content.billing.cancel-checkout');
    Route::post('/content/billing/add-website', [ContentBillingController::class, 'addWebsite'])
        ->name('content.billing.add-website');
    Route::post('/content/billing/remove-website', [ContentBillingController::class, 'removeWebsite'])
        ->name('content.billing.remove-website');
    Route::post('/content/billing/cancel', [ContentBillingController::class, 'cancel'])
        ->name('content.billing.cancel');
    Route::post('/content/billing/resume', [ContentBillingController::class, 'resume'])
        ->name('content.billing.resume');
});

// OAuth-style one-click link from the WordPress plugin.
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/wordpress/connect', [WordPressConnectController::class, 'start'])->name('wordpress.connect.start');
    Route::post('/wordpress/connect', [WordPressConnectController::class, 'approve'])->name('wordpress.connect.approve');
});

// Signed deep-link from WP HQ — opens Reports in a full tab (session auth).
Route::get('/wordpress/embed/reports', [WordPressEmbedController::class, 'reports'])
    ->middleware(['web', 'signed'])
    ->name('wordpress.embed.reports');

Route::get('/wordpress/embed/page-audit', [WordPressEmbedController::class, 'pageAudit'])
    ->middleware(['web', 'signed'])
    ->name('wordpress.embed.page-audit');

// Signed unsubscribe link carried by lifecycle/marketing emails. GET confirms
// (mail scanners prefetch — must not mutate), POST stamps the opt-out. POST is
// also the RFC 8058 one-click target, hence CSRF-exempt in bootstrap/app.php.
Route::get('/email/unsubscribe/{user}', [EmailUnsubscribeController::class, 'show'])
    ->middleware(['web', 'signed'])
    ->name('email.unsubscribe');
Route::post('/email/unsubscribe/{user}', [EmailUnsubscribeController::class, 'store'])
    ->middleware(['web', 'signed'])
    ->name('email.unsubscribe.store');

Route::middleware(['auth', 'verified', 'onboarded'])->group(function () {
    Route::view('/dashboard', 'dashboard')->middleware('feature:dashboard')->name('dashboard');
    // Post-signup landing hub — website-scoped Site Explorer report + a top
    // tab bar (Site Explorer / Site Health / Traffic / GSC), each tab showing
    // a real processing/needs-action/ready pill. See WebsiteOverviewController.
    Route::get('/overview', [WebsiteOverviewController::class, 'show'])->name('website-overview');
    Route::view('/site-explorer', 'site-explorer')->name('site-explorer');
    // Backlink slice of the Site Explorer snapshot for the current website,
    // rendered in dashboard style (Pulse group nav item).
    Route::get('/backlinks', [BacklinksController::class, 'show'])->name('backlinks.index');
    // Organic-competitor slice of the same snapshot (Pulse group nav item).
    Route::get('/competitors', [CompetitorsController::class, 'show'])->name('competitors.index');
    // Priority Action Queue drill-down: one filterable + paginated page per issue
    // group (crawl_* findings and the GSC/keyword action types).
    Route::get('/issues/{key}', fn (string $key) => view('issues.show', ['key' => $key]))
        ->middleware('feature:dashboard')
        ->name('issues.show')->where('key', '[a-z0-9_]+');
    Route::view('/statistics', 'statistics')->middleware('feature:dashboard')->name('statistics');
    Route::view('/keywords', 'keywords.index')->middleware('feature:keywords')->name('keywords.index');
    // Registered before the /keywords/{query} catch-all below so it isn't swallowed.
    Route::view('/keywords/fix', 'keywords.fix')->middleware('feature:audits')->name('keywords.fix');
    Route::get('/keywords/{query}', fn (string $query) => view('keywords.show', ['query' => $query]))
        ->middleware('feature:keywords')
        ->name('keywords.show')->where('query', '.*');
    // Unified Keyword Research hub (Ideas · Volume · Competitor Gap). The old
    // per-tool paths redirect here (deep links / bookmarks keep working); the
    // route names are retained so existing route() callers still resolve.
    Route::view('/keyword-research', 'keyword-research.index')->middleware('feature:keywords')->name('keyword-research.index');
    Route::redirect('/keyword-volume', '/keyword-research?tab=volume')->name('keyword-volume.index');
    Route::redirect('/keyword-ideas', '/keyword-research?tab=ideas')->name('keyword-ideas.index');
    // Competitor Gap moved out of the hub to its own Orbit page (2026-07-14).
    Route::view('/keyword-gap', 'keyword-gap')->middleware('feature:keywords')->name('keyword-gap.index');
    Route::redirect('/competitive', '/keyword-gap')->name('competitive.index');
    // Competitor auto-discovery — reachable from the Gap tab.
    Route::view('/competitive/competitors', 'competitive.competitors')->middleware('feature:keywords')->name('competitive.competitors');
    // Competitor Discovery — find competitors for ANY url (keyword-fleet → LLM
    // junk-check → crawl-if-scrap → SERP tally). Orbit menu.
    Route::view('/competitor-discovery', 'competitor-discovery')->middleware('feature:keywords')->name('competitor-discovery.index');
    Route::view('/rank-tracking', 'rank-tracking.index')->middleware('feature:rank_tracking')->name('rank-tracking.index');
    Route::get('/rank-tracking/{keywordId}', fn (string $keywordId) => view('rank-tracking.show', ['keywordId' => $keywordId]))
        ->middleware('feature:rank_tracking')
        ->name('rank-tracking.show');
    // The legacy manual backlink tracker (backlinks/index.blade.php +
    // Livewire\Backlinks\BacklinksManager) was UNROUTED 2026-07-14 — it
    // used to own this URI and, being registered after the Site Explorer
    // backlinks page above, silently overrode it (same URI + name → last
    // registration wins). /backlinks is now exclusively the read-only
    // Site Explorer backlink view (BacklinksController).
    Route::view('/pages', 'pages.index')->middleware('feature:pages')->name('pages.index');
    Route::view('/custom-audit', 'pages.custom-audit')->middleware('feature:audits')->name('custom-audit.index');
    Route::view('/pagespeed', 'pages.page-speed')->middleware('feature:audits')->name('pagespeed.index');
    Route::get('/pages/{id}', fn (string $id) => view('pages.show', ['pageUrl' => $id]))
        ->middleware('feature:pages')
        ->name('pages.show')->where('id', '.*');
    Route::view('/sitemaps', 'sitemaps.index')->middleware('feature:sitemaps')->name('sitemaps.index');
    Route::view('/link-structure', 'link-structure.index')->middleware('feature:link_structure')->name('link-structure.index');
    Route::get('/site-audit/download', [SiteAuditExportController::class, 'download'])
        ->middleware(['feature:link_structure', 'throttle:10,1'])
        ->name('site-audit.download');
    Route::get('/report/download', [ClientReportExportController::class, 'download'])
        ->middleware('throttle:10,1')
        ->name('report.download');
    Route::post('/report/share', [ReportShareController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('report.share');
    Route::delete('/report/share', [ReportShareController::class, 'destroy'])
        ->name('report.share.revoke');
    Route::get('/page-audits/{pageAuditReport}', [PageAuditController::class, 'show'])
        ->middleware('feature:audits')
        ->name('page-audits.show');
    Route::get('/page-audits/{id}/download', [PageAuditController::class, 'download'])
        ->middleware(['feature:audits', 'throttle:30,1'])
        ->name('page-audits.download');
    Route::view('/websites', 'websites.index')->name('websites.index');
    Route::view('/team', 'team.index')->middleware('feature:team')->name('team.index');
    Route::view('/reports', 'reports.index')->middleware('feature:reports')->name('reports.index');

    // Content Autopilot — automatic content calendar (setup wizard renders on
    // the same page while no plan exists). Review page shows one topic's
    // current article + SEO checks. Env-gated (CONTENT_AUTOPILOT_UI): the
    // shared box-A docroot means code goes live before deploy decisions — the
    // env flag, not code presence, decides where the feature is visible.
    if (config('services.content_autopilot.ui_enabled')) {
        // Get started / buy — reachable WITHOUT content access (team permission
        // still applies). This is where no-access users are redirected.
        Route::view('/content/get-started', 'content.get-started')
            ->middleware('feature:content')->name('content.get-started');
        // The product surfaces require content access for the current website
        // (content.access), OR an existing plan-with-topics so a lapsed user can
        // still publish already-generated articles (handled inside the middleware).
        Route::view('/content', 'content.index')->middleware(['feature:content', 'content.access'])->name('content.index');
        Route::view('/content/research', 'content.research')->middleware(['feature:content', 'content.access'])->name('content.research');
        Route::view('/content/settings', 'content.settings')->middleware(['feature:content', 'content.access'])->name('content.settings');
        Route::view('/content/integrations', 'content.integrations')->middleware(['feature:content', 'content.access'])->name('content.integrations');
        Route::view('/content/social', 'content.social')->middleware(['feature:content', 'content.access'])->name('content.social');
        Route::view('/content/tracker', 'content.tracker')->middleware(['feature:content', 'content.access'])->name('content.tracker');
        Route::get('/content/tracker/{keyword}', fn (string $keyword) => view('content.keyword-history', ['keywordId' => $keyword]))
            ->middleware(['feature:content', 'content.access'])
            ->name('content.keyword-history');
        Route::get('/content/topics/{topic}', fn (string $topic) => view('content.review', ['topicId' => $topic]))
            ->middleware(['feature:content', 'content.access'])
            ->name('content.review');
    }
    Route::view('/settings', 'settings.index')->middleware('feature:settings')->name('settings.index');

    // `feature.enabled:ai_studio` is the global beta kill-switch (config
    // features.ai_studio); `feature:ai_studio` is the per-team permission gate.
    // Route names stay registered even when disabled so firstAccessibleRoute()
    // redirects never hit an unknown route — the middleware just 404s the hit.
    Route::middleware(['feature.enabled:ai_studio', 'feature:ai_studio'])->group(function (): void {
        Route::get('/ai-studio', [AiStudioController::class, 'index'])
            ->name('ai-studio.index');
        Route::post('/ai-studio/tools/{toolId}/run', [AiStudioController::class, 'run'])
            ->where('toolId', '[a-z0-9\-]+')
            ->middleware('throttle:30,1')
            ->name('ai-studio.run');
        Route::put('/ai-studio/brand-voice', [AiStudioController::class, 'brandVoiceUpdate'])
            ->middleware('throttle:10,1')
            ->name('ai-studio.brand-voice.update');
        Route::delete('/ai-studio/brand-voice', [AiStudioController::class, 'brandVoiceDestroy'])
            ->name('ai-studio.brand-voice.destroy');

        // Blog Post Wizard — the dashboard re-implementation of the WP
        // plugin's multi-step AI Writer. The marker tool's launcher card
        // links here instead of running the generic tool form. All state
        // mutations delegate to WriterProjectService via
        // AiStudioWriterController (session-resolved website).
        Route::get('/ai-studio/blog-post-wizard', [AiStudioWriterController::class, 'page'])
            ->name('ai-studio.wizard');

        Route::prefix('ai-studio/writer-projects')->name('ai-studio.writer-projects.')->group(function (): void {
            Route::get('/', [AiStudioWriterController::class, 'index'])->name('index');
            Route::post('/', [AiStudioWriterController::class, 'store'])->name('store');
            Route::get('/{externalId}', [AiStudioWriterController::class, 'show'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('show');
            Route::patch('/{externalId}', [AiStudioWriterController::class, 'update'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('update');
            Route::delete('/{externalId}', [AiStudioWriterController::class, 'destroy'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('destroy');
            Route::post('/{externalId}/brief', [AiStudioWriterController::class, 'generateBrief'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('brief');
            Route::post('/{externalId}/brief/chat', [AiStudioWriterController::class, 'briefChat'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('brief.chat');
            Route::post('/{externalId}/images/search', [AiStudioWriterController::class, 'searchImages'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('images.search');
            Route::post('/{externalId}/strategy', [AiStudioWriterController::class, 'strategy'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('strategy');
            Route::post('/{externalId}/generate', [AiStudioWriterController::class, 'generate'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('generate');
            Route::get('/{externalId}/generate-status', [AiStudioWriterController::class, 'generateStatus'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('generate.status');
            Route::get('/{externalId}/credits', [AiStudioWriterController::class, 'credits'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('credits');
        });

        Route::get('/ai-studio/ai-writer-prompts', [AiStudioWriterController::class, 'promptsIndex'])
            ->name('ai-studio.prompts.index');
        Route::post('/ai-studio/ai-writer-prompts', [AiStudioWriterController::class, 'promptsStore'])
            ->middleware('throttle:20,1')->name('ai-studio.prompts.store');
        Route::delete('/ai-studio/ai-writer-prompts/{externalId}', [AiStudioWriterController::class, 'promptsDestroy'])
            ->where('externalId', '[A-Za-z0-9\-]+')->name('ai-studio.prompts.destroy');
    });
});

Route::middleware(['auth', 'verified', 'throttle:oauth'])->group(function () {
    Route::view('/onboarding', 'onboarding.index')->name('onboarding');
    Route::get('/auth/google/redirect', [GoogleOAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('/auth/google/callback', [GoogleOAuthController::class, 'callback'])->name('google.callback');
    // Gmail-send scope grant — same controller, requests gmail.send on top
    // of the existing Analytics/GSC/Indexing scopes via incremental consent.
    Route::get('/auth/google/mail/redirect', [GoogleOAuthController::class, 'redirectMailScope'])->name('google.mail.redirect');
    // Microsoft / Outlook OAuth — powers the "send report from Outlook" mail transport.
    Route::get('/auth/microsoft/redirect', [MicrosoftOAuthController::class, 'redirect'])->name('microsoft.redirect');
    Route::get('/auth/microsoft/callback', [MicrosoftOAuthController::class, 'callback'])->name('microsoft.callback');
    // Facebook / X OAuth — Content Autopilot social auto-share connections.
    Route::get('/auth/facebook/redirect', [SocialShareOAuthController::class, 'facebookRedirect'])->name('social.facebook.redirect');
    Route::get('/auth/facebook/callback', [SocialShareOAuthController::class, 'facebookCallback'])->name('social.facebook.callback');
    Route::get('/auth/x/redirect', [SocialShareOAuthController::class, 'xRedirect'])->name('social.x.redirect');
    Route::get('/auth/x/callback', [SocialShareOAuthController::class, 'xCallback'])->name('social.x.callback');
    Route::get('/auth/pinterest/redirect', [SocialShareOAuthController::class, 'pinterestRedirect'])->name('social.pinterest.redirect');
    Route::get('/auth/pinterest/callback', [SocialShareOAuthController::class, 'pinterestCallback'])->name('social.pinterest.callback');
});

// Google Cross-Account Protection (RISC/CAP) receiver endpoint.
Route::post('/auth/google/cap/events', GoogleCapController::class)
    ->middleware(['throttle:oauth'])
    ->name('google.cap.events');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
    // Admin home: today's signups/trials/payments, customer segments, revenue.
    Route::get('/', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/drill/{metric}', [\App\Http\Controllers\Admin\DashboardController::class, 'drill'])->name('dashboard.drill');
    Route::get('/clients', [AdminClientController::class, 'index'])->name('clients.index');
    Route::post('/clients', [AdminClientController::class, 'store'])->name('clients.store');
    Route::post('/clients/bulk', [AdminClientController::class, 'bulk'])->name('clients.bulk');
    Route::put('/clients/{user}', [AdminClientController::class, 'update'])->name('clients.update');
    Route::post('/clients/{user}/crawl', [AdminClientController::class, 'crawl'])->name('clients.crawl');
    Route::post('/clients/{user}/impersonate', [ClientImpersonationController::class, 'start'])->name('clients.impersonate');

    Route::view('/docs/site-crawler', 'admin.docs.crawler')->name('docs.crawler');

    Route::view('/proxies', 'admin.proxies')->name('proxies.index');

    Route::get('/activities', [AdminActivityController::class, 'index'])->name('activities.index');

    Route::get('/crawler', [CrawlerController::class, 'index'])->name('crawler.index');

    // Backlink Explorer — search stored backlinks (crawler + persisted
    // DataForSEO) from the link graph; zero new provider calls.
    Route::get('/backlink-explorer', [BacklinkExplorerController::class, 'index'])->name('backlink-explorer.index');
    Route::get('/backlink-explorer/export', [BacklinkExplorerController::class, 'export'])->name('backlink-explorer.export');

    // Domain intelligence — browse/search/drill the domain_metrics asset.
    Route::get('/domain-metrics', [DomainMetricsController::class, 'index'])->name('domain-metrics.index');
    Route::get('/domain-metrics/{domainMetric}', [DomainMetricsController::class, 'show'])->name('domain-metrics.show');
    Route::post('/domain-metrics/{domainMetric}/reclassify', [DomainMetricsController::class, 'reclassify'])->name('domain-metrics.reclassify');
    Route::post('/domain-metrics/{domainMetric}/refresh', [DomainMetricsController::class, 'refresh'])->name('domain-metrics.refresh');

    // Link-graph engine dashboard (Tier-1.5 crawler + discovery analytics).
    Route::get('/link-graph', [LinkGraphController::class, 'index'])->name('link-graph.index');
    Route::post('/link-graph/toggle', [LinkGraphController::class, 'toggle'])->name('link-graph.toggle');
    Route::post('/link-graph/reseed', [LinkGraphController::class, 'reseed'])->name('link-graph.reseed');

    // Ops dashboard: failed jobs + never-crawled stuck sites + queue depths.
    // Companion to the ebq:failed-jobs-alert mailed digest (2026-07-06 incident).
    Route::get('/ops', [OpsController::class, 'index'])->name('ops.index');
    Route::get('/content-feedback', [ContentFeedbackController::class, 'index'])->name('content-feedback.index');
    Route::post('/ops/retry', [OpsController::class, 'retry'])->name('ops.retry');
    Route::post('/ops/forget', [OpsController::class, 'forget'])->name('ops.forget');
    Route::post('/ops/crawl/{crawlSite}', [OpsController::class, 'startCrawl'])->name('ops.start-crawl');

    Route::get('/fleet', [FleetController::class, 'index'])->name('fleet.index');
    Route::post('/fleet/settings', [FleetController::class, 'settings'])->name('fleet.settings');
    Route::post('/fleet/provision', [FleetController::class, 'provision'])->name('fleet.provision');
    Route::post('/fleet/reconcile', [FleetController::class, 'reconcile'])->name('fleet.reconcile');
    Route::post('/fleet/{node}/drain', [FleetController::class, 'drain'])->name('fleet.drain');
    Route::post('/fleet/{node}/destroy', [FleetController::class, 'destroy'])->name('fleet.destroy');

    // Fleet UI E2E test report (screenshot slideshow from the latest Dusk run)
    Route::get('/fleet-test', [FleetTestController::class, 'index'])->name('fleet-test');

    // Database-shard node fleet (App\Http\Controllers\Admin\DbFleetController)
    Route::get('/db-fleet', [DbFleetController::class, 'index'])->name('db-fleet.index');
    Route::post('/db-fleet/settings', [DbFleetController::class, 'settings'])->name('db-fleet.settings');
    Route::post('/db-fleet/register-primary', [DbFleetController::class, 'registerPrimary'])->name('db-fleet.register-primary');
    Route::post('/db-fleet/provision', [DbFleetController::class, 'provision'])->name('db-fleet.provision');
    Route::post('/db-fleet/move', [DbFleetController::class, 'move'])->name('db-fleet.move');
    Route::post('/db-fleet/{node}/bootstrap', [DbFleetController::class, 'bootstrap'])->name('db-fleet.bootstrap');
    Route::post('/db-fleet/{node}/migrate', [DbFleetController::class, 'migrate'])->name('db-fleet.migrate');
    Route::post('/db-fleet/{node}/drain', [DbFleetController::class, 'drain'])->name('db-fleet.drain');
    Route::post('/db-fleet/{node}/destroy', [DbFleetController::class, 'destroy'])->name('db-fleet.destroy');

    Route::get('/marketing', [AdminMarketingController::class, 'index'])->name('marketing.index');
    Route::get('/marketing/sends', [AdminMarketingController::class, 'sends'])->name('marketing.sends');
    Route::post('/marketing/{website}/send', [AdminMarketingController::class, 'send'])->name('marketing.send');

    // Lifecycle emails: segment report + send log + kill switches + test send.
    Route::get('/lifecycle', [AdminLifecycleController::class, 'index'])->name('lifecycle.index');
    Route::put('/lifecycle/settings', [AdminLifecycleController::class, 'settings'])->name('lifecycle.settings');
    Route::post('/lifecycle/test-send', [AdminLifecycleController::class, 'testSend'])->name('lifecycle.test-send');

    Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('/bug-reports', [BugReportController::class, 'index'])->name('bug-reports.index');
    Route::get('/bug-reports/{bugReport}/screenshot', [BugReportController::class, 'screenshot'])->name('bug-reports.screenshot');
    Route::post('/bug-reports/{bugReport}/resolve', [BugReportController::class, 'resolve'])->name('bug-reports.resolve');
    Route::get('/usage', [AdminUsageController::class, 'index'])->name('usage.index');
    Route::get('/site-explorer-usage', [AdminSiteExplorerUsageController::class, 'index'])->name('site-explorer-usage.index');
    Route::post('/site-explorer-usage/clear-cache', [AdminSiteExplorerUsageController::class, 'clearCache'])->name('site-explorer-usage.clear-cache');
    Route::get('/plugin-releases', [AdminPluginReleaseController::class, 'index'])->name('plugin-releases.index');
    Route::post('/plugin-releases/toggle-updates', [AdminPluginReleaseController::class, 'toggleUpdates'])->name('plugin-releases.toggle-updates');
    Route::post('/plugin-releases', [AdminPluginReleaseController::class, 'store'])->name('plugin-releases.store');
    Route::post('/plugin-releases/{pluginRelease}/publish', [AdminPluginReleaseController::class, 'publish'])->name('plugin-releases.publish');
    Route::post('/plugin-releases/{pluginRelease}/zip', [AdminPluginReleaseController::class, 'uploadZip'])->name('plugin-releases.upload-zip');
    Route::post('/plugin-releases/{pluginRelease}/rollback', [AdminPluginReleaseController::class, 'rollback'])->name('plugin-releases.rollback');
    Route::delete('/plugin-releases/{pluginRelease}', [AdminPluginReleaseController::class, 'destroy'])->name('plugin-releases.destroy');
    Route::get('/plugin-adoption', [AdminPluginAdoptionController::class, 'index'])->name('plugin-adoption.index');

    // Per-website WordPress-plugin feature toggles. Admin enables/disables
    // value-add features (chatbot, AI writer, etc.) on a per-site basis.
    // Core SEO output is never gated server-side, so disabling here can
    // never de-index a customer's site.
    Route::get('/website-features', [AdminWebsiteFeatureController::class, 'index'])
        ->name('website-features.index');
    // Global master kill-switch — overrides per-site flags. Lives at a
    // distinct path so the route model binding for `{website}` below
    // doesn't claim the literal "global" segment.
    Route::put('/website-features/global', [AdminWebsiteFeatureController::class, 'globalUpdate'])
        ->name('website-features.global-update');
    Route::put('/website-features/{website}', [AdminWebsiteFeatureController::class, 'update'])
        ->name('website-features.update');

    // Per-website subscription overview — sits as a tab on the
    // WordPress Plugin master page. Read-only in v1.
    Route::get('/billing', [AdminBillingController::class, 'index'])->name('billing.index');

    // Plan management — drives the marketing /pricing page, the public
    // /api/v1/plans endpoint, and the Stripe checkout flow. Editable
    // per-row: name, pricing, Stripe price IDs, trial days, features,
    // active/highlighted state.
    Route::get('/plans', [AdminPlanController::class, 'index'])->name('plans.index');
    Route::get('/plans/create', [AdminPlanController::class, 'create'])->name('plans.create');
    Route::post('/plans', [AdminPlanController::class, 'store'])->name('plans.store');
    Route::get('/plans/{plan}/edit', [AdminPlanController::class, 'edit'])->name('plans.edit');
    Route::put('/plans/{plan}', [AdminPlanController::class, 'update'])->name('plans.update');

    // Self-hosted keyword API fleet management — add/edit/remove servers,
    // live health probes, and sample volume/discovery test dispatches.
    Route::get('/keyword-servers', [AdminKeywordApiServerController::class, 'index'])->name('keyword-servers.index');
    Route::post('/keyword-servers', [AdminKeywordApiServerController::class, 'store'])->name('keyword-servers.store');
    Route::put('/keyword-servers/{keywordServer}', [AdminKeywordApiServerController::class, 'update'])->name('keyword-servers.update');
    Route::delete('/keyword-servers/{keywordServer}', [AdminKeywordApiServerController::class, 'destroy'])->name('keyword-servers.destroy');
    Route::post('/keyword-servers/{keywordServer}/test', [AdminKeywordApiServerController::class, 'test'])->name('keyword-servers.test');
    Route::post('/keyword-servers/{keywordServer}/test-keyword', [AdminKeywordApiServerController::class, 'testKeyword'])->name('keyword-servers.test-keyword');
    Route::post('/keyword-servers/{keywordServer}/test-website', [AdminKeywordApiServerController::class, 'testWebsite'])->name('keyword-servers.test-website');

    // Artisan commands reference — operator-facing docs for every
    // `ebq:*` console command. Read-only; documentation lives in
    // ArtisanCommandsController::CATALOG, signatures come live from
    // Artisan::all() so the page can't drift from `php artisan list`.
    Route::get('/commands', [AdminArtisanCommandsController::class, 'index'])->name('commands.index');

    // Consolidated platform settings — AI model, rank-tracker default
    // re-check interval, and the Keywords Everywhere competitor-data toggle,
    // all on one page.
    Route::get('/settings', [AdminPlatformSettingsController::class, 'edit'])
        ->name('settings');
    Route::put('/settings', [AdminPlatformSettingsController::class, 'update'])
        ->name('settings.update');
    Route::post('/settings/refresh-models', [AdminPlatformSettingsController::class, 'refreshModels'])
        ->name('settings.refresh-models');
});

Route::middleware('auth')->post('/admin/impersonation/stop', [ClientImpersonationController::class, 'stop'])->name('admin.impersonation.stop');
