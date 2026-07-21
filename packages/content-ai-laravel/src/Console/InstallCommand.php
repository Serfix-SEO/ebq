<?php

namespace Serfix\ContentAi\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class InstallCommand extends Command
{
    protected $signature = 'content-ai:install {--force : Overwrite an existing config file}';

    protected $description = 'Publish the Content AI config/migrations and generate a webhook secret';

    public function handle(): int
    {
        $this->callSilent('vendor:publish', [
            '--tag' => 'content-ai-config',
            '--force' => (bool) $this->option('force'),
        ]);
        $this->components->info('Published config/content-ai.php');

        $secret = Str::random(48);

        $this->newLine();
        $this->components->info('Add this to your .env:');
        $this->line('  CONTENT_AI_WEBHOOK_SECRET='.$secret);
        $this->newLine();

        $this->components->info('Then, in Content Autopilot → Connect publishing, choose "Webhook" and enter:');
        $this->line('  Endpoint URL: '.url((string) config('content-ai.webhook.path', 'serfix/content-ai/webhook')));
        $this->line('  Signing secret: the value above (identical on both sides)');
        $this->newLine();

        $this->components->warn('Run `php artisan migrate` to create the article tables.');
        $this->components->warn('Using the `public` image disk? Run `php artisan storage:link` too.');

        return self::SUCCESS;
    }
}
