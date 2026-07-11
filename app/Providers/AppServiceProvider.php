<?php

namespace App\Providers;

use App\Listeners\RecordGrowthReportSent;
use App\Models\RankTrackingKeyword;
use App\Models\Website;
use App\Observers\RankTrackingKeywordObserver;
use App\Policies\WebsitePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        require_once base_path('app/Support/helpers.php');

        $this->app->singleton(\App\Services\LanguageDetectorService::class);

        // Sharding: per-request/job routing state for the tenant/crawl tiers.
        $this->app->singleton(\App\Support\ShardContext::class);

        // Cashier 16+ does NOT auto-load its own migrations — they're
        // only made available via `vendor:publish --tag=cashier-migrations`.
        // We've manually copied the subscription / subscription-item /
        // meter migrations we need into `database/migrations/` (skipping
        // the customer_columns one because we have our own version on
        // `websites`). So no migration-skipping API call is needed here.

        // LLM client — provider (Mistral/DeepSeek) and model both come
        // from admin settings via the factory. Deliberately bind(), not
        // singleton(): resolution happens per request, so an admin
        // flipping the provider or model takes effect on the next
        // request without a deploy. Per-call `model` overrides on
        // individual LLM calls still win — this is just the
        // platform-wide fallback.
        $this->app->bind(
            \App\Services\Llm\LlmClient::class,
            fn () => \App\Services\Llm\LlmClientFactory::make(),
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sharding: turn every active db_nodes row into a live `node:{id}`
        // connection. Degrades to a no-op before the table exists (install /
        // migrate) or if the DB is unreachable, so it never blocks boot.
        $this->app->make(\App\Support\ShardManager::class)->register();

        Gate::policy(Website::class, WebsitePolicy::class);

        RankTrackingKeyword::observe(RankTrackingKeywordObserver::class);

        Event::listen(MessageSent::class, RecordGrowthReportSent::class);

        // Register the Microsoft Socialite provider's event subscriber so
        // Socialite::driver('microsoft') resolves. The package ships the
        // subscriber separately to keep core Socialite lean.
        // Listener must be the plain "Class@method" string. Wrapping it in
        // an array makes the dispatcher read it as [class, method] and the
        // single element leaves method undefined → "Undefined array key 1"
        // the moment SocialiteWasCalled fires (e.g. during package:discover).
        Event::listen(
            \SocialiteProviders\Manager\SocialiteWasCalled::class,
            \SocialiteProviders\Microsoft\MicrosoftExtendSocialite::class.'@handle',
        );

        RateLimiter::for('oauth', function (Request $request) {
            return Limit::perMinute(20)->by($request->user()?->id ?: $request->ip());
        });

        // Cashier's billable model is App\Models\User — the per-user
        // billing migration in 2026_05_02 moved the Cashier columns
        // from `websites` to `users` and rebuilt `subscriptions` with
        // `user_id`. Cashier's default customer model is User, so no
        // explicit `useCustomerModel()` call is needed. Calling it
        // with Website would point relationship lookups at the wrong
        // table and break $subscription->owner.

        // Per-box fleet queue metrics: only worker boxes carry a FLEET_NODE_ID
        // (config/fleet.php), so the web box no-ops. Each box maintains its own
        // in-flight / finished / failed counters in the shared Redis, which the
        // crawler fleet page reads back per node. See App\Support\FleetMetrics.
        if ($nodeId = config('fleet.node_id')) {
            Queue::before(fn (\Illuminate\Queue\Events\JobProcessing $e) => \App\Support\FleetMetrics::onProcessing($nodeId));
            Queue::after(fn (\Illuminate\Queue\Events\JobProcessed $e) => \App\Support\FleetMetrics::onProcessed($nodeId));
            // Fires when an attempt throws (before a retry-release OR a permanent fail) —
            // decrements the in-flight gauge so retries don't leak it.
            Queue::exceptionOccurred(fn (\Illuminate\Queue\Events\JobExceptionOccurred $e) => \App\Support\FleetMetrics::onException($nodeId));
            Queue::failing(fn (\Illuminate\Queue\Events\JobFailed $e) => \App\Support\FleetMetrics::onFailed($nodeId));
        }

        // EVERY box (web + pinned worker + ephemeral fleet): buffer permanent job
        // failures into shared Redis for the admin digest (`ebq:failed-jobs-alert`).
        // Deliberately outside the fleet gate above — the 2026-07-06 incident was
        // crawl jobs failing silently on the worker for 3 days, visible only in
        // failed_jobs, which nobody watches.
        Queue::failing(fn (\Illuminate\Queue\Events\JobFailed $e) => \App\Support\FailedJobAlertBuffer::record($e));
    }
}
