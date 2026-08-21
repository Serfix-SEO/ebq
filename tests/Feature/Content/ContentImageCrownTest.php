<?php

namespace Tests\Feature\Content;

use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Image rows follow the is_current crown: every consumer (publish drivers'
 * featured image, WP sideload, review page) reads images off the CURRENT
 * version, so a new version created after image generation — client edit,
 * revise pass, brand scrub — must inherit them or the article publishes
 * without its main image (cocomii Shopify 2026-08-21).
 */
class ContentImageCrownTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: ContentTopic, 1: ContentArticle, 2: ContentImage} */
    private function topicWithImagedArticle(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'T', 'target_keyword' => 'kw', 'status' => ContentTopic::STATUS_READY,
        ]);
        $v1 = ContentArticle::storeVersion($topic, [
            'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D',
            'slug' => 'h', 'html' => '<p>body</p>', 'seo_score' => 90,
        ]);
        $image = ContentImage::create([
            'article_id' => $v1->id,
            'role' => ContentImage::ROLE_FEATURED, 'status' => ContentImage::STATUS_GENERATED,
            'disk_path' => 'content/images/TEST', 'alt_text' => 'alt',
        ]);

        return [$topic, $v1, $image];
    }

    public function test_new_versions_inherit_the_image_rows(): void
    {
        [$topic, $v1, $image] = $this->topicWithImagedArticle();

        $v2 = ContentArticle::storeVersion($topic, [
            'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D',
            'slug' => 'h', 'html' => '<p>edited</p>', 'seo_score' => 91,
        ]);

        $this->assertSame($v2->id, $image->fresh()->article_id, 'featured image must move to the new current version');
        $this->assertSame(0, $v1->fresh()->images()->count());
        $this->assertSame(1, $v2->images()->where('role', ContentImage::ROLE_FEATURED)->count());
    }

    public function test_images_survive_two_version_bumps(): void
    {
        [$topic, , $image] = $this->topicWithImagedArticle();

        ContentArticle::storeVersion($topic, ['h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D', 'slug' => 'h', 'html' => '<p>v2</p>', 'seo_score' => 91]);
        $v3 = ContentArticle::storeVersion($topic, ['h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D', 'slug' => 'h', 'html' => '<p>v3</p>', 'seo_score' => 92]);

        $this->assertSame($v3->id, $image->fresh()->article_id);
    }
}
