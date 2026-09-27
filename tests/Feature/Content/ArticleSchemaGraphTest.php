<?php

namespace Tests\Feature\Content;

use App\Models\ContentArticle;
use App\Models\ContentAuthor;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Aeo\ArticleSchemaGraph;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The structured description an answer engine reads.
 *
 * Before this there were three disagreeing half-implementations and every one
 * of them named the author as "Organization: <bare domain>" — the weakest
 * possible claim to authorship.
 */
class ArticleSchemaGraphTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{ContentArticle, ContentTopic, Website} */
    private function article(string $html = '<p>Oud is a resin.</p>', array $planAttrs = []): array
    {
        $website = Website::factory()->for(User::factory())->create([
            'domain' => 'bellavest.ae', 'normalized_domain' => 'bellavest.ae',
        ]);
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'language' => 'en'] + $planAttrs);
        $topic = ContentTopic::create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'Best oud perfume', 'target_keyword' => 'best oud perfume',
            'status' => ContentTopic::STATUS_READY, 'scheduled_for' => now()->toDateString(),
        ]);
        $article = ContentArticle::create([
            'topic_id' => $topic->id, 'version' => 1, 'is_current' => true,
            'h1' => 'Best Oud Perfume', 'meta_title' => 'Best Oud Perfume for Summer',
            'meta_description' => 'How to choose oud for hot weather.',
            'html' => $html, 'word_count' => 900, 'canonical_url' => 'https://bellavest.ae/best-oud',
        ]);

        return [$article, $topic->fresh(['plan', 'website']), $website];
    }

    private function nodes(array $graph): array
    {
        return collect($graph['@graph'] ?? [])->keyBy('@type')->all();
    }

    public function test_the_graph_links_the_article_to_a_real_person_when_one_is_set(): void
    {
        [$article, $topic, $website] = $this->article();
        ContentAuthor::create([
            'website_id' => $website->id, 'name' => 'Sara Malik', 'role' => 'Head Perfumer',
            'bio' => 'Twelve years blending oud.', 'credentials' => 'IFRA certified',
            'same_as' => ['https://www.linkedin.com/in/saramalik', 'not-a-url'],
        ]);

        $nodes = $this->nodes(app(ArticleSchemaGraph::class)->build($article, $topic));

        $this->assertArrayHasKey('Person', $nodes);
        $this->assertSame('Sara Malik', $nodes['Person']['name']);
        $this->assertSame('Head Perfumer', $nodes['Person']['jobTitle']);
        $this->assertSame(['https://www.linkedin.com/in/saramalik'], $nodes['Person']['sameAs'], 'junk profile links are dropped');

        // The article points AT the person by id rather than nesting a copy.
        $this->assertSame($nodes['Person']['@id'], $nodes['BlogPosting']['author']['@id']);
        $this->assertSame($nodes['Organization']['@id'], $nodes['BlogPosting']['publisher']['@id']);
    }

    public function test_without_an_author_no_person_is_invented(): void
    {
        [$article, $topic] = $this->article();

        $nodes = $this->nodes(app(ArticleSchemaGraph::class)->build($article, $topic));

        $this->assertArrayNotHasKey('Person', $nodes);
        // Attribution falls back to the organisation — never to a name nobody gave us.
        $this->assertSame($nodes['Organization']['@id'], $nodes['BlogPosting']['author']['@id']);
    }

    public function test_the_organisation_uses_the_legal_name_and_profiles_when_given(): void
    {
        [$article, $topic] = $this->article('<p>x</p>', [
            'org_legal_name' => 'Bellavest Trading LLC',
            'org_logo_url' => 'https://bellavest.ae/logo.png',
            'org_same_as' => ['https://instagram.com/bellavest'],
        ]);

        $org = $this->nodes(app(ArticleSchemaGraph::class)->build($article, $topic))['Organization'];

        $this->assertSame('Bellavest Trading LLC', $org['name']);
        $this->assertSame('https://bellavest.ae/logo.png', $org['logo']['url']);
        $this->assertSame(['https://instagram.com/bellavest'], $org['sameAs']);
    }

    public function test_an_faq_section_becomes_question_nodes(): void
    {
        $html = '<p>Intro.</p><h2>FAQ</h2>'
            .'<h3>Does oud last in heat?</h3><p>Yes, longer than florals.</p>'
            .'<h3>How many sprays?</h3><p>One per wrist.</p>';
        [$article, $topic] = $this->article($html);

        $nodes = $this->nodes(app(ArticleSchemaGraph::class)->build($article, $topic));

        $this->assertArrayHasKey('FAQPage', $nodes);
        $this->assertCount(2, $nodes['FAQPage']['mainEntity']);
        $this->assertSame('Does oud last in heat?', $nodes['FAQPage']['mainEntity'][0]['name']);
    }

    public function test_a_listicle_is_not_passed_off_as_a_howto(): void
    {
        // Overreaching on structured data is how it gets ignored: HowTo needs
        // both the intent in the title and a real ordered procedure.
        [$listicle, $topicA] = $this->article('<p>x</p><ol><li>One</li><li>Two</li><li>Three</li></ol>');
        $this->assertArrayNotHasKey('HowTo', $this->nodes(app(ArticleSchemaGraph::class)->build($listicle, $topicA)));

        [$guide, $topicB] = $this->article('<p>x</p><ol><li>Warm the skin</li><li>Spray once</li><li>Let it settle</li></ol>');
        $guide->forceFill(['h1' => 'How to Apply Oud Perfume'])->save();
        $nodes = $this->nodes(app(ArticleSchemaGraph::class)->build($guide, $topicB));
        $this->assertArrayHasKey('HowTo', $nodes);
        $this->assertCount(3, $nodes['HowTo']['step']);
        $this->assertSame(1, $nodes['HowTo']['step'][0]['position']);
    }

    public function test_the_script_is_one_json_ld_block_ready_to_embed(): void
    {
        [$article, $topic] = $this->article();

        $script = app(ArticleSchemaGraph::class)->script($article, $topic);

        $this->assertStringStartsWith('<script type="application/ld+json">', $script);
        $decoded = json_decode(strip_tags($script), true);
        $this->assertSame('https://schema.org', $decoded['@context']);
        $this->assertNotEmpty($decoded['@graph']);
    }
}
