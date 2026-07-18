<?php

namespace Tests\Feature\Content;

use App\Jobs\GenerateContentImagesJob;
use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\IdeogramClient;
use App\Services\Content\IdeogramSpendMeter;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContentImagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        Storage::fake('public');
        config([
            'services.ideogram.key' => 'fake-key',
            'services.ideogram.base_url' => 'https://api.ideogram.ai/v1',
            'services.ideogram.monthly_cap_usd' => 10,
        ]);
        // The spend meter is a Redis counter — RefreshDatabase does NOT clear
        // it, so the ->add() in the cap test would otherwise accumulate across
        // runs and eventually push every run over the cap. Reset it per test.
        \Illuminate\Support\Facades\Redis::connection()
            ->del('ideogram:spend:'.now()->format('Y-m'));
    }

    private function readyArticle(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'target_keyword' => 'pubg name generator',
            'secondary_keywords' => ['stylish pubg names', 'pubg symbols'],
            'status' => ContentTopic::STATUS_READY,
        ]);
        $article = ContentArticle::storeVersion($topic, [
            'h1' => 'PUBG Name Generator Guide',
            'meta_title' => 'PUBG Name Generator Guide',
            'meta_description' => 'Guide.',
            'slug' => 'pubg-name-generator-guide',
            'html' => '<h2 id="how-it-works">How it works</h2><p>Body one.</p>'
                .'<h2 id="stylish-fonts">Stylish fonts</h2><p>Body two.</p>'
                .'<h2 id="faq-frequently-asked">FAQ</h2><p>Answers.</p>',
            'seo_issues' => [],
        ]);

        return [$user, $website, $plan, $topic, $article];
    }

    private function fakeIdeogramOk(): void
    {
        Http::fake([
            'api.ideogram.ai/*' => Http::response(['data' => [['url' => 'https://cdn.ideogram.ai/img.png', 'seed' => 1, 'resolution' => '1344x768']]], 200),
            'cdn.ideogram.ai/*' => Http::response('PNGBYTES', 200),
        ]);
    }

    public function test_generates_featured_plus_inline_images_with_keyphrase_alts(): void
    {
        $this->fakeIdeogramOk();
        [, , , $topic, $article] = $this->readyArticle();

        (new GenerateContentImagesJob($article->id))->handle(app(IdeogramClient::class), app(IdeogramSpendMeter::class));

        $images = ContentImage::query()->where('article_id', $article->id)->get();
        // 1 featured + 2 inline (maxInlineImages default 2). FAQ section skipped.
        $this->assertSame(1, $images->where('role', ContentImage::ROLE_FEATURED)->count());
        $this->assertSame(2, $images->where('role', ContentImage::ROLE_INLINE)->count());
        $this->assertTrue($images->every(fn ($i) => $i->status === ContentImage::STATUS_GENERATED));

        // Bytes persisted to the public disk.
        $this->assertTrue(Storage::disk('public')->exists($images->first()->disk_path));

        // Featured alt uses the focus keyphrase; inline alts use additional keyphrases.
        $featured = $images->firstWhere('role', ContentImage::ROLE_FEATURED);
        $this->assertStringContainsStringIgnoringCase('pubg name generator', $featured->alt_text);
        $inlineAlts = $images->where('role', ContentImage::ROLE_INLINE)->pluck('alt_text')->implode(' | ');
        $this->assertStringContainsString('stylish pubg names', $inlineAlts);

        // HTML now carries a featured figure at the top + inline figures after headings.
        $html = $article->fresh()->html;
        $this->assertStringContainsString('<figure class="content-image">', $html);
        $this->assertMatchesRegularExpression('/id="how-it-works".*?<\/h2><figure/s', $html);
        // Featured figure precedes the first <h2>.
        $this->assertLessThan(strpos($html, '<h2'), strpos($html, '<figure'));
    }

    public function test_skipped_when_images_disabled(): void
    {
        Setting::set('content.images.enabled', '0');
        Http::fake();
        [, , , , $article] = $this->readyArticle();

        (new GenerateContentImagesJob($article->id))->handle(app(IdeogramClient::class), app(IdeogramSpendMeter::class));

        $this->assertSame(0, ContentImage::query()->where('article_id', $article->id)->count());
        Http::assertNothingSent();
    }

    public function test_ideogram_failure_leaves_article_without_images(): void
    {
        Http::fake(['api.ideogram.ai/*' => Http::response(['error' => 'boom'], 500)]);
        [, , , , $article] = $this->readyArticle();
        $before = $article->html;

        (new GenerateContentImagesJob($article->id))->handle(app(IdeogramClient::class), app(IdeogramSpendMeter::class));

        $this->assertSame(0, ContentImage::query()->where('article_id', $article->id)->count());
        $this->assertSame($before, $article->fresh()->html);
    }

    public function test_exhausted_spend_cap_skips_generation(): void
    {
        config(['services.ideogram.monthly_cap_usd' => 1]);
        app(IdeogramSpendMeter::class)->add(1.0); // at cap
        Http::fake();
        [, , , , $article] = $this->readyArticle();

        (new GenerateContentImagesJob($article->id))->handle(app(IdeogramClient::class), app(IdeogramSpendMeter::class));

        $this->assertSame(0, ContentImage::query()->where('article_id', $article->id)->count());
        Http::assertNothingSent();
    }

    public function test_plan_with_images_disabled_skips_generation(): void
    {
        Http::fake();
        [, , $plan, , $article] = $this->readyArticle();
        $plan->update(['images_enabled' => false]);

        (new GenerateContentImagesJob($article->id))->handle(app(IdeogramClient::class), app(IdeogramSpendMeter::class));

        $this->assertSame(0, ContentImage::query()->where('article_id', $article->id)->count());
        Http::assertNothingSent();
    }

    public function test_publish_sideloads_featured_media_and_rewrites_inline_src(): void
    {
        // Guard-free (tests can't do live DNS); the parent Publishing test binds
        // this, but here we bind it locally too.
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });

        [$user, $website, $plan, $topic, $article] = $this->readyArticle();
        $topic->update(['status' => ContentTopic::STATUS_SCHEDULED, 'scheduled_for' => now()->subDay()]);
        Storage::disk('public')->put('content/images/feat.png', 'FEAT');
        Storage::disk('public')->put('content/images/inl.png', 'INL');
        $featuredUrl = Storage::disk('public')->url('content/images/feat.png');
        $inlineUrl = Storage::disk('public')->url('content/images/inl.png');
        $article->forceFill(['html' => '<figure class="content-image"><img src="'.$featuredUrl.'" alt="a"/></figure>'
            .'<h2 id="s">S</h2><figure class="content-image"><img src="'.$inlineUrl.'" alt="stylish pubg names"/></figure><p>x</p>'])->save();
        ContentImage::query()->create(['article_id' => $article->id, 'role' => ContentImage::ROLE_FEATURED, 'disk_path' => 'content/images/feat.png', 'filename' => 'feat.png', 'alt_text' => 'a', 'status' => ContentImage::STATUS_GENERATED]);
        ContentImage::query()->create(['article_id' => $article->id, 'role' => ContentImage::ROLE_INLINE, 'disk_path' => 'content/images/inl.png', 'filename' => 'inl.png', 'alt_text' => 'stylish pubg names', 'status' => ContentImage::STATUS_GENERATED]);

        ContentIntegration::query()->create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD,
            'credentials' => ['site_url' => 'https://client-blog.com', 'username' => 'admin', 'app_password' => 'p'],
            'status' => ContentIntegration::STATUS_CONNECTED,
            'config' => ['seo_plugin' => false],
        ]);

        Http::fake([
            'client-blog.com/wp-json/wp/v2/media/*' => Http::response(['id' => 9], 200),
            'client-blog.com/wp-json/wp/v2/media' => Http::sequence()
                ->push(['id' => 11, 'source_url' => 'https://client-blog.com/wp/feat.png'], 201)
                ->push(['id' => 12, 'source_url' => 'https://client-blog.com/wp/inl.png'], 201),
            'client-blog.com/wp-json/wp/v2/posts*' => Http::response(['id' => 500, 'link' => 'https://client-blog.com/p/', 'status' => 'publish'], 201),
            'client-blog.com/p*' => Http::response('<h1>x</h1>', 200),
        ]);

        (new \App\Jobs\PublishContentArticleJob($topic->id))->handle(
            app(\App\Services\Content\Publishing\PublishDriverFactory::class),
            app(\App\Support\Audit\SafeHttpGuard::class),
        );

        Http::assertSent(function ($req) {
            if (! str_ends_with($req->url(), '/wp/v2/posts')) {
                return false;
            }
            $d = $req->data();
            return ($d['featured_media'] ?? null) === 11
                && str_contains($d['content'] ?? '', 'client-blog.com/wp/inl.png')   // inline src rewritten
                && ! str_contains($d['content'] ?? '', '/storage/content/images/');   // no local URLs left
        });
        $this->assertSame(ContentTopic::STATUS_PUBLISHED, $topic->fresh()->status);
    }
}
