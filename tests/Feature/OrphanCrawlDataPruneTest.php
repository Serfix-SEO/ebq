<?php

namespace Tests\Feature;

use App\Models\CrawlSite;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrphanCrawlDataPruneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The prune targets rows whose crawl_site_id DANGLES — the prod shape,
     * where website_pages carries no foreign key. sqlite here still has the
     * migration's FK, so build the dangling row directly instead of deleting a
     * parent (which sqlite would turn into a NULL).
     */
    private function danglingPage(string $url): string
    {
        $id = (string) Str::ulid();
        // `defer_foreign_keys`, not `foreign_keys`: sqlite ignores the latter
        // inside RefreshDatabase's open transaction, while the former defers
        // enforcement to a COMMIT that never comes (the test rolls back). This
        // buys the dangling-id shape prod actually has — prod's website_pages
        // carries no foreign key on crawl_site_id at all.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('website_pages')->insert([
            'id' => $id,
            'crawl_site_id' => (string) Str::ulid(), // no such crawl site
            'url' => $url,
            'url_hash' => WebsitePage::hashUrl($url),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_dry_run_reports_orphans_without_deleting(): void
    {
        $orphan = $this->danglingPage('https://gone.example/a');

        $this->artisan('ebq:prune-orphan-crawl-data')
            ->expectsOutputToContain('would delete')
            ->assertSuccessful();

        $this->assertNotNull(DB::table('website_pages')->find($orphan));
    }

    public function test_force_deletes_orphans_and_spares_live_rows(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.com']);
        $site = CrawlSite::find($website->crawl_site_id);

        $keep = WebsitePage::create([
            'crawl_site_id' => $site->id,
            'url' => 'https://example.com/keep',
            'url_hash' => WebsitePage::hashUrl('https://example.com/keep'),
        ]);
        $orphan = $this->danglingPage('https://gone.example/a');

        $this->artisan('ebq:prune-orphan-crawl-data --force')->assertSuccessful();

        $this->assertNull(DB::table('website_pages')->find($orphan));
        $this->assertNotNull(DB::table('website_pages')->find($keep->id));
    }

    public function test_reports_nothing_when_the_store_is_clean(): void
    {
        $this->artisan('ebq:prune-orphan-crawl-data')
            ->expectsOutputToContain('No orphaned crawl rows.')
            ->assertSuccessful();
    }
}
