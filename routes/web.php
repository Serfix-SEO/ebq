<?php

use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\GuestAuditController;
use App\Http\Controllers\GuestPageSpeedController;
use App\Http\Controllers\GuestRankCheckController;
use App\Http\Controllers\GuestKeywordVolumeController;
use App\Http\Controllers\GoogleCapController;
use App\Http\Controllers\MicrosoftOAuthController;
use App\Http\Controllers\PageAuditController;
use App\Http\Controllers\Admin\ActivityController as AdminActivityController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\ClientImpersonationController;
use App\Http\Controllers\Admin\PluginAdoptionController as AdminPluginAdoptionController;
use App\Http\Controllers\Admin\PluginReleaseController as AdminPluginReleaseController;
use App\Http\Controllers\Admin\UsageController as AdminUsageController;
use App\Http\Controllers\Admin\WebsiteFeatureController as AdminWebsiteFeatureController;
use App\Http\Controllers\Admin\BillingController as AdminBillingController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\ArtisanCommandsController as AdminArtisanCommandsController;
use App\Http\Controllers\Admin\PlatformSettingsController as AdminPlatformSettingsController;
use App\Http\Controllers\Admin\KeywordApiServerController as AdminKeywordApiServerController;
use App\Http\Controllers\Admin\MarketingController as AdminMarketingController;
use App\Http\Controllers\WordPressConnectController;
use App\Http\Controllers\WordPressEmbedController;
use App\Http\Controllers\WordPressPluginDownloadController;
use App\Http\Controllers\WordPressPluginVersionController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing')->name('landing');
Route::view('/features', 'features')->name('features');
Route::view('/wordpress-plugin', 'wordpress-plugin')->name('wordpress-plugin');
Route::view('/pricing', 'pricing')->name('pricing');
Route::view('/website-revamp', 'website-revamp')->name('website-revamp');
Route::view('/contact', 'contact')->name('contact');
Route::view('/terms-conditions', 'legal.terms')->name('terms-conditions');
Route::view('/privacy-policy', 'legal.privacy')->name('privacy-policy');
Route::view('/refund-policy', 'legal.refund-policy')->name('refund-policy');
Route::view('/guide', 'guide')->name('guide');

// Public, no-signup SEO audit launched from the landing-page hero. Anonymous —
// no GSC/GA, no paid SERP/CWV — and rate-limited + reCAPTCHA-gated in the
// controller. POST keeps default `web` CSRF protection (the form ships a token).
Route::post('/audit', [GuestAuditController::class, 'store'])->name('guest-audit.store');
Route::get('/audit/{guestPageAudit}/status', [GuestAuditController::class, 'status'])->name('guest-audit.status');
Route::get('/audit/{guestPageAudit}', [GuestAuditController::class, 'show'])->name('guest-audit.show');

// Dedicated public SEO-audit tool page (same flow as the landing hero).
Route::view('/free-audit', 'tools.audit')->name('tools.audit');

// Public, no-signup PageSpeed test tool — same progressive friction as the
// guest audit (1st free on-screen, 2nd by email, 3rd → signup). Runs the
// self-hosted Lighthouse; rate-limited + reCAPTCHA-gated in the controller.
Route::view('/pagespeed-test', 'tools.page-speed')->name('tools.pagespeed');
Route::post('/pagespeed-test', [GuestPageSpeedController::class, 'store'])->name('guest-pagespeed.store');
Route::get('/pagespeed-test/{guestPageSpeed}/status', [GuestPageSpeedController::class, 'status'])->name('guest-pagespeed.status');
Route::get('/pagespeed-test/{guestPageSpeed}', [GuestPageSpeedController::class, 'show'])->name('guest-pagespeed.show');

// Public, no-signup keyword rank tracker — same progressive friction as the
// guest audit / PageSpeed test (1st free on-screen, 2nd by email, 3rd → signup).
// Runs a single Serper organic lookup; rate-limited + reCAPTCHA-gated in the controller.
Route::view('/rank-tracker', 'tools.rank-tracker')->name('tools.rank-tracker');
Route::post('/rank-tracker', [GuestRankCheckController::class, 'store'])->name('guest-rank.store');
Route::get('/rank-tracker/{guestRankCheck}/status', [GuestRankCheckController::class, 'status'])->name('guest-rank.status');
Route::get('/rank-tracker/{guestRankCheck}', [GuestRankCheckController::class, 'show'])->name('guest-rank.show');

