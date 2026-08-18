<?php

namespace App\Console\Commands;

use App\Jobs\GenerateContentImagesJob;
use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentTopic;
use App\Models\Website;
use App\Services\Content\IdeogramSpendMeter;
use App\Support\ContentAutopilotConfig;
use Illuminate\Console\Command;

/**
 * Re-run image generation for finished articles that ended up with none.
 *
 * Why this exists: `GenerateContentImagesJob` returns early when the monthly
 * Ideogram meter is exhausted, and it creates image rows only on success — so a
 * blown cap produces articles with silently zero images and nothing to retry
 * from. On 2026-08-17 the $10 cap tripped at 00:14 and the next **91 articles
 * across 12 clients** were written imageless; one client (fit-xp.com) signed up
 * inside the blackout and never saw the feature work at all.
 *
 * SPENDS REAL MONEY (~$0.03/image, several per article), so it defaults to a
 * dry run and prints the bill before anything is dispatched.
 *
 *   php artisan ebq:backfill-article-images
 *   php artisan ebq:backfill-article-images --force
 *   php artisan ebq:backfill-article-images --website=fit-xp.com --force
 */
class BackfillArticleImages extends Command
{
    protected $signature = 'ebq:backfill-article-images
        {--force : Actually dispatch (without this the command only reports)}
        {--website= : Limit to one domain}
        {--since= : Only articles created on/after this datetime (default: the 2026-08-17 cap blackout)}
        {--limit=200 : Safety ceiling on articles touched in one run}';

    protected $description = 'Generate images for finished articles that have none';

    public function handle(IdeogramSpendMeter $meter): int
    {
        $force = (bool) $this->option('force');
        $since = (string) ($this->option('since') ?: '2026-08-17 00:14:49');

        if (! ContentAutopilotConfig::imagesEnabled()) {
            $this->error('Images are disabled platform-wide — nothing would generate. Aborting.');

            return self::FAILURE;
        }

        $articles = ContentArticle::query()
            ->where('is_current', true)
            ->where('created_at', '>=', $since)
            // No image rows at all: a partially-imaged article is a different
            // problem and re-running would duplicate what it already has.
            ->whereNotIn('id', ContentImage::query()->select('article_id')->distinct())
            ->orderBy('created_at')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'topic_id', 'h1', 'created_at']);

        $topics = ContentTopic::query()->whereIn('id', $articles->pluck('topic_id'))->get()->keyBy('id');
        $sites = Website::query()->whereIn('id', $topics->pluck('website_id')->unique())->get()->keyBy('id');

        if (($only = trim((string) $this->option('website'))) !== '') {
            $wanted = $sites->first(fn (Website $w) => $w->domain === $only || $w->normalized_domain === $only);
            if ($wanted === null) {
                $this->error('No affected article for website '.$only);

                return self::FAILURE;
            }
            $articles = $articles->filter(fn ($a) => (string) ($topics[$a->topic_id]->website_id ?? '') === (string) $wanted->id);
        }

        // A plan with images switched off must not be "fixed" into generating
        // them — that is the client's own setting, not the outage.
        $skippedByChoice = 0;
        $articles = $articles->filter(function ($a) use ($topics, &$skippedByChoice) {
            $plan = $topics[$a->topic_id]->plan ?? null;
            if ($plan === null || ! $plan->images_enabled) {
                $skippedByChoice++;

                return false;
            }

            return true;
        });

        if ($articles->isEmpty()) {
            $this->info('Nothing to backfill'.($skippedByChoice > 0 ? " ({$skippedByChoice} skipped: images off by client choice)." : '.'));

            return self::SUCCESS;
        }

        $perArticle = 1 + ContentAutopilotConfig::maxInlineImages();
        $estimate = $articles->count() * $perArticle * 0.03;

        $rows = [];
        foreach ($articles->groupBy(fn ($a) => (string) ($topics[$a->topic_id]->website_id ?? '?')) as $wid => $group) {
            $rows[] = [$sites[$wid]->domain ?? $wid, $group->count(), '~$'.number_format($group->count() * $perArticle * 0.03, 2)];
        }
        $this->table(['website', 'articles', 'est. cost'], $rows);

        $this->line('Articles: <info>'.$articles->count().'</info> · up to <info>'.$perArticle.'</info> images each · est. <info>~$'
            .number_format($estimate, 2).'</info>');
        $this->line('Image meter: $'.number_format($meter->spent(), 2)
            .' spent this month, cap '.($meter->cap() === null ? '<info>none</info>' : '$'.number_format($meter->cap(), 2)));

        if ($meter->exhausted()) {
            $this->error('The image meter is EXHAUSTED — every job would return early. Raise or clear the cap first.');

            return self::FAILURE;
        }

        if (! $force) {
            $this->comment('Dry run — nothing dispatched. Re-run with --force to generate.');

            return self::SUCCESS;
        }

        foreach ($articles as $article) {
            GenerateContentImagesJob::dispatch((string) $article->id);
        }

        $this->info('Dispatched '.$articles->count().' image job(s) to the content queue.');
        $this->line('Watch: php artisan ebq:backfill-article-images   (re-run the dry report; the list shrinks as they finish)');

        return self::SUCCESS;
    }
}
