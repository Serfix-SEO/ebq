<?php

namespace Serfix\ContentAi;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Serfix\ContentAi\Console\InstallCommand;
use Serfix\ContentAi\Console\VerifyCommand;
use Serfix\ContentAi\Http\Controllers\ArticleController;
use Serfix\ContentAi\Http\Controllers\WebhookController;
use Serfix\ContentAi\Http\Middleware\VerifyWebhookSignature;
use Serfix\ContentAi\Rendering\LazyChunk;
use Serfix\ContentAi\Rendering\Renderer;

class ContentAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/content-ai.php', 'content-ai');

        // One instance per request: it holds the "current article", which is
        // what lets the global Blade variables know what to render.
        $this->app->singleton(Renderer::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'content-ai');

        $this->registerRoutes();
        $this->registerBladeGlobals();

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

    /**
     * Global Blade variables + directives, so a host can drop our output into
     * their own design instead of adopting our views:
     *
     *   {!! $serfix_head !!}  {!! $serfix_body !!}  {!! $serfix_body_below !!}
     *
     *   @serfixHead           @serfixBody           @serfixBodyBelow
     *
     * Shared with EVERY view (via `*`), which is only safe because each value
     * is a LazyChunk — it renders at echo time and yields '' when the request
     * is not showing an article. That means these can live in a global layout.
     */
    private function registerBladeGlobals(): void
    {
        if (! config('content-ai.render.globals', true)) {
            return;
        }

        View::composer('*', function ($view) {
            $renderer = $this->app->make(Renderer::class);

            $view->with([
                'serfix_head' => new LazyChunk(fn () => $renderer->head()),
                'serfix_body' => new LazyChunk(fn () => $renderer->body()),
                'serfix_body_below' => new LazyChunk(fn () => $renderer->bodyBelow()),
                'serfix_article' => $renderer->current(),
            ]);
        });

        Blade::directive('serfixHead', fn ($e) => "<?php echo serfix_head({$e}); ?>");
        Blade::directive('serfixBody', fn ($e) => "<?php echo serfix_body({$e}); ?>");
        Blade::directive('serfixBodyBelow', fn ($e) => "<?php echo serfix_body_below({$e}); ?>");
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