// Public, no-signup keyword search-volume finder — same progressive friction
// (1st free on-screen, 2nd by email, 3rd → signup). One keyword per check;
// DB-first against the shared keyword_metrics cache, so it only calls Keywords
// Everywhere on a cache miss. Rate-limited + reCAPTCHA-gated in the controller.
// Distinct public path so it doesn't collide with the authenticated portal
// finder at /keyword-volume (mirrors /pagespeed vs /pagespeed-test).
Route::view('/keyword-volume-checker', 'tools.keyword-volume')->name('tools.keyword-volume');
Route::post('/keyword-volume-checker', [GuestKeywordVolumeController::class, 'store'])->name('guest-volume.store');
Route::get('/keyword-volume-checker/{guestKeywordVolume}/status', [GuestKeywordVolumeController::class, 'status'])->name('guest-volume.status');
Route::get('/keyword-volume-checker/{guestKeywordVolume}', [GuestKeywordVolumeController::class, 'show'])->name('guest-volume.show');

// Always-fresh download of the latest packaged WP plugin — bypasses public/ caching.
Route::get('/wordpress/plugin.zip', WordPressPluginDownloadController::class)->name('wordpress.plugin.download');
Route::get('/wordpress/plugin/version', WordPressPluginVersionController::class)->name('wordpress.plugin.version');

// Public pricing API — anonymous, drives the WordPress plugin's setup
// wizard pricing step + any external integration that needs the plan
// list. No identifier captured (matches /wordpress/plugin/version).
Route::get('/api/v1/plans', [\App\Http\Controllers\Api\V1\PricingController::class, 'public'])
    ->name('api.v1.plans');

// Stripe webhook — extends Cashier's controller. CSRF-exempted in
// bootstrap/app.php. Stripe signs the request body; the controller
// verifies via STRIPE_WEBHOOK_SECRET so no extra auth needed.
Route::post('/stripe/webhook', [\App\Http\Controllers\StripeWebhookController::class, 'handleWebhook'])
    ->name('cashier.webhook');

// Self-hosted keyword API result callback. Server-to-server; CSRF-exempted in
// bootstrap/app.php. The body is HMAC-signed with the originating server's
// webhook_secret — the controller verifies it.
Route::post('/webhooks/keyword-finder', \App\Http\Controllers\Webhooks\KeywordFinderWebhookController::class)
    ->name('webhooks.keyword-finder');

