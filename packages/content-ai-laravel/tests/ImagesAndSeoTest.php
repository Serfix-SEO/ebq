<?php

namespace Serfix\ContentAi\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Serfix\ContentAi\Models\Article;
use Serfix\ContentAi\Services\ImageLocalizer;
use Serfix\ContentAi\Services\MetaBuilder;
use Serfix\ContentAi\Services\SchemaBuilder;

class ImagesAndSeoTest extends TestCase
{
    /**
     * The point of the package: a published page must never fetch from Serfix
     * storage. Otherwise every client site bills our bucket and breaks when a
     * file moves.
     */
    public function test_images_are_downloaded_and_the_html_points_at_the_local_copy(): void
    {
        config(['content-ai.images.localize' => true]);
        Storage::fake('public');
        Http::fake(['nbg1.your-objectstorage.com/*' => Http::response('PNGBYTES', 200)]);

        $remote = 'https://nbg1.your-objectstorage.com/serfix/content/images/01ABC.png';
        $this->deliver($this->articlePayload([
            'article' => ['html' => '<p>Intro</p><img src="'.$remote.'" alt="A clan logo">'],
        ]))->assertOk();

        $article = Article::query()->first();

        $this->assertStringNotContainsString('nbg1.your-objectstorage.com', $article->html);
        $this->assertSame(1, $article->images()->count());
        Storage::disk('public')->assertExists($article->images()->first()->path);
    }

    /** A download failure must degrade to a hotlink, never to a broken image. */
    public function test_a_failed_download_leaves_the_original_src_intact(): void
    {
        config(['content-ai.images.localize' => true]);
        Storage::fake('public');
        Http::fake(['nbg1.your-objectstorage.com/*' => Http::response('', 500)]);

        $remote = 'https://nbg1.your-objectstorage.com/serfix/content/images/broken.png';
        $this->deliver($this->articlePayload([
            'article' => ['html' => '<img src="'.$remote.'">'],
        ]))->assertOk();

        $article = Article::query()->first();
        $this->assertStringContainsString($remote, $article->html);
        $this->assertSame(0, $article->images()->count());
    }

    /**
     * SSRF guard: a payload naming an internal address must never make this
     * server fetch it. Cloud metadata endpoints are the classic target.
     */
    public function test_only_allow_listed_https_hosts_are_ever_fetched(): void
    {
        $localizer = app(ImageLocalizer::class);

        $this->assertTrue($localizer->isFetchable('https://nbg1.your-objectstorage.com/a.png'));
        $this->assertFalse($localizer->isFetchable('http://nbg1.your-objectstorage.com/a.png'), 'plain http');
        $this->assertFalse($localizer->isFetchable('https://169.254.169.254/latest/meta-data/'), 'metadata service');
        $this->assertFalse($localizer->isFetchable('https://localhost/a.png'));
        $this->assertFalse($localizer->isFetchable('file:///etc/passwd'));
        // Suffix matching must not be fooled by a lookalike domain.
        $this->assertFalse($localizer->isFetchable('https://evil-your-objectstorage.com/a.png'));
    }

    public function test_re_delivery_reuses_the_stored_image_instead_of_downloading_again(): void
    {
        config(['content-ai.images.localize' => true]);
        Storage::fake('public');
        Http::fake(['nbg1.your-objectstorage.com/*' => Http::response('PNGBYTES', 200)]);

        $payload = $this->articlePayload([
            'article' => ['html' => '<img src="https://nbg1.your-objectstorage.com/x.png">'],
        ]);
        $this->deliver($payload)->assertOk();
        $this->deliver($this->articlePayload([
            'event' => 'article.updated',
            'external_id' => Article::query()->first()->id,
            'article' => ['html' => '<img src="https://nbg1.your-objectstorage.com/x.png"><p>More.</p>'],
        ]))->assertOk();

        $this->assertSame(1, Article::query()->first()->images()->count());
        Http::assertSentCount(1);
    }

    public function test_meta_covers_title_description_canonical_og_and_twitter(): void
    {
        $this->deliver($this->articlePayload())->assertOk();
        $meta = app(MetaBuilder::class)->for(Article::query()->first());

        $this->assertSame('Best PUBG Clan Names: 150+ Ideas', $meta['title']);
        $this->assertSame('A list of clan names that actually work.', $meta['description']);
        $this->assertSame('index, follow', $meta['robots']);
        $this->assertStringContainsString('/blog/best-pubg-clan-names', $meta['canonical']);
        $this->assertSame('article', $meta['og']['og:type']);
        $this->assertArrayHasKey('twitter:card', $meta['twitter']);
    }

    /** Drafts are reachable by signed link — Google follows those, so noindex. */
    public function test_unpublished_articles_are_marked_noindex(): void
    {
        config(['content-ai.publishing.auto_publish' => false]);
        $this->deliver($this->articlePayload())->assertOk();

        $meta = app(MetaBuilder::class)->for(Article::query()->first());
        $this->assertSame('noindex, nofollow', $meta['robots']);
    }

    public function test_schema_emits_blogposting_and_breadcrumbs(): void
    {
        $this->deliver($this->articlePayload())->assertOk();
        $json = app(SchemaBuilder::class)->toJson(Article::query()->first());
        $decoded = json_decode($json, true);

        $types = array_column($decoded['@graph'], '@type');
        $this->assertContains('BlogPosting', $types);
        $this->assertContains('BreadcrumbList', $types);

        $posting = $decoded['@graph'][0];
        $this->assertSame('Best PUBG Clan Names: 150+ Ideas', $posting['headline']);
        $this->assertSame('en', $posting['inLanguage']);
        $this->assertSame(1200, $posting['wordCount']);
    }

    /** Content AI already writes an FAQ block; lifting it is free rich-result eligibility. */
    public function test_an_faq_block_becomes_a_faqpage_node(): void
    {
        $this->deliver($this->articlePayload([
            'article' => [
                'html' => '<section class="faq"><h2>Is it free?</h2><p>Yes, entirely.</p>'
                    .'<h2>How long does it take?</h2><p>About five minutes.</p></section>',
            ],
        ]))->assertOk();

        $decoded = json_decode(app(SchemaBuilder::class)->toJson(Article::query()->first()), true);
        $faq = collect($decoded['@graph'])->firstWhere('@type', 'FAQPage');

        $this->assertNotNull($faq);
        $this->assertCount(2, $faq['mainEntity']);
        $this->assertSame('Is it free?', $faq['mainEntity'][0]['name']);
        $this->assertSame('Yes, entirely.', $faq['mainEntity'][0]['acceptedAnswer']['text']);
    }
}
