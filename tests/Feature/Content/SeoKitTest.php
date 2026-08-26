<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ArticleReview;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** "SEO Kit" accordion: copy-ready SEO values on the article page (2026-08-27). */
class SeoKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    public function test_seo_kit_renders_values_head_snippet_and_schema(): void
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create(['domain' => 'pubgnamegenerator.net']);
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'billing_covered_at' => now()]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'T', 'target_keyword' => 'fancy font creator',
            'secondary_keywords' => ['pubg symbols'], 'status' => ContentTopic::STATUS_READY,
        ]);
        ContentArticle::create([
            'topic_id' => $topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'Fancy Font Creator Guide', 'meta_title' => 'Fancy Font Creator — Guide',
            'meta_description' => 'A guide to fancy fonts.', 'slug' => 'fancy-font-creator',
            'html' => '<p>Body.</p>', 'seo_score' => 95,
            'og_title' => 'OG Fancy Title', 'twitter_card' => 'summary_large_image',
        ]);

        $c = Livewire::actingAs($user)->test(ArticleReview::class, ['topicId' => $topic->id]);
        $kit = $c->viewData('seoKit');

        $this->assertNotNull($kit);
        $labels = array_column($kit['rows'], 'label');
        foreach ([__('Meta title'), __('Meta description'), __('URL slug'), __('Focus keyword'), __('Keywords'), __('Canonical URL'), __('Robots')] as $expected) {
            $this->assertContains($expected, $labels);
        }
        // Canonical computed from domain + slug when unset and unpublished.
        $canonical = collect($kit['rows'])->firstWhere('label', __('Canonical URL'))['value'];
        $this->assertSame('https://pubgnamegenerator.net/fancy-font-creator/', $canonical);
        // Head snippet carries escaped tags + OG override.
        $this->assertStringContainsString('<title>Fancy Font Creator — Guide</title>', $kit['headHtml']);
        $this->assertStringContainsString('og:title" content="OG Fancy Title"', $kit['headHtml']);
        $this->assertStringContainsString('twitter:card" content="summary_large_image"', $kit['headHtml']);
        // Schema is valid JSON-LD with the headline.
        $this->assertStringContainsString('application/ld+json', $kit['schemaJson']);
        $json = json_decode(trim(str_replace(['<script type="application/ld+json">', '</script>'], '', $kit['schemaJson'])), true);
        $this->assertSame('Article', $json['@type']);
        $this->assertSame('Fancy Font Creator Guide', $json['headline']);
        $this->assertSame($canonical, $json['mainEntityOfPage']['@id']);

        // Section visible on the page (collapsed accordion header).
        $c->assertSee(__('SEO Kit'));
        $c->assertSee(__('Copy all meta tags (HTML)'));
    }
}