// Billing — Stripe Checkout + Customer Portal redirects. Auth required
// because we need the user context to resolve which Website is being
// billed. The /checkout endpoint mints a Stripe Hosted Checkout session
// and redirects; /success and /cancel handle the post-checkout return.
Route::middleware(['web', 'auth'])->group(function (): void {
    // Subscription management page — current plan, plan grid, cancel,
    // resume, recent invoices. Lives at /billing under sidebar nav.
    Route::get('/billing', [\App\Http\Controllers\BillingController::class, 'show'])
        ->name('billing.show');
    Route::post('/billing/swap', [\App\Http\Controllers\BillingController::class, 'swap'])
        ->name('billing.swap');
    Route::post('/billing/cancel', [\App\Http\Controllers\BillingController::class, 'cancelSubscription'])
        ->name('billing.cancel-subscription');
    Route::post('/billing/resume', [\App\Http\Controllers\BillingController::class, 'resume'])
        ->name('billing.resume');

    // Stripe Hosted Checkout flow — first-time purchase or post-cancel
    // re-subscribe. swap() is preferred for active subscribers.
    Route::get('/billing/checkout', [\App\Http\Controllers\BillingController::class, 'checkout'])
        ->name('billing.checkout');
    Route::get('/billing/success', [\App\Http\Controllers\BillingController::class, 'success'])
        ->name('billing.success');
    // Renamed from `billing.cancel` to free that name for the
    // subscription-cancel POST above. This handles "user backed out
    // of Stripe Checkout" only, not in-app cancellation.
    Route::get('/billing/cancel-checkout', [\App\Http\Controllers\BillingController::class, 'cancel'])
        ->name('billing.cancel-checkout');
    Route::get('/billing/portal', [\App\Http\Controllers\BillingController::class, 'portal'])
        ->name('billing.portal');
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

Route::middleware(['auth', 'verified', 'onboarded'])->group(function () {
    Route::view('/dashboard', 'dashboard')->middleware('feature:dashboard')->name('dashboard');
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
    Route::redirect('/competitive', '/keyword-research?tab=gap')->name('competitive.index');
    // Competitor auto-discovery — reachable from the Gap tab.
    Route::view('/competitive/competitors', 'competitive.competitors')->middleware('feature:keywords')->name('competitive.competitors');
    Route::view('/rank-tracking', 'rank-tracking.index')->middleware('feature:rank_tracking')->name('rank-tracking.index');
    Route::get('/rank-tracking/{keywordId}', fn (int $keywordId) => view('rank-tracking.show', ['keywordId' => $keywordId]))
        ->whereNumber('keywordId')
        ->middleware('feature:rank_tracking')
        ->name('rank-tracking.show');
    Route::view('/backlinks', 'backlinks.index')->middleware('feature:backlinks')->name('backlinks.index');
    Route::view('/pages', 'pages.index')->middleware('feature:pages')->name('pages.index');
    Route::view('/custom-audit', 'pages.custom-audit')->middleware('feature:audits')->name('custom-audit.index');
    Route::view('/pagespeed', 'pages.page-speed')->middleware('feature:audits')->name('pagespeed.index');
    Route::get('/pages/{id}', fn (string $id) => view('pages.show', ['pageUrl' => $id]))
        ->middleware('feature:pages')
        ->name('pages.show')->where('id', '.*');
    Route::view('/sitemaps', 'sitemaps.index')->middleware('feature:sitemaps')->name('sitemaps.index');
    Route::view('/link-structure', 'link-structure.index')->middleware('feature:link_structure')->name('link-structure.index');
    Route::get('/page-audits/{pageAuditReport}', [PageAuditController::class, 'show'])
        ->middleware('feature:audits')
        ->name('page-audits.show');
    Route::get('/page-audits/{id}/download', [PageAuditController::class, 'download'])
        ->middleware(['feature:audits', 'throttle:30,1'])
        ->name('page-audits.download');
    Route::view('/websites', 'websites.index')->name('websites.index');
    Route::view('/team', 'team.index')->middleware('feature:team')->name('team.index');
    Route::view('/reports', 'reports.index')->middleware('feature:reports')->name('reports.index');
    Route::view('/settings', 'settings.index')->middleware('feature:settings')->name('settings.index');

    Route::middleware('feature:ai_studio')->group(function (): void {
        Route::get('/ai-studio', [\App\Http\Controllers\AiStudioController::class, 'index'])
            ->name('ai-studio.index');
        Route::post('/ai-studio/tools/{toolId}/run', [\App\Http\Controllers\AiStudioController::class, 'run'])
            ->where('toolId', '[a-z0-9\-]+')
            ->middleware('throttle:30,1')
            ->name('ai-studio.run');
        Route::put('/ai-studio/brand-voice', [\App\Http\Controllers\AiStudioController::class, 'brandVoiceUpdate'])
            ->middleware('throttle:10,1')
            ->name('ai-studio.brand-voice.update');
        Route::delete('/ai-studio/brand-voice', [\App\Http\Controllers\AiStudioController::class, 'brandVoiceDestroy'])
            ->name('ai-studio.brand-voice.destroy');

        // Blog Post Wizard — the dashboard re-implementation of the WP
        // plugin's multi-step AI Writer. The marker tool's launcher card
        // links here instead of running the generic tool form. All state
        // mutations delegate to WriterProjectService via
        // AiStudioWriterController (session-resolved website).
        Route::get('/ai-studio/blog-post-wizard', [\App\Http\Controllers\AiStudioWriterController::class, 'page'])
            ->name('ai-studio.wizard');

        Route::prefix('ai-studio/writer-projects')->name('ai-studio.writer-projects.')->group(function (): void {
            Route::get('/', [\App\Http\Controllers\AiStudioWriterController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\AiStudioWriterController::class, 'store'])->name('store');
            Route::get('/{externalId}', [\App\Http\Controllers\AiStudioWriterController::class, 'show'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('show');
            Route::patch('/{externalId}', [\App\Http\Controllers\AiStudioWriterController::class, 'update'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('update');
            Route::delete('/{externalId}', [\App\Http\Controllers\AiStudioWriterController::class, 'destroy'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('destroy');
            Route::post('/{externalId}/brief', [\App\Http\Controllers\AiStudioWriterController::class, 'generateBrief'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('brief');
            Route::post('/{externalId}/brief/chat', [\App\Http\Controllers\AiStudioWriterController::class, 'briefChat'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('brief.chat');
            Route::post('/{externalId}/images/search', [\App\Http\Controllers\AiStudioWriterController::class, 'searchImages'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('images.search');
            Route::post('/{externalId}/strategy', [\App\Http\Controllers\AiStudioWriterController::class, 'strategy'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('strategy');
            Route::post('/{externalId}/generate', [\App\Http\Controllers\AiStudioWriterController::class, 'generate'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('generate');
            Route::get('/{externalId}/credits', [\App\Http\Controllers\AiStudioWriterController::class, 'credits'])
                ->where('externalId', '[A-Za-z0-9\-]+')->name('credits');
        });

        Route::get('/ai-studio/ai-writer-prompts', [\App\Http\Controllers\AiStudioWriterController::class, 'promptsIndex'])
            ->name('ai-studio.prompts.index');
        Route::post('/ai-studio/ai-writer-prompts', [\App\Http\Controllers\AiStudioWriterController::class, 'promptsStore'])
            ->middleware('throttle:20,1')->name('ai-studio.prompts.store');
        Route::delete('/ai-studio/ai-writer-prompts/{externalId}', [\App\Http\Controllers\AiStudioWriterController::class, 'promptsDestroy'])
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
});

// Google Cross-Account Protection (RISC/CAP) receiver endpoint.
Route::post('/auth/google/cap/events', GoogleCapController::class)
    ->middleware(['throttle:oauth'])
    ->name('google.cap.events');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/clients', [AdminClientController::class, 'index'])->name('clients.index');
    Route::post('/clients', [AdminClientController::class, 'store'])->name('clients.store');
    Route::post('/clients/bulk', [AdminClientController::class, 'bulk'])->name('clients.bulk');
    Route::put('/clients/{user}', [AdminClientController::class, 'update'])->name('clients.update');
    Route::post('/clients/{user}/crawl', [AdminClientController::class, 'crawl'])->name('clients.crawl');
    Route::post('/clients/{user}/impersonate', [ClientImpersonationController::class, 'start'])->name('clients.impersonate');

    Route::view('/docs/site-crawler', 'admin.docs.crawler')->name('docs.crawler');

    Route::view('/proxies', 'admin.proxies')->name('proxies.index');

    Route::get('/activities', [AdminActivityController::class, 'index'])->name('activities.index');

    Route::get('/crawler', [\App\Http\Controllers\Admin\CrawlerController::class, 'index'])->name('crawler.index');

    Route::get('/fleet', [\App\Http\Controllers\Admin\FleetController::class, 'index'])->name('fleet.index');
    Route::post('/fleet/settings', [\App\Http\Controllers\Admin\FleetController::class, 'settings'])->name('fleet.settings');
    Route::post('/fleet/provision', [\App\Http\Controllers\Admin\FleetController::class, 'provision'])->name('fleet.provision');
    Route::post('/fleet/reconcile', [\App\Http\Controllers\Admin\FleetController::class, 'reconcile'])->name('fleet.reconcile');
    Route::post('/fleet/{node}/drain', [\App\Http\Controllers\Admin\FleetController::class, 'drain'])->name('fleet.drain');
    Route::post('/fleet/{node}/destroy', [\App\Http\Controllers\Admin\FleetController::class, 'destroy'])->name('fleet.destroy');

    Route::get('/marketing', [AdminMarketingController::class, 'index'])->name('marketing.index');
    Route::get('/marketing/sends', [AdminMarketingController::class, 'sends'])->name('marketing.sends');
    Route::post('/marketing/{website}/send', [AdminMarketingController::class, 'send'])->name('marketing.send');

    Route::get('/leads', [\App\Http\Controllers\Admin\LeadController::class, 'index'])->name('leads.index');
    Route::get('/usage', [AdminUsageController::class, 'index'])->name('usage.index');
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
