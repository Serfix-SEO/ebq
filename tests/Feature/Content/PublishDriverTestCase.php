<?php

namespace Tests\Feature\Content;

use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Shared scaffolding for the per-platform publish-driver suites: a scheduled
 * article + website, a no-network SafeHttpGuard, and the encryption-at-rest
 * assertion every driver test must make.
 */
abstract class PublishDriverTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\PlanSeeder::class);
        // SafeHttpGuard does live DNS resolution; test hostnames must not
        // depend on the internet. All HTTP is Http::fake()d anyway.
        $this->app->bind(\App\Support\Audit\SafeHttpGuard::class, fn () => new class extends \App\Support\Audit\SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
    }

    /** @return array{0: User, 1: Website, 2: ContentPlan, 3: ContentTopic, 4: ContentArticle} */
    protected function scheduledArticle(array $articleAttrs = []): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'publish_days' => [],
            'timezone' => 'UTC',
        ]);
        $topic = ContentTopic::factory()->for($plan, 'plan')->create([
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_SCHEDULED,
            'scheduled_for' => now()->subDay(),
            'secondary_keywords' => ['alpha keyword', 'beta keyword'],
        ]);
        $article = ContentArticle::storeVersion($topic, array_merge([
            'h1' => 'A Publishable Article',
            'meta_title' => 'A Publishable Article',
            'meta_description' => 'Description.',
            'slug' => 'a-publishable-article',
            'html' => '<h2>Body</h2><p>Text with <strong>bold</strong>.</p>',
            'word_count' => 500,
            'seo_score' => 90,
            'seo_issues' => [],
        ], $articleAttrs));

        return [$user, $website, $plan, $topic, $article];
    }

    /** A generated featured image row whose bytes exist on the content disk. */
    protected function featuredImage(ContentArticle $article): ContentImage
    {
        \Illuminate\Support\Facades\Storage::fake(ContentImage::disk());
        $path = 'content/'.$article->id.'/featured.png';
        \Illuminate\Support\Facades\Storage::disk(ContentImage::disk())->put($path, 'png-bytes');

        return ContentImage::query()->create([
            'article_id' => $article->id,
            'role' => ContentImage::ROLE_FEATURED,
            'status' => ContentImage::STATUS_GENERATED,
            'disk_path' => $path,
            'filename' => 'featured.png',
            'alt_text' => 'Featured alt',
            'prompt' => 'p',
        ]);
    }

    /** Raw DB row must not leak the secret in cleartext (AsEncryptedArrayObject). */
    protected function assertCredentialEncrypted(string $integrationId, string $secret): void
    {
        $raw = (string) DB::table('content_integrations')->where('id', $integrationId)->value('credentials');
        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString($secret, $raw);
    }
}
