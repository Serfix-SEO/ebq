<?php

namespace Tests\Feature\Content;

use App\Jobs\CheckTrackedKeywordSerpJob;
use App\Models\ContentTrackedKeyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentSerpChecker;
use App\Services\SerperSearchClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContentSerpCheckerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function trackedRow(Website $website, string $kw = 'seo dubai'): ContentTrackedKeyword
    {
        return ContentTrackedKeyword::create([
            'website_id' => $website->id,
            'keyword' => $kw,
            'normalized_keyword' => ContentTrackedKeyword::normalize($kw),
            'source' => ContentTrackedKeyword::SOURCE_AUTO,
        ]);
    }

    public function test_checker_records_domain_position(): void
    {
        $website = Website::factory()->for(User::factory()->create())->create(['domain' => 'daomarketing.com']);
        $row = $this->trackedRow($website);

        $this->mock(SerperSearchClient::class, function ($m) {
            $m->shouldReceive('query')->once()->andReturn([
                'organic' => [
                    ['position' => 1, 'link' => 'https://competitor.com/x'],
                    ['position' => 2, 'link' => 'https://www.daomarketing.com/blog/seo-dubai', 'title' => 'SEO Dubai'],
                ],
            ]);
        });

        app(ContentSerpChecker::class)->check($row->fresh());

        $row->refresh();
        $this->assertSame(2, $row->serp_position);
        $this->assertStringContainsString('daomarketing.com', $row->serp_url);
        $this->assertNotNull($row->serp_checked_at);
    }

    public function test_checker_stamps_null_when_not_ranking(): void
    {
        $website = Website::factory()->for(User::factory()->create())->create(['domain' => 'daomarketing.com']);
        $row = $this->trackedRow($website);

        $this->mock(SerperSearchClient::class, function ($m) {
            $m->shouldReceive('query')->andReturn(['organic' => [
                ['position' => 1, 'link' => 'https://someoneelse.com/'],
            ]]);
        });

        app(ContentSerpChecker::class)->check($row->fresh());

        $row->refresh();
        $this->assertNull($row->serp_position);
        $this->assertNotNull($row->serp_checked_at); // checked, just not found
    }

    public function test_checker_leaves_row_untouched_on_api_failure(): void
    {
        $website = Website::factory()->for(User::factory()->create())->create(['domain' => 'daomarketing.com']);
        $row = $this->trackedRow($website);

        $this->mock(SerperSearchClient::class, function ($m) {
            $m->shouldReceive('query')->andReturn(null); // no key / API down
        });

        app(ContentSerpChecker::class)->check($row->fresh());

        $row->refresh();
        $this->assertNull($row->serp_checked_at); // not stamped → retried next run
    }

    public function test_job_only_checks_stale_or_unchecked_rows(): void
    {
        $website = Website::factory()->for(User::factory()->create())->create(['domain' => 'daomarketing.com']);
        $fresh = $this->trackedRow($website, 'fresh kw');
        $fresh->forceFill(['serp_checked_at' => now()->subDay()])->save(); // checked yesterday → skip
        $stale = $this->trackedRow($website, 'stale kw');
        $stale->forceFill(['serp_checked_at' => now()->subDays(10)])->save(); // >7d → check
        $never = $this->trackedRow($website, 'never kw'); // null → check

        $seen = [];
        $this->mock(ContentSerpChecker::class, function ($m) use (&$seen) {
            $m->shouldReceive('check')->andReturnUsing(function (ContentTrackedKeyword $kw) use (&$seen) {
                $seen[] = $kw->normalized_keyword;
            });
        });

        (new CheckTrackedKeywordSerpJob($website->id))->handle(app(ContentSerpChecker::class));

        sort($seen);
        $this->assertSame(['never kw', 'stale kw'], $seen);
    }
}
