<?php

namespace Tests\Feature\Content;

use App\Jobs\GenerateInlineImageJob;
use App\Livewire\Content\ArticleReview;
use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\IdeogramClient;
use App\Services\Content\IdeogramSpendMeter;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImageRegenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Storage::fake('public');
        config([
            'services.ideogram.key' => 'fake-key',
            'services.ideogram.base_url' => 'https://api.ideogram.ai/v1',
            'services.ideogram.monthly_cap_usd' => 10,
        ]);
        \Illuminate\Support\Facades\Redis::connection()->del('ideogram:spend:'.now()->format('Y-m'));
        \Illuminate\Support\Facades\RateLimiter::clear('image-regen:'); // per-test keys differ anyway
    }

    /** @return array{0: User, 1: ContentTopic, 2: ContentArticle, 3: ContentImage} */
    private function fixture(string $status = ContentTopic::STATUS_READY): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'billing_covered_at' => now()]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'T', 'target_keyword' => 'kw', 'status' => $status,
        ]);
        Storage::disk('public')->put('content/images/old.png', 'OLDBYTES');
        $article = ContentArticle::create([
            'topic_id' => $topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D', 'slug' => 'h',
            'html' => '<p>placeholder</p>',
        ]);
        $image = ContentImage::create([
            'article_id' => $article->id, 'role' => ContentImage::ROLE_FEATURED,
            'status' => ContentImage::STATUS_GENERATED, 'prompt' => 'a clean product shot',
            'disk_path' => 'content/images/old.png', 'filename' => 'old.png', 'alt_text' => 'kw',
        ]);
        $article->forceFill([
            'html' => '<figure class="content-image"><img src="'.$image->url().'" alt="kw"></figure><p>Body.</p>',
        ])->save();

        return [$user, $topic, $article, $image];
    }

    public function test_regenerate_creates_pending_row_and_dispatches_with_replace_id(): void
    {
        Queue::fake();
        [$user, $topic, , $image] = $this->fixture();

        Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->call('regenerateImage', $image->id);

        $new = ContentImage::query()
            ->where('status', ContentImage::STATUS_PENDING)
            ->latest()->first();
        $this->assertNotNull($new);
        $newId = $new->id;
        $this->assertSame(ContentImage::STATUS_PENDING, $new->status);
        $this->assertSame(ContentImage::ROLE_FEATURED, $new->role);
        $this->assertSame($image->prompt, $new->prompt);
        $this->assertSame(1, (int) $new->params['regen_count']);
        Queue::assertPushed(GenerateInlineImageJob::class, fn ($j) => $j->imageId === $newId && $j->replaceImageId === $image->id);
    }

    public function test_regenerate_guards_tenancy_uploads_and_lineage_cap(): void
    {
        Queue::fake();
        [$user, $topic, $article, $image] = $this->fixture();

        // Upload (no prompt) → refused.
        $upload = ContentImage::create([
            'article_id' => $article->id, 'role' => ContentImage::ROLE_INLINE,
            'status' => ContentImage::STATUS_GENERATED, 'prompt' => null,
            'params' => ['source' => 'upload'],
        ]);
        $c = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id]);
        $c->call('regenerateImage', $upload->id);
        Queue::assertNothingPushed();

        // Lineage cap: regen_count already 3 → refused.
        $image->forceFill(['params' => ['regen_count' => 3]])->save();
        $c->call('regenerateImage', $image->id);
        Queue::assertNothingPushed();
    }

    public function test_job_swaps_src_in_current_article_and_rejects_old_row(): void
    {
        [, $topic, $article, $old] = $this->fixture();
        $new = ContentImage::create([
            'article_id' => $article->id, 'role' => ContentImage::ROLE_FEATURED,
            'status' => ContentImage::STATUS_PENDING, 'prompt' => 'a clean product shot',
            'params' => ['source' => 'client-regen', 'regenerated_from' => $old->id, 'regen_count' => 1],
        ]);
        Http::fake([
            'api.ideogram.ai/*' => Http::response(['data' => [['url' => 'https://cdn.ideogram.ai/new.png', 'seed' => 2, 'resolution' => '1344x768']]], 200),
            'cdn.ideogram.ai/*' => Http::response('NEWBYTES', 200),
        ]);

        (new GenerateInlineImageJob($new->id, 'a clean product shot', $old->id))
            ->handle(app(IdeogramClient::class), app(IdeogramSpendMeter::class));

        $new->refresh();
        $this->assertSame(ContentImage::STATUS_GENERATED, $new->status);
        $this->assertSame(ContentImage::STATUS_REJECTED, $old->fresh()->status);
        $html = (string) $article->fresh()->html;
        $this->assertStringContainsString($new->url(), $html, 'src swapped to the new file');
        $this->assertStringNotContainsString('old.png', $html);
        // The regen source label survives the generate params merge.
        $this->assertSame('client-regen', $new->params['source']);
    }

    public function test_swap_noops_when_figure_was_deleted_from_body(): void
    {
        [, $topic, $article, $old] = $this->fixture();
        $article->forceFill(['html' => '<p>No figure anymore.</p>'])->save();
        $new = ContentImage::create([
            'article_id' => $article->id, 'role' => ContentImage::ROLE_FEATURED,
            'status' => ContentImage::STATUS_PENDING, 'prompt' => 'p',
            'params' => ['source' => 'client-regen'],
        ]);
        Http::fake([
            'api.ideogram.ai/*' => Http::response(['data' => [['url' => 'https://cdn.ideogram.ai/new.png', 'seed' => 2, 'resolution' => '1344x768']]], 200),
            'cdn.ideogram.ai/*' => Http::response('NEWBYTES', 200),
        ]);

        (new GenerateInlineImageJob($new->id, 'p', $old->id))
            ->handle(app(IdeogramClient::class), app(IdeogramSpendMeter::class));

        $this->assertSame('<p>No figure anymore.</p>', (string) $article->fresh()->html);
        $this->assertSame(ContentImage::STATUS_REJECTED, $old->fresh()->status);
    }

    public function test_main_image_card_shows_in_all_three_states(): void
    {
        [$user, $topic, $article, $image] = $this->fixture();
        $plan = $topic->plan;

        // Toggle ON + embedded.
        $c = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id]);
        $c->assertSee(__('Main image'))->assertSee(__('shown at the top of your article'));

        // Toggle ON + figure deleted from body.
        $article->forceFill(['html' => '<p>No figure.</p>'])->save();
        Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->assertSee(__('Main image'))
            ->assertSee(__('You removed it from the article body'));

        // Toggle OFF.
        $plan->update(['toggles' => array_merge((array) $plan->toggles, ['featured_image' => false])]);
        Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id])
            ->assertSee(__('Main image'))
            ->assertSee(__('because you turned that off in settings'));
    }

    public function test_prompts_no_longer_steer_toward_hands(): void
    {
        $src = file_get_contents(app_path('Jobs/GenerateContentImagesJob.php'));
        $this->assertStringNotContainsString('close-ups of hands at work', $src);
        $this->assertStringNotContainsString('hands, tools, materials or textures', $src);
        $this->assertStringContainsString('visible human hands or faces', $src);
    }

    public function test_request_inline_image_is_rate_limited(): void
    {
        Queue::fake();
        [$user, $topic] = $this->fixture();

        $c = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id]);
        for ($i = 0; $i < 11; $i++) {
            $c->call('requestInlineImage', 'a nice image '.$i);
        }
        // 11th call refused by the rate limiter: only 10 rows created.
        $this->assertSame(10, ContentImage::query()->where('status', ContentImage::STATUS_PENDING)->count());
    }
}
