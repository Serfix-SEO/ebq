<?php

namespace App\Jobs;

use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Services\Content\ContentLlmSpendMeter;
use App\Services\Content\IdeogramClient;
use App\Services\Content\IdeogramSpendMeter;
use App\Services\Llm\LlmClientFactory;
use App\Support\ContentAutopilotConfig;
use App\Support\Queues;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Content Autopilot Phase 4: generate a featured image + a few inline images
 * for a READY article via Ideogram, and inject them into the article HTML.
 *
 * Runs ASYNC after the article is ready (chained from ProduceContentArticleJob)
 * — it must NEVER block publishing. Every failure mode degrades to "article
 * without images": Ideogram off/unconfigured, monthly cap hit, a generation
 * or download error, or the article changing out from under us. tries=1
 * (images bill real money; retries would double-charge — a lost run just
 * means no images, which is fine).
 *
 * Alt text is deliberately keyphrase-driven: the featured alt uses the focus
 * keyphrase and each inline alt uses one of the additional keyphrases, so the
 * images also raise the on-page topical-coverage signal (its image-alt bonus)
 * rather than being decorative.
 */
class GenerateContentImagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $articleId)
    {
        $this->onQueue(Queues::CONTENT);
    }

    public function uniqueId(): string
    {
        return $this->articleId;
    }

    public function handle(IdeogramClient $ideogram, IdeogramSpendMeter $meter): void
    {
        if (! ContentAutopilotConfig::imagesEnabled() || ! $ideogram->isConfigured() || $meter->exhausted()) {
            return;
        }

        $article = ContentArticle::query()->with('topic.plan')->find($this->articleId);
        if ($article === null || ! $article->is_current || $article->images()->where('status', ContentImage::STATUS_GENERATED)->exists()) {
            return; // gone, superseded, or already has images
        }

        $topic = $article->topic;
        $plan = $topic?->plan;

        // Per-plan opt-out: the client disabled images in onboarding/settings.
        // (The global ContentAutopilotConfig::imagesEnabled() above is the
        //  platform kill-switch; this is the client's own choice.)
        if ($plan !== null && $plan->images_enabled === false) {
            return;
        }

        $focus = trim((string) ($topic?->target_keyword ?? ''));
        $additional = array_values(array_filter(array_map(
            static fn ($k): string => trim((string) $k),
            (array) ($topic?->secondary_keywords ?? [])
        )));
        // Style: the picked category's art-direction prompt, plus any free-text
        // override the plan carries, and the category's matching Ideogram type.
        $stylePrompt = trim(implode('. ', array_filter([
            \App\Support\ContentImageStyles::prompt($plan?->image_style),
            trim((string) ($plan?->image_style_prompt ?? '')),
        ])));
        $speed = ContentAutopilotConfig::renderingSpeed();
        $style = \App\Support\ContentImageStyles::isValid($plan?->image_style)
            ? \App\Support\ContentImageStyles::ideogramStyle($plan->image_style)
            : ContentAutopilotConfig::styleType();

        // Tell the review page that images are actively being generated so its
        // progress overlay stays up until every image is done. Image rows are
        // created only on success, so there is no in-progress row to detect —
        // this flag (cleared in the finally below) is the signal. TTL bounds it
        // so a crashed worker can't pin the overlay forever.
        $imagesFlag = 'content:images:running:'.($topic?->id ?? $article->id);
        \Illuminate\Support\Facades\Cache::put($imagesFlag, 1, now()->addMinutes(10));

        try {
        $html = (string) $article->html;
        $anchors = $this->sectionAnchors($html, ContentAutopilotConfig::maxInlineImages());

        // Ask an LLM to write art-directed, content-aware prompts (works for
        // any niche). Falls back to a deterministic prompt per item if the
        // LLM is unavailable or omits one.
        $llmPrompts = $this->llmPrompts($article, $topic, $anchors, $stylePrompt);

        // Build the work list: featured first, then one per section anchor.
        $jobs = [];
        if (ContentAutopilotConfig::featuredImageEnabled()) {
            $jobs[] = [
                'role' => ContentImage::ROLE_FEATURED,
                'anchor' => null,
                'prompt' => $llmPrompts['featured'] ?? $this->prompt((string) $article->h1, $stylePrompt),
                'alt' => $focus !== '' ? Str::ucfirst($focus) : (string) $article->h1,
                'aspect' => '16x9',
            ];
        }
        foreach ($anchors as $i => $anchor) {
            $jobs[] = [
                'role' => ContentImage::ROLE_INLINE,
                'anchor' => $anchor['id'],
                'prompt' => $llmPrompts['inline'][$anchor['id']] ?? $this->prompt($anchor['text'].' — '.$article->h1, $stylePrompt),
                // Weave an additional keyphrase into the alt for topical coverage.
                'alt' => ($additional[$i] ?? $anchor['text']),
                'aspect' => '16x9',
            ];
        }

        $inlineInjections = [];
        $featuredImage = null;

        foreach ($jobs as $spec) {
            if ($meter->exhausted()) {
                break;
            }
            $result = $ideogram->generate($spec['prompt'], [
                'aspect_ratio' => $spec['aspect'],
                'rendering_speed' => $speed,
                'style_type' => $style,
                'num_images' => 1,
            ]);
            if (! ($result['ok'] ?? false) || empty($result['images'][0]['url'])) {
                continue;
            }
            $meter->add((float) ($result['cost_usd'] ?? $ideogram->costPerImage($speed)));

            $bytes = $ideogram->download((string) $result['images'][0]['url']);
            if ($bytes === null || $bytes === '') {
                continue;
            }

            $filename = Str::ulid()->toBase32().'.png';
            $path = 'content/images/'.$filename;
            // Store on the configured disk (local 'public' or Hetzner S3) with
            // public visibility so the preview + WP sideload can fetch it.
            Storage::disk(ContentImage::disk())->put($path, $bytes, 'public');

            $image = ContentImage::query()->create([
                'article_id' => $article->id,
                'role' => $spec['role'],
                'section_anchor' => $spec['anchor'],
                'prompt' => $spec['prompt'],
                'params' => ['rendering_speed' => $speed, 'style_type' => $style, 'aspect_ratio' => $spec['aspect'],
                    'seed' => $result['images'][0]['seed'] ?? null, 'resolution' => $result['images'][0]['resolution'] ?? null],
                'disk_path' => $path,
                'bytes' => strlen($bytes),
                'alt_text' => mb_substr($spec['alt'], 0, 300),
                'filename' => $filename,
                'cost_usd' => (float) ($result['cost_usd'] ?? 0),
                'status' => ContentImage::STATUS_GENERATED,
            ]);

            $url = $image->url();
            $tag = $this->figure($url, $spec['alt']);
            if ($spec['role'] === ContentImage::ROLE_FEATURED) {
                $featuredImage = $tag;
            } else {
                $inlineInjections[$spec['anchor']] = $tag;
            }
        }

        if ($featuredImage === null && $inlineInjections === []) {
            return; // nothing generated — leave the article untouched
        }

        $article->forceFill(['html' => $this->inject($html, $featuredImage, $inlineInjections)])->saveQuietly();

        Log::info('content_autopilot.images_generated', [
            'article_id' => $article->id,
            'featured' => $featuredImage !== null,
            'inline' => count($inlineInjections),
            'spent' => $meter->spent(),
        ]);
        } finally {
            \Illuminate\Support\Facades\Cache::forget($imagesFlag);
        }
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * First N H2 anchors (id + text) — the inline-image targets.
     *
     * @return list<array{id:string, text:string}>
     */
    private function sectionAnchors(string $html, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }
        preg_match_all('/<h2\b[^>]*\bid="([^"]+)"[^>]*>(.*?)<\/h2>/is', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $text = trim(html_entity_decode(strip_tags($row[2])));
            // Skip boilerplate sections that shouldn't carry a decorative image.
            if (preg_match('/faq|frequently asked|key takeaways/i', $text)) {
                continue;
            }
            $out[] = ['id' => $row[1], 'text' => $text];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * One LLM call to art-direct the images: a description of the ideal photo
     * for the article as a whole (featured) and for each illustrated section,
     * tailored to the actual topic + business — niche-agnostic (works for a
     * law firm, recipe blog, SaaS, or a gaming site). Returns null-safe maps;
     * missing keys fall back to the deterministic prompt. Never throws.
     *
     * @param  list<array{id:string, text:string}>  $anchors
     * @return array{featured?:string, inline?:array<string,string>}
     */
    private function llmPrompts(ContentArticle $article, $topic, array $anchors, string $stylePrompt): array
    {
        try {
            $model = ContentAutopilotConfig::modelFor('image_prompts');
            $llm = LlmClientFactory::make($model['provider']);
            if (! $llm->isAvailable()) {
                return [];
            }

            $plan = $topic?->plan;
            $business = trim((string) ($plan?->business_description ?? ''));
            $sections = array_map(static fn ($a) => ['id' => $a['id'], 'heading' => $a['text']], $anchors);

            $system = 'You are an art director writing image-generation prompts for a blog article. '
                .'Return ONLY JSON. For each prompt, describe a single, specific, editorial-quality image that '
                .'visually represents the actual content — concrete scene, subject, mood, lighting, composition, and style. '
                .'Adapt to the business/topic (photoreal for real-world businesses, illustration/graphic for digital/gaming/abstract topics). '
                .'The FEATURED (hero) prompt may include the article title as a bold, correctly-spelled text overlay if it suits the theme. '
                .'Inline prompts must NOT contain text overlays. Never include logos, watermarks, real brand marks, celebrities, or anything offensive. '
                .'Keep each prompt 1-3 sentences.'
                .($stylePrompt !== '' ? ' Preferred visual style: '.$stylePrompt.'.' : '');

            $user = json_encode([
                'article_title' => (string) $article->h1,
                'summary' => (string) ($article->meta_description ?? ''),
                'business' => $business !== '' ? mb_substr($business, 0, 400) : null,
                'sections_needing_images' => $sections,
                'output_shape' => ['featured' => 'string prompt', 'inline' => ['<section id>' => 'string prompt']],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $options = ['temperature' => 0.7, '__user_id' => $topic?->website?->user_id, '__source' => 'content_autopilot.image_prompts'];
            if (! empty($model['model'])) {
                $options['model'] = $model['model'];
            }

            $resp = $llm->completeJson([
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ], $options);
            app(ContentLlmSpendMeter::class)->add(ContentLlmSpendMeter::EST_IDEATE_USD);

            if (! is_array($resp)) {
                return [];
            }

            $out = [];
            if (is_string($resp['featured'] ?? null) && trim($resp['featured']) !== '') {
                $out['featured'] = trim($resp['featured']);
            }
            $inline = [];
            foreach ((array) ($resp['inline'] ?? []) as $id => $prompt) {
                if (is_string($prompt) && trim($prompt) !== '') {
                    $inline[(string) $id] = trim($prompt);
                }
            }
            if ($inline !== []) {
                $out['inline'] = $inline;
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('content_autopilot.image_prompts_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function prompt(string $subject, string $stylePrompt): string
    {
        $subject = trim(preg_replace('/\s+/', ' ', $subject));
        $base = "Editorial blog illustration for an article about \"{$subject}\". Clean, modern, high quality, no text or watermarks.";

        return $stylePrompt !== '' ? $base.' Style: '.$stylePrompt : $base;
    }

    private function figure(?string $url, string $alt): string
    {
        if ($url === null) {
            return '';
        }

        return '<figure class="content-image"><img src="'.e($url).'" alt="'.e($alt).'" loading="lazy" /></figure>';
    }

    /**
     * Featured image before the first content (after any TOC nav); each inline
     * figure immediately after its section's <h2>.
     *
     * @param  array<string,string>  $inline  anchor id => figure html
     */
    private function inject(string $html, ?string $featured, array $inline): string
    {
        foreach ($inline as $anchorId => $figure) {
            $html = preg_replace(
                '/(<h2\b[^>]*\bid="'.preg_quote($anchorId, '/').'"[^>]*>.*?<\/h2>)/is',
                '$1'.$figure,
                $html,
                1
            ) ?? $html;
        }

        if ($featured !== null) {
            // After a leading TOC nav if present, else at the very top.
            if (preg_match('/^(\s*<nav\b[^>]*class="[^"]*content-toc[^"]*"[^>]*>.*?<\/nav>)/is', $html, $m)) {
                $html = $m[1].$featured.mb_substr($html, mb_strlen($m[1]));
            } else {
                $html = $featured.$html;
            }
        }

        return $html;
    }
}
