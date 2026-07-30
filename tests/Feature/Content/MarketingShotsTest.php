<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ArticleReview;
use App\Livewire\Content\ContentCalendar;
use App\Livewire\Content\KeywordRankHistory;
use App\Livewire\Content\KeywordTracker;
use App\Models\ContentArticle;
use App\Models\ContentImage;
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
use Illuminate\Support\Carbon;
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
        // Freeze "today" mid-month so the calendar always shows a believable
        // mix — shipped articles behind us, review and scheduled ahead. Run on
        // the 30th it would otherwise render 21 published and nothing planned.
        //
        // A 30-DAY month specifically: the shot fills every date, and the plan
        // allows 30 articles a month, so a 31-day month trips the over-quota
        // banner and paints two cards red.
        $month = now()->startOfMonth();
        while ($month->daysInMonth !== 30) {
            $month = $month->subMonthNoOverflow();
        }
        Carbon::setTestNow($month->copy()->addDays(13)->setTime(9, 40));

        $user = User::factory()->create([
            'name' => 'Sam Rivera', 'email' => 'sam@brightpathdigital.com',
            'content_comp_sites' => 5, // a covered account, so the quota meter reads normally
        ]);
        $website = Website::factory()->for($user)->create(['domain' => 'brightpathdigital.com']);
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'auto_publish' => true,
            'articles_per_week' => 7,
            'article_length' => 1400,
            'business_description' => 'Brightpath Digital is a small SEO agency that runs audits, technical fixes and reporting for service businesses.',
            'offerings' => ['sell' => ['SEO audits', 'Technical SEO retainers', 'Monthly reporting'], 'dont_sell' => []],
        ]);
        app(ContentEntitlements::class)->coverWebsite($website);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        // Google connected. Without this the tracker and article screens render
        // their "Connect Search Console / Analytics" prompts, which read as a
        // half-configured account on a marketing page. The demo brand is a
        // fully set-up customer, so the shots show the working state.
        $google = \App\Models\GoogleAccount::factory()->create(['user_id' => $user->id]);
        $website->forceFill([
            'gsc_site_url' => 'https://brightpathdigital.com/',
            'gsc_google_account_id' => $google->id,
            'ga_property_id' => '000000000',
            'ga_google_account_id' => $google->id,
        ])->save();

        // ── A calendar month that looks like a real editorial plan ──────────
        // One article on EVERY day of the month, weekends included: a sparse
        // grid made the screenshot look like an empty trial account, and gaps
        // on Saturday and Sunday looked like a broken schedule (2026-07-30
        // feedback, twice). Titles are
        // matched to the hero photograph each card carries — three of the
        // photos have their own article's title rendered inside the image — so
        // the grid reads as one coherent plan rather than stock art.
        $days = [];
        for ($d = now()->startOfMonth(); $d->month === now()->month; $d = $d->copy()->addDay()) {
            $days[] = $d->copy();
        }
        // title, keyword, hero index (null = no article yet), monthly volume
        $titles = [
            ['How to Do an SEO Audit: A Step-by-Step Guide for Beginners', 'how to do an seo audit', 0, 5400],
            ['Technical SEO Checklist: 15 Steps to Fix Crawling and Indexing Issues', 'technical seo checklist', 3, 3600],
            ['How to Use Google Search Console for SEO: A Beginner\'s Guide', 'how to use google search console', 6, 8100],
            ['Core Web Vitals: What They Are and How to Fix Them', 'core web vitals', 1, 12100],
            ['How Search Engines Crawl and Index Your Site', 'how search engines crawl', 4, 1600],
            ['How to Fix Duplicate Content Issues on Your Website', 'fix duplicate content', 5, 2900],
            ['Search Console Performance Reports, Explained', 'search console performance report', 7, 1300],
            ['How to Report SEO Results to Your Clients', 'seo client reporting', 2, 880],
            ['How to Verify a New Site in Search Console', 'verify site search console', 8, 720],
            ['XML Sitemaps: What to Include and What to Leave Out', 'xml sitemap best practices', 1, 2400],
            ['Robots.txt Explained, With Examples That Actually Work', 'robots txt examples', 5, 1900],
            ['Canonical Tags: When to Use Them and When Not To', 'canonical tags', 4, 1500],
            ['How to Find and Fix Broken Links at Scale', 'fix broken links', 7, 1100],
            ['Page Speed for Non-Developers: What to Ask Your Team For', 'improve page speed', 2, 3300],
            ['Internal Linking: The Cheapest Ranking Win You Are Ignoring', 'internal linking strategy', 8, 2700],
            ['How to Write Title Tags That Earn the Click', 'how to write title tags', 6, 4100],
            ['Meta Descriptions: Do They Still Matter in 2026?', 'meta descriptions seo', 3, 1800],
            ['Schema Markup for Small Sites, Without a Developer', 'schema markup guide', 0, 2200],
            ['Local SEO Checklist for Multi-Location Businesses', 'local seo checklist', 5, 3900],
            ['How to Audit a Site Migration Before It Goes Live', 'site migration seo', 1, 990],
            ['Keyword Research for Service Businesses', 'keyword research services', 7, 4800],
            ['How Often Should You Run an SEO Audit?', 'how often seo audit', null, 590],
            ['Index Coverage Errors, One by One', 'index coverage errors', 4, 1400],
            ['Redirects Without Regret: 301s, 302s and Chains', 'seo redirects guide', 3, 1700],
            ['How to Track Rankings Without Fooling Yourself', 'how to track rankings', 7, 1250],
            ['Content Refreshes: Which Old Posts to Update First', 'content refresh seo', 2, 2050],
            ['Search Intent, Explained With Real SERPs', 'search intent seo', 6, 3100],
            ['How to Set Up Google Analytics for a Content Site', 'google analytics setup', 8, 2600],
            ['Structured Data Errors and How to Read Them', 'structured data errors', 0, 860],
            ['Mobile-First Indexing: What It Changes for You', 'mobile first indexing', 5, 1450],
            ['How to Brief a Writer So the Draft Comes Back Right', 'seo content brief', 1, 940],
        ];
        $topics = [];
        foreach ($titles as $i => [$title, $kw, $hero, $volume]) {
            $date = $days[$i] ?? null;
            if ($date === null) {
                break;      // a short month simply carries fewer topics
            }
            // Status follows the DATE, so the grid stays believable whenever it
            // is regenerated: everything behind us shipped, the next few are in
            // review, the tail is still being planned.
            $daysOut = now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
            $status = match (true) {
                $daysOut < 0 => 'published',
                $daysOut <= 1 => 'ready',
                $daysOut <= 4 => 'scheduled',
                $hero === null => 'suggested',
                default => 'approved',
            };
            $topics[] = ContentTopic::factory()->create([
                'plan_id' => $plan->id,
                'website_id' => $website->id,
                'title' => $title,
                'target_keyword' => $kw,
                'status' => $status,
                'scheduled_for' => $date,
                'keyword_volume' => $volume,
                'published_at' => $status === 'published' ? $date : null,
                'source' => $i % 3 === 2 ? 'llm' : 'gsc_gap',
                'secondary_keywords' => ['crawl errors', 'index coverage', 'page speed'],
            ]);
        }
        ContentIntegration::create([
            'website_id' => $website->id,
            'platform' => 'wordpress',
            'status' => ContentIntegration::STATUS_CONNECTED,
            'credentials' => ['site_url' => 'https://brightpathdigital.com', 'user' => 'demo', 'app_password' => 'demo'],
        ]);

        // ── Hero thumbnails for the calendar cards ──────────────────────────
        // Real photographs, checked in under tests/fixtures/marketing/heroes —
        // they are OUR OWN article images, produced by this very pipeline for
        // the Serfix blog, so the screenshot shows what the product actually
        // makes. (They replaced GD-painted gradients on 2026-07-30: the
        // gradients made the calendar look like a placeholder.) They are copied
        // onto the public disk so the dumped HTML resolves real <img> URLs. No
        // client imagery, no image-API spend.
        config(['services.content.images_disk' => 'public']);
        @mkdir(storage_path('app/public/content/demo-shots'), 0755, true);
        $placeHero = function (int $i): string {
            $file = "content/demo-shots/hero-{$i}.webp";
            copy(base_path("tests/fixtures/marketing/heroes/hero-{$i}.webp"), storage_path('app/public/'.$file));

            return $file;
        };
        $attachHero = function (ContentArticle $article, int $i, string $alt) use ($placeHero): void {
            ContentImage::create([
                'article_id' => $article->id,
                'role' => ContentImage::ROLE_FEATURED,
                'status' => ContentImage::STATUS_GENERATED,
                'disk_path' => $placeHero($i),
                'alt_text' => $alt,
                'filename' => "hero-{$i}.webp",
            ]);
        };
        // Every topic that has a hero gets a lightweight current article; the
        // one without is still just a suggestion. Topic 0 gets the full sample
        // article BELOW (storeVersion moves is_current, so its hero must attach
        // to that later version, not one created here).
        foreach ($titles as $i => [, , $hero]) {
            if ($hero === null || $i === 0 || ! isset($topics[$i])) {
                continue;
            }
            $article = ContentArticle::storeVersion($topics[$i], [
                'h1' => $topics[$i]->title, 'html' => '<p>demo</p>',
                'seo_score' => [94, 88, 91, 96, 86, 90, 93, 89, 92][$i % 9], 'word_count' => 1180 + (($i * 137) % 900),
            ]);
            $attachHero($article, $hero, $topics[$i]->title);
        }

        // ── One finished article: preview + SEO score + checks ──────────────
        $topic = $topics[0];
        $sampleArticle = ContentArticle::storeVersion($topic, [
            'h1' => 'How to Do an SEO Audit: A Step-by-Step Guide for Beginners',
            'meta_title' => 'How to Do an SEO Audit: A Step-by-Step Guide',
            'meta_description' => 'A practical SEO audit walkthrough: what to check for crawling, indexing, on-page and speed problems, in what order, and how to turn findings into fixes.',
            'slug' => 'how-to-do-an-seo-audit',
            'focus_keyword' => 'seo audit',
            'seo_score' => 100,
            'word_count' => 984,
            'html' => $this->sampleArticleHtml(),
            'seo_issues' => [],
            'style_issues' => [],
            'generation_meta' => ['provider' => 'demo', 'model' => 'demo'],
        ]);
        $attachHero($sampleArticle, 0, $topic->title);

        // Calendar shots AFTER every article exists so cards carry their heroes.
        $this->dump('calendar', Livewire::test(ContentCalendar::class)->html());
        $this->dump('calendar-list', Livewire::test(ContentCalendar::class)->set('view', 'list')->html());
        $this->dump('settings', Livewire::test(ContentCalendar::class, ['mode' => 'settings'])->html());

        // ── Research page: keyword-ideas feed ───────────────────────────────
        $plan->forceFill(['keywords_classified_at' => now()->subDay()])->save();
        foreach ([
            ['seo audit checklist', 4400, 0.15, 'informational', 'gap'],
            ['how to fix crawl errors', 2900, 0.10, 'informational', 'gap'],
            ['best seo reporting tools', 1900, 0.45, 'commercial', 'gap'],
            ['what is core web vitals', 1600, 0.20, 'informational', 'own'],
            ['google search console tutorial', 880, 0.08, 'informational', 'gap'],
            ['xml sitemap best practices', 720, 0.55, 'informational', 'gap'],
            ['seo agency for small business', 590, 0.75, 'transactional', 'chosen'],
            ['what is an index coverage report', 480, 0.05, 'informational', 'gap'],
        ] as [$kw, $vol, $comp, $intent, $type]) {
            \App\Models\ContentPlanKeyword::create([
                'plan_id' => $plan->id, 'keyword' => $kw,
                'keyword_hash' => \App\Models\KeywordMetric::hashKeyword($kw),
                'type' => $type, 'country' => 'global', 'search_volume' => $vol,
                'competition' => $comp, 'search_intent' => $intent,
            ]);
        }
        $this->dump('research', Livewire::test(\App\Livewire\Content\ContentResearch::class)->html());

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
            ['how to do an seo audit', true, 4],
            ['seo audit checklist', false, 7],
            ['technical seo checklist', false, 11],
            ['how to use google search console', false, 3],
        ] as [$kw, $primary, $pos]) {
            $tracked[] = ContentTrackedKeyword::create([
                'website_id' => $website->id,
                'topic_id' => $topic->id,
                'keyword' => $kw,
                'normalized_keyword' => ContentTrackedKeyword::normalize($kw),
                'page_url' => 'https://brightpathdigital.com/blog/how-to-do-an-seo-audit',
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
                    'page' => 'https://brightpathdigital.com/blog/how-to-do-an-seo-audit',
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
                'url' => 'https://brightpathdigital.com/blog/how-to-do-an-seo-audit',
                'source' => ContentKeywordRankHistory::SOURCE_SERP,
            ]);
        }
        $this->dump('rank-history', Livewire::test(KeywordRankHistory::class, ['keywordId' => $tracked[0]->id])->html());

        $this->assertTrue(true, 'fixtures written to '.$this->out);
    }
    private function sampleArticleHtml(): string
    {
        return <<<'HTML'
<p>An SEO audit is a structured look at everything standing between your pages and the people searching for them. Work through it in order, starting with crawling, then indexing, then on-page, then speed, and each answer narrows the next question instead of leaving you with a pile of unrelated warnings.</p>
<div class="key-takeaways"><h2>Key takeaways</h2><ul><li>Start every SEO audit with crawling and indexing: a page Google can't reach can't rank.</li><li>Check one representative page per template rather than every URL on the site.</li><li>Fix crawl errors and duplicate titles before you touch keyword placement.</li><li>Re-run the audit quarterly, and after any redesign or migration.</li></ul></div>
<h2>What an SEO audit actually covers</h2>
<p>Most checklists are a flat list of two hundred items, which is why most audits stall. Group the work into four questions instead. Can search engines reach the page, are they allowed to keep it, does the page answer the query, and does it load fast enough to be worth showing. Everything worth checking sits under one of those four.</p>
<p>Order matters more than coverage. Rewriting a title tag on a page that's blocked in robots.txt changes nothing, so the crawling layer comes first and the polish comes last. That ordering is the difference between an audit that produces fixes and one that produces a spreadsheet nobody opens twice.</p>
<h2>Step one: crawling</h2>
<p>Start with the robots.txt file and the XML sitemap, because between them they decide what a crawler even attempts. A disallow rule left over from a staging site is the single most common reason a section vanishes from search, and it costs nothing to rule out in the first minute of an SEO audit.</p>
<p>Then crawl the site yourself and look at the response codes. A handful of 404s from old campaigns is normal. The crawl errors worth acting on are the patterns: long redirect chains, soft 404s returning 200, and internal links still pointing at http when the site is https. Each one wastes crawl budget and muddies the signals you're trying to send.</p>
<p>Fix the template, not the row. One bad partial in a theme usually explains hundreds of broken links, and correcting it there clears the whole list at once. If you fix URLs individually you'll be back next quarter with the same report.</p>
<h2>Step two: indexing</h2>
<p>Reaching a page and keeping it are different things. The index coverage report tells you which pages Google chose to store and which it discarded, and the reasons it gives, like "Discovered, currently not indexed" or "Duplicate without user-selected canonical", are diagnostic rather than decorative. <a href="https://developers.google.com/search/docs" rel="nofollow">Google Search Central</a> documents what each state means.</p>
<p>Two patterns account for most exclusions. The first is duplication: filtered, paginated or session URLs producing near-identical pages, none of which is clearly canonical. The second is thinness: tag archives and location pages that carry a heading and little else. Canonicalise the first group, then consolidate or expand the second.</p>
<h2>Step three: on-page</h2>
<p>Only now does the writing come into it. Take one page per template, so a service page, a blog post and a category, and check the obvious things. One H1 that matches what the page is for, a title tag that would earn the click, a meta description that isn't truncated, and headings that describe sections rather than decorate them.</p>
<p>Look for pages competing with each other. Two articles targeting the same query split their own links and impressions, and the one Google picks is rarely the one you'd have chosen. Merging them into a single stronger page is usually the highest-value fix an SEO audit produces, and it's invisible to any tool that only checks pages in isolation.</p>
<p>While you're there, check that each page links out to the others it should. Internal links are the cheapest ranking signal you control, and most sites leave their best pages three clicks deep with nothing pointing at them.</p>
<h2>Step four: page speed and stability</h2>
<p>Core Web Vitals measure how the page feels while it loads: how long the main content takes to appear, whether the layout jumps as it does, and how quickly it responds to a tap. Field data beats lab data here, because it reflects the devices and connections your visitors actually have rather than a fast machine on a fast line.</p>
<p>The usual page speed culprits are unsized images, render-blocking scripts, and fonts that arrive late and reflow the text. None of them need a rebuild. Set explicit width and height on images, defer whatever isn't needed for the first screen, and the numbers usually move without anyone touching the design.</p>
<h2>Turning findings into fixes</h2>
<p>An audit that ends in a document hasn't finished. Sort every finding by how many pages it affects and how hard it is to change, then take the top of that list to whoever owns the code. Blocked pages and broken canonicals are one-line changes with site-wide effects, so they go first. Rewriting individual meta descriptions can wait.</p>
<p>Record the date, the issues found, and the pages affected. The next SEO audit is far quicker when you can see what was already fixed, what came back, and which templates keep producing the same problem month after month.</p>
<h2>Frequently asked questions about running an SEO audit</h2>
<h3>How long does an SEO audit take?</h3>
<p>A focused pass on a small site takes a few hours. Large sites with many templates take longer, but the extra time goes into sampling more templates, not into checking more URLs.</p>
<h3>How often should I run one?</h3>
<p>Quarterly is enough for most sites, plus an extra pass straight after a redesign, a platform change or a domain migration. Those are the moments when crawling and indexing problems get introduced.</p>
<h3>Do I need paid tools to do this?</h3>
<p>No. Search Console covers indexing and performance, and a crawler covers response codes and duplication. Paid tools mostly save time and add competitive context rather than revealing anything the free ones hide.</p>
HTML;
    }
}
