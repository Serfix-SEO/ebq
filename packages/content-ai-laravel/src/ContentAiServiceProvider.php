<?php

namespace Serfix\ContentAi;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Serfix\ContentAi\Console\InstallCommand;
use Serfix\ContentAi\Console\VerifyCommand;
use Serfix\ContentAi\Http\Controllers\ArticleController;
use Serfix\ContentAi\Http\Controllers\WebhookController;
use Serfix\ContentAi\Http\Middleware\VerifyWebhookSignature;

class ContentAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/content-ai.php', 'content-ai');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'content-ai');

        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/content-ai.php' => config_path('content-ai.php'),
            ], 'content-ai-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'content-ai-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/content-ai'),
            ], 'content-ai-views');

            $this->commands([InstallCommand::class, VerifyCommand::class]);
        }
    }

    private function registerRoutes(): void
    {
        // Webhook first and independently of the blog routes: a host that turns
        // off the bundled pages (to render articles in their own app) must still
        // be able to RECEIVE them.
        if (config('content-ai.webhook.enabled', true)) {
            Route::middleware(array_merge(
                (array) config('content-ai.webhook.middleware', ['api']),
                [VerifyWebhookSignature::class]
            ))->post(
                (string) config('content-ai.webhook.path', 'serfix/content-ai/webhook'),
                WebhookController::class
            )->name('content-ai.webhook');
        }

        if (! config('content-ai.route.enabled', true)) {
            return;
        }

        $name = (string) config('content-ai.route.name_prefix', 'content-ai.');
        $prefix = trim((string) config('content-ai.route.prefix', 'blog'), '/');

        Route::group(array_filter([
            'prefix' => $prefix,
            'middleware' => (array) config('content-ai.route.middleware', ['web']),
            'domain' => config('content-ai.route.domain'),
        ]), function () use ($name) {
            Route::get('/', [ArticleController::class, 'index'])->name($name.'index');
            Route::get('feed', [ArticleController::class, 'feed'])->name($name.'feed');
            Route::get('sitemap.xml', [ArticleController::class, 'sitemap'])->name($name.'sitemap');
            // Last: a literal segment above must never be shadowed by a slug.
            Route::get('{slug}', [ArticleController::class, 'show'])->name($name.'show');
        });
    }
}
