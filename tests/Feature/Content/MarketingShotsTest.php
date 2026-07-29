<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ArticleReview;
use App\Livewire\Content\ContentCalendar;
use App\Livewire\Content\KeywordRankHistory;
use App\Livewire\Content\KeywordTracker;
use App\Models\ContentArticle;
use App\Models\ContentIntegration;
use App\Models\ContentKeywordRankHistory;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\ContentTrackedKeyword;
use App\Models\SearchConsoleData;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Screenshot fixture generator for the marketing pages — NOT an assertion test.
 *
 * Renders the REAL Content Autopilot components against a throwaway sqlite
 * database seeded with a fictional demo brand, and dumps each one's HTML so a
 * headless browser can turn it into a product visual. This is how
 * public/images/content/*.png are produced: genuine product UI (real Blade,
 * real CSS, real components) with invented sample data, so no client's content
 * ever lands on a public page.
 *
 * Skipped by default — it writes files, so it only runs on demand:
 *   MARKETING_SHOTS=/abs/output/dir php artisan test tests/Feature/Content/MarketingShotsTest.php
 */
class MarketingShotsTest extends TestCase
{
    use RefreshDatabase;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $dir = (string) env('MARKETING_SHOTS', '');
        if ($dir === '') {
            $this->markTestSkipped('Set MARKETING_SHOTS=<dir> to regenerate the marketing visuals.');
        }
        $this->out = rtrim($dir, '/');
        if (! is_dir($this->out)) {
            mkdir($this->out, 0755, true);
        }
        $this->seed(PlanSeeder::class);
        Queue::fake(); // never dispatch pipeline work from a fixture run
    }

    private function dump(string $name, string $html): void
    {
        file_put_contents($this->out.'/'.$name.'.html', $html);
    }

    public function test_render_the_marketing_shots(): void
    {
        $user = User::factory()->create([
            'name' => 'Sam Rivera', 'email' => 'sam@northlanecoffee.com',
            'content_comp_sites' => 5, // a covered account, so the quota meter reads normally
        ]);
        $website = Website::factory()->for($user)->create(['domain' => 'northlanecoffee.com']);
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'auto_publish' => true,
            'articles_per_week' => 3,
            'article_length' => 800,
            'business_description' => 'Northlane Coffee roasts single-origin beans and ships fresh subscriptions across the UK.',
            'offerings' => ['sell' => ['Single-origin subscriptions', 'Espresso blends', 'Brewing equipment'], 'dont_sell' => []],
        ]);
        app(ContentEntitlements::class)->coverWebsite($website);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        // ── A calendar month that looks like a real editorial plan ──────────
        $day = fn (int $d) => now()->startOfMonth()->addDays($d - 1);
        $titles = [
            ['Single Origin vs Blend: Which Coffee Should You Buy?', 'single origin vs blend', 'published', 3],
            ['How to Store Coffee Beans So They Stay Fresh for Weeks', 'how to store coffee beans', 'published', 6],
            ['The Complete Pour Over Coffee Guide for Home Brewing', 'pour over coffee guide', 'published', 9],
            ['Light vs Dark Roast: A Practical Comparison', 'light vs dark roast', 'published', 13],
            ['Best Grind Size for Every Brewing Method', 'best grind size for coffee', 'published', 16],
            ['Why Fresh Roast Dates Matter More Than Origin', 'coffee roast date', 'ready', 20],
            ['Espresso at Home: Equipment That Is Actually Worth It', 'home espresso equipment', 'scheduled', 23],
            ['Cold Brew vs Iced Coffee: What Is the Difference?', 'cold brew vs iced coffee', 'approved', 25],
            ['A Beginner Guide to Coffee Tasting Notes', 'coffee tasting notes', 'approved', 27],
            ['How Altitude Changes the Flavour of Your Coffee', 'coffee altitude flavour', 'suggested', 30],
        ];
        $topics = [];
        foreach ($titles as $i => [$title, $kw, $status, $offset]) {
            $topics[] = ContentTopic::factory()->create([
                'plan_id' => $plan->id,
                'website_id' => $website->id,
                'title' => $title,
                'target_keyword' => $kw,
                'status' => $status,
                'scheduled_for' => $day($offset),
                'keyword_volume' => [2400, 1300, 5400, 880, 1900, 720, 3600, 4400, 590, 320][$i] ?? 500,
                'published_at' => $status === 'published' ? $day($offset) : null,
                'source' => $i < 7 ? 'gsc_gap' : 'llm',
                'secondary_keywords' => ['grind size', 'water temperature', 'brew ratio'],
            ]);
        }
        ContentIntegration::create([
            'website_id' => $website->id,
            'platform' => 'wordpress',
            'status' => ContentIntegration::STATUS_CONNECTED,
            'credentials' => ['site_url' => 'https://northlanecoffee.com', 'user' => 'demo', 'app_password' => 'demo'],
        ]);

        $this->dump('calendar', Livewire::test(ContentCalendar::class)->html());

        // ── One finished article: preview + SEO score + checks ──────────────
        $topic = $topics[2];
        ContentArticle::storeVersion($topic, [
            'h1' => 'The Complete Pour Over Coffee Guide for Home Brewing',
            'meta_title' => 'Pour Over Coffee Guide: Brew Better Cups at Home',
            'meta_description' => 'A practical pour over coffee guide covering grind size, water temperature and brew ratio, with a simple routine for a sweet, balanced cup every morning.',
            'slug' => 'pour-over-coffee-guide',
            'focus_keyword' => 'pour over coffee guide',
            'seo_score' => 92,
            'word_count' => 2140,
            'html' => $this->sampleArticleHtml(),
            'seo_issues' => [],
            'style_issues' => [],
            'generation_meta' => ['provider' => 'demo', 'model' => 'demo'],
        ]);
        $component = Livewire::test(ArticleReview::class, ['topicId' => $topic->id]);
        // Audit trail for tuning the demo article — which weighted checks miss.
        $ref = new \ReflectionMethod(ArticleReview::class, 'scoreCurrent');
        $ref->setAccessible(true);
        $res = $ref->invoke($component->instance(), $this->sampleArticleHtml());
        file_put_contents($this->out.'/article-score.json', json_encode([
            'score' => $res['score'] ?? null,
            'failed' => array_values(array_filter($res['checks'] ?? [], fn ($c) => ! ($c['passed'] ?? true))),
        ], JSON_PRETTY_PRINT));
        $this->dump('article', $component->html());

        // ── Tracker + one keyword's rank history ───────────────────────────
        $tracked = [];
        foreach ([
            ['pour over coffee guide', true, 4],
            ['best grind size for coffee', false, 7],
            ['how to store coffee beans', false, 11],
            ['single origin vs blend', false, 3],
        ] as [$kw, $primary, $pos]) {
            $tracked[] = ContentTrackedKeyword::create([
                'website_id' => $website->id,
                'topic_id' => $topic->id,
                'keyword' => $kw,
                'normalized_keyword' => ContentTrackedKeyword::normalize($kw),
                'page_url' => 'https://northlanecoffee.com/blog/pour-over-coffee-guide',
                'is_primary' => $primary,
                'source' => ContentTrackedKeyword::SOURCE_AUTO,
                'serp_position' => $pos,
                'serp_checked_at' => now()->subDay(),
            ]);
        }
        foreach ($tracked as $i => $row) {
            $base = [180, 90, 60, 140][$i] ?? 60;
            for ($d = 27; $d >= 1; $d--) {
                $impr = (int) round($base * (1.6 - $d / 40) + ($d % 5) * 7);
                SearchConsoleData::create([
                    'website_id' => $website->id,
                    'date' => now()->subDays($d)->toDateString(),
                    'query' => $row->normalized_keyword,
                    'page' => 'https://northlanecoffee.com/blog/pour-over-coffee-guide',
                    'clicks' => max(0, (int) round($impr * 0.09)),
                    'impressions' => $impr,
                    'position' => round(([6.2, 9.4, 13.1, 4.8][$i] ?? 8) + $d / 12, 1),
                    'ctr' => 0.09,
                ]);
            }
        }

        $this->dump('tracker', Livewire::test(KeywordTracker::class)->html());

        // A believable 90-day climb for the rank-history chart.
        $climb = [58, 47, 41, 33, 28, 22, 19, 15, 12, 9, 7, 6, 4];
        foreach ($climb as $i => $pos) {
            ContentKeywordRankHistory::create([
                'website_id' => $website->id,
                'tracked_keyword_id' => $tracked[0]->id,
                'normalized_keyword' => $tracked[0]->normalized_keyword,
                'checked_on' => now()->subDays((count($climb) - $i) * 7)->toDateString(),
                'position' => $pos,
                'url' => 'https://northlanecoffee.com/blog/pour-over-coffee-guide',
                'source' => ContentKeywordRankHistory::SOURCE_SERP,
            ]);
        }
        $this->dump('rank-history', Livewire::test(KeywordRankHistory::class, ['keywordId' => $tracked[0]->id])->html());

        $this->assertTrue(true, 'fixtures written to '.$this->out);
    }

    private function sampleArticleHtml(): string
    {
        return <<<'HTML'
<p>This pour over coffee guide covers the three things that decide whether your cup tastes sweet or sour: grind size, water temperature and brew ratio. Get those right and a thirty-dollar dripper will out-brew most café machines.</p>
<div class="key-takeaways"><h2>Key takeaways</h2><ul><li>Start at a 1:16 brew ratio, or 60g of coffee per litre of water.</li><li>Use a medium-fine grind size, roughly the texture of table salt.</li><li>Keep water temperature between 92&deg;C and 96&deg;C.</li><li>Aim for a total brew time of three to four minutes.</li></ul></div>
<h2>What this pour over coffee guide covers</h2>
<p>Weigh the coffee before you adjust anything else. A 1:16 brew ratio is what most roasters design their profiles around, and it is forgiving enough that a mistake elsewhere shows up clearly instead of hiding behind an odd strength. Twenty grams of coffee to 320g of water makes a generous single mug.</p>
<p>Once the ratio is fixed, every other change you make has exactly one variable behind it. That is the point of following a pour over coffee guide in order: if you adjust three things at once and the cup improves, you have learned nothing you can repeat tomorrow.</p>
<h2>Grind size does most of the work</h2>
<p>Grind size controls how fast water moves through the bed, and therefore how much of the coffee actually dissolves. Too coarse and the water rushes past, pulling out the bright acids but leaving the sugars behind, which is the thin, sour cup. Too fine and the water stalls, dragging out the dry, bitter compounds that come last.</p>
<p>Table salt is the reference most people find useful for a cone dripper. If your brew finishes well under three minutes, go a step finer. If it drags past four and a half, go coarser. Adjust in small steps and taste between each one. The difference between a good cup and a great one is usually two clicks on a burr grinder.</p>
<h2>Water temperature and why the bloom matters</h2>
<p>Water temperature between 92&deg;C and 96&deg;C keeps extraction in the sweet range. Off the boil for thirty seconds is close enough without a thermometer. Cooler water under-extracts and tastes flat, while water at a rolling boil scalds the grounds and adds a papery harshness that no ratio will rescue.</p>
<p>Before the main pour, wet the grounds with roughly twice their weight in water and wait thirty seconds. Fresh coffee releases carbon dioxide, and that gas physically pushes water away from the grounds. The bloom lets it escape so the rest of the pour meets coffee instead of bubbles, which is why the same beans taste better a week after roasting than on day one. The <a href="https://sca.coffee/" rel="nofollow">Specialty Coffee Association</a> publishes brewing standards that land in the same range.</p>
<h2>Pouring technique, simplified</h2>
<p>After the bloom, pour in slow concentric circles, keeping the water level roughly halfway up the cone. Avoid pouring directly onto the filter wall: water that runs down the paper bypasses the coffee entirely and dilutes the cup. Two or three steady pours are easier to repeat than one continuous stream.</p>
<p>A flat, even bed at the end of the brew means the water moved through uniformly. Deep craters, or a bed climbing one wall, tell you the pour was uneven long before the taste does.</p>
<h2>Troubleshooting your cup</h2>
<p>Sour, thin, finishes fast: grind finer or raise the water temperature slightly. Bitter, dry, finishes slowly: grind coarser and check that you are not over-agitating the bed. Muddled and flat with no clear flavour: your beans are probably past their best, so check the roast date rather than blaming the technique.</p>
<p>Keep a short log for a week covering brew ratio, grind setting, water temperature, total time and one line on how it tasted. Five entries in, the pattern is usually obvious, and you will have a recipe you can hit every morning without thinking about it.</p>
<h2>Frequently asked questions about this pour over coffee guide</h2>
<h3>Does the filter brand matter?</h3>
<p>Less than rinsing it. A pre-rinse removes the papery taste and preheats the brewer, which affects the cup far more than which box the filters came from.</p>
<h3>Do I need a gooseneck kettle?</h3>
<p>It helps, because it makes the pour rate repeatable, but grind size and brew ratio will change your coffee far more than the kettle spout does. Fix those first.</p>
<h3>How fresh should the beans be?</h3>
<p>Best between five days and four weeks off roast. Before that the bloom is violent and the flavours are still tight. Well after it, the aromatics have faded and no adjustment brings them back.</p>
HTML;
    }
}
