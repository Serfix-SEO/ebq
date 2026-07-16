<?php

namespace Tests\Feature;

use App\Services\LinkGraph\EdgeRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EdgeRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_external_links_as_domain_edges(): void
    {
        $written = app(EdgeRecorder::class)->record('https://blog.example.com/post/1', [
            ['href' => 'https://gearshop.test/products', 'anchor' => 'best hiking gear', 'nofollow' => false],
            ['href' => 'https://gearshop.test/about', 'anchor' => 'about', 'nofollow' => true], // same target domain → collapses
            ['href' => 'https://other.test/', 'anchor' => 'https://other.test', 'nofollow' => true],
            ['href' => 'https://www.example.com/internal', 'anchor' => 'self'], // same registrable → skipped
            ['href' => 'not-a-url', 'anchor' => 'junk'],
        ], EdgeRecorder::SOURCE_OWN_CRAWL);

        $this->assertSame(2, $written);
        $this->assertSame(3, DB::table('link_domains')->count()); // example.com + gearshop.test + other.test

        $edges = DB::table('link_edges')
            ->join('link_domains as t', 't.id', '=', 'link_edges.to_domain_id')
            ->pluck('link_edges.dofollow', 't.name');
        $this->assertTrue((bool) $edges['gearshop.test']); // any dofollow sighting wins
        $this->assertFalse((bool) $edges['other.test']);

        $this->assertSame('text', DB::table('link_edges')
            ->join('link_domains as t', 't.id', '=', 'link_edges.to_domain_id')
            ->where('t.name', 'gearshop.test')->value('anchor_class'));
        $this->assertSame('naked', DB::table('link_edges')
            ->join('link_domains as t', 't.id', '=', 'link_edges.to_domain_id')
            ->where('t.name', 'other.test')->value('anchor_class'));

        // from_url captured for page-level granularity later.
        $this->assertSame(1, DB::table('link_urls')->count());
        $this->assertSame('/post/1', DB::table('link_urls')->value('path'));
    }

    public function test_resighting_updates_last_seen_not_duplicate(): void
    {
        $recorder = app(EdgeRecorder::class);
        $links = [['href' => 'https://gearshop.test/', 'anchor' => 'shop', 'nofollow' => true]];

        $recorder->record('https://blog.example.com/a', $links);
        $first = DB::table('link_edges')->first();

        $this->travel(3)->days();
        $recorder->record('https://blog.example.com/b', [['href' => 'https://gearshop.test/', 'anchor' => 'shop', 'nofollow' => false]]);

        $this->assertSame(1, DB::table('link_edges')->count()); // still one edge
        $edge = DB::table('link_edges')->first();
        $this->assertTrue((bool) $edge->dofollow); // sticky-true upgrade
        $this->assertNotEquals($first->last_seen_at, $edge->last_seen_at);
        $this->assertEquals($first->first_seen_at, $edge->first_seen_at); // write-once
    }

    public function test_record_inbound_persists_provider_backlinks(): void
    {
        $recorder = app(EdgeRecorder::class);

        $written = $recorder->recordInbound('victim.test', [
            ['url_from' => 'https://spam-seller.site/p1', 'url_to' => 'https://victim.test/a', 'anchor' => 'buy links', 'dofollow' => false],
            ['url_from' => 'https://spam-seller.site/p2', 'url_to' => 'https://victim.test/b', 'anchor' => 'buy links', 'dofollow' => true], // same source domain → one edge, dofollow wins
            ['url_from' => 'https://legitblog.com/review', 'url_to' => 'https://victim.test/', 'anchor' => 'nice tool', 'dofollow' => true],
        ]);

        $this->assertSame(2, $written);
        $edges = DB::table('link_edges')
            ->join('link_domains as f', 'f.id', '=', 'link_edges.from_domain_id')
            ->where('link_edges.source', 'provider')
            ->pluck('link_edges.dofollow', 'f.name');
        $this->assertTrue((bool) $edges['spam-seller.site']);
        $this->assertTrue((bool) $edges['legitblog.com']);

        // Re-sighting on a later regeneration → same edge, last_seen bumped.
        $this->travel(2)->days();
        $recorder->recordInbound('victim.test', [
            ['url_from' => 'https://legitblog.com/review', 'url_to' => 'https://victim.test/', 'anchor' => 'nice tool', 'dofollow' => true],
        ]);
        $this->assertSame(2, DB::table('link_edges')->where('source', 'provider')->count());
    }

    public function test_recorder_never_throws_on_broken_schema(): void
    {
        \Illuminate\Support\Facades\Schema::drop('link_edges');

        $written = app(EdgeRecorder::class)->record('https://a.test/x', [
            ['href' => 'https://b.test/', 'anchor' => 'x'],
        ]);

        $this->assertSame(0, $written);
    }
}
