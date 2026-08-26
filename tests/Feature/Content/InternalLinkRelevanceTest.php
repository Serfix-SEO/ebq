<?php

namespace Tests\Feature\Content;

use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentArticleProducer;
use App\Services\Content\ContentSeoScorer;
use App\Support\Content\InternalLinkCandidates;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Internal-link relevance (cocomii 2026-08-26: "specific terms point to
 * random blog entries"): titled topic-relevant candidates reach the prompts,
 * the scorer flags mismatched anchors, and the final gate strips them.
 */
class InternalLinkRelevanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config(['services.mistral.key' => 'test-key', 'services.deepseek.key' => 'test-key']);
    }

    /** @return array{0: Website, 1: ContentPlan, 2: ContentTopic} */
    private function siteWithPages(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create();
        $crawlSiteId = \App\Models\CrawlSite::firstOrCreate(
            ['normalized_domain' => $website->domain],
            ['status' => 'completed', 'subscriber_count' => 1, 'effective_cap' => 100],
        )->id;
        $website->forceFill(['crawl_site_id' => $crawlSiteId])->save();

        $rows = [
            // Nav-heavy inbound leaders (irrelevant to the topic).
            ['url' => 'https://'.$website->domain.'/about-us', 'title' => 'About Us', 'inbound' => 500],
            ['url' => 'https://'.$website->domain.'/contact', 'title' => 'Contact', 'inbound' => 400],
            // Topic-relevant page, low inbound (the page anchors SHOULD hit).
            ['url' => 'https://'.$website->domain.'/rectangle-iphone-case-guide', 'title' => 'Rectangle iPhone Case Style Guide', 'inbound' => 2],
            ['url' => 'https://'.$website->domain.'/blog/random-post', 'title' => 'Our Summer Picnic Recap', 'inbound' => 300],
        ];
        foreach ($rows as $r) {
            DB::table('website_pages')->insert([
                'id' => (string) \Illuminate\Support\Str::ulid(),
                'crawl_site_id' => $crawlSiteId, 'url' => $r['url'],
                'url_hash' => hash('sha256', $r['url']), 'title' => $r['title'],
                'http_status' => 200, 'inbound_link_count' => $r['inbound'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id, 'billing_covered_at' => now(),
            'business_description' => 'Phone case shop.',
            'offerings' => ['sell' => ['phone cases'], 'dont_sell' => []],
        ]);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'How to Clean an iPhone Case', 'target_keyword' => 'rectangle iphone case',
            'status' => ContentTopic::STATUS_READY,
        ]);

        return [$website, $plan, $topic];
    }

    private function scorerContextFor(ContentTopic $topic, ContentPlan $plan, Website $website): array
    {
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'scorerContext');
        $m->setAccessible(true);

        return $m->invoke(app(ContentArticleProducer::class), $topic, $plan, $website);
    }

    public function test_candidates_are_titled_and_topic_relevant_beats_inbound(): void
    {
        [$website, $plan, $topic] = $this->siteWithPages();
        $context = $this->scorerContextFor($topic, $plan, $website);

        $this->assertNotEmpty($context['selected_pages']);
        // The low-inbound but topic-matching page ranks FIRST.
        $this->assertStringContainsString('rectangle-iphone-case-guide', $context['selected_pages'][0]['url']);
        $this->assertSame('Rectangle iPhone Case Style Guide', $context['selected_pages'][0]['title']);
        // site_pages maps every fetched url to its title (scorer's basis).
        $this->assertArrayHasKey('https://'.$website->domain.'/blog/random-post', $context['site_pages']);
    }

    public function test_zero_overlap_falls_back_to_inbound_order(): void
    {
        [$website, $plan, $topic] = $this->siteWithPages();
        $topic->update(['title' => 'Zzz', 'target_keyword' => 'qqqqq wwwww']);

        $context = $this->scorerContextFor($topic->fresh(), $plan, $website);

        $this->assertSame('About Us', $context['selected_pages'][0]['title']);
    }

    public function test_revise_prompt_carries_titles_and_anchor_rule(): void
    {
        [$website, $plan, $topic] = $this->siteWithPages();
        $article = ContentArticle::create([
            'topic_id' => $topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D',
            'slug' => 'h', 'html' => '<p>Body.</p>', 'seo_score' => 70,
        ]);

        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' =>
            json_encode(['html' => '<p>x</p>', 'meta_title' => 't', 'meta_description' => 'd', 'h1' => 'h'])]]]])]);
        $m = new \ReflectionMethod(ContentArticleProducer::class, 'revise');
        $m->setAccessible(true);
        $m->invoke(app(ContentArticleProducer::class), $article, $topic, $plan);

        $bodies = '';
        foreach (Http::recorded() as [$request]) {
            $bodies .= json_encode($request->data());
        }
        $this->assertStringContainsString('Rectangle iPhone Case Style Guide', $bodies);
        $this->assertStringContainsString('ANCHOR RULE', $bodies);
    }

    public function test_scorer_flags_only_specific_mismatched_anchors(): void
    {
        [$website, $plan, $topic] = $this->siteWithPages();
        $context = $this->scorerContextFor($topic, $plan, $website);
        $scorer = app(ContentSeoScorer::class);
        $base = 'https://'.$website->domain;

        // Specific anchor → unrelated post: flagged.
        $html = '<p><a href="'.$base.'/blog/random-post">Rectangle iPhone 17 Pro Max Case</a></p>';
        $bad = $scorer->mismatchedAnchors($html, $context);
        $this->assertCount(1, $bad);
        $this->assertSame($base.'/blog/random-post', $bad[0]['url']);

        // Anchor sharing title vocabulary: passes.
        $this->assertSame([], $scorer->mismatchedAnchors(
            '<p><a href="'.$base.'/rectangle-iphone-case-guide">rectangle iPhone case style guide</a></p>', $context));

        // Generic anchor: passes (not the failure mode).
        $this->assertSame([], $scorer->mismatchedAnchors(
            '<p><a href="'.$base.'/blog/random-post">learn more</a></p>', $context));

        // External link: ignored.
        $this->assertSame([], $scorer->mismatchedAnchors(
            '<p><a href="https://elsewhere.example/post">Rectangle iPhone 17 Pro Max Case</a></p>', $context));

        // Unknown/untitled page: passes (no basis to judge).
        $this->assertSame([], $scorer->mismatchedAnchors(
            '<p><a href="'.$base.'/uncrawled-page">Rectangle iPhone 17 Pro Max Case</a></p>', $context));
    }

    public function test_final_gate_strips_mismatched_links_without_failing_the_topic(): void
    {
        [$website, $plan, $topic] = $this->siteWithPages();
        $base = 'https://'.$website->domain;
        $article = ContentArticle::create([
            'topic_id' => $topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'H', 'meta_title' => 'H', 'meta_description' => 'D', 'slug' => 'h',
            'html' => '<p>Keep <a href="'.$base.'/rectangle-iphone-case-guide">rectangle iPhone case guide</a>'
                .' but strip <a href="'.$base.'/blog/random-post">Rectangle iPhone 17 Pro Max Case</a>.</p>',
            'seo_score' => 95,
        ]);
        $context = $this->scorerContextFor($topic, $plan, $website);

        $m = new \ReflectionMethod(ContentArticleProducer::class, 'stripMismatchedInternalLinks');
        $m->setAccessible(true);
        $result = $m->invoke(app(ContentArticleProducer::class), $article, $topic, $context);

        $this->assertSame('anchor_strip', $result->generation_meta['stage'] ?? null);
        $this->assertStringContainsString('rectangle-iphone-case-guide', $result->html, 'matching link survives');
        $this->assertStringNotContainsString('blog/random-post', $result->html, 'mismatched link unwrapped');
        $this->assertStringContainsString('Rectangle iPhone 17 Pro Max Case', $result->html, 'anchor text kept as plain text');
        $this->assertTrue($result->is_current);

        // Clean article → no extra version stored.
        $before = $topic->articles()->count();
        $again = $m->invoke(app(ContentArticleProducer::class), $result, $topic, $context);
        $this->assertSame($before, $topic->articles()->count());
        $this->assertSame($result->id, $again->id);
    }
}
