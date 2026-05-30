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

        // Cashier 16+ does NOT auto-load its own migrations — they're
        // only made available via `vendor:publish --tag=cashier-migrations`.
        // We've manually copied the subscription / subscription-item /
        // meter migrations we need into `database/migrations/` (skipping
        // the customer_columns one because we have our own version on
        // `websites`). So no migration-skipping API call is needed here.

        // LLM client — bind the interface to Mistral by default. Per-task
        // services that want a different provider for a specific endpoint
        // (e.g. Claude for copywriting in Phase 2) can take a concrete
        // class via constructor injection instead of the interface.
        $this->app->bind(\App\Services\Llm\LlmClient::class, function () {
            // Model resolution goes through AiModelConfig so the admin's
            // dropdown choice (persisted in `settings.ai.llm.model`)
            // takes precedence over the .env-driven config default.
            // Per-call `model` overrides on individual LLM calls still
            // win — this is just the platform-wide fallback.
            return new \App\Services\Llm\MistralClient(
                (string) config('services.mistral.key', ''),
                \App\Support\AiModelConfig::currentModel(),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
