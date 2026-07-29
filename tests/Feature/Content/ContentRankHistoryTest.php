<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\KeywordRankHistory;
use App\Models\ContentKeywordRankHistory;
use App\Models\ContentTrackedKeyword;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentRankHistoryService;
use App\Services\Content\ContentSerpChecker;
use App\Services\SerperSearchClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rank history: every live SERP check is appended to
 * content_keyword_rank_history (the tracked-keyword row only ever holds the
 * latest position), and the per-keyword history page reads it back.
 */
class ContentRankHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function tracked(Website $website, string $kw = 'seo dubai'): ContentTrackedKeyword
    {
        return ContentTrackedKeyword::create([
            'website_id' => $website->id,
            'keyword' => $kw,
            'normalized_keyword' => ContentTrackedKeyword::normalize($kw),
            'source' => ContentTrackedKeyword::SOURCE_AUTO,
            'page_url' => 'https://daomarketing.com/blog/seo-dubai',
        ]);
    }

    private function website(?User $user = null): Website
    {
        return Website::factory()->for($user ?? User::factory()->create())->create(['domain' => 'daomarketing.com']);
    }

    private function fakeSerp(int $position): void
    {
        $this->mock(SerperSearchClient::class, function ($m) use ($position) {
            $m->shouldReceive('query')->andReturn(['organic' => [
                ['position' => $position, 'link' => 'https://daomarketing.com/blog/seo-dubai'],
            ]]);
        });
    }

    public function test_each_check_is_appended_to_history(): void
    {
        $website = $this->website();
        $row = $this->tracked($website);

        $this->fakeSerp(7);
        app(ContentSerpChecker::class)->check($row->fresh());

        $history = ContentKeywordRankHistory::query()->where('website_id', $website->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame(7, $history->first()->position);
        $this->assertSame($row->normalized_keyword, $history->first()->normalized_keyword);
        $this->assertSame(today()->toDateString(), $history->first()->checked_on->toDateString());
    }

    public function test_a_same_day_recheck_corrects_the_point_instead_of_duplicating_it(): void
    {
        $website = $this->website();
        $row = $this->tracked($website);

        $this->fakeSerp(9);
        app(ContentSerpChecker::class)->check($row->fresh());
        $this->fakeSerp(4);
        app(ContentSerpChecker::class)->check($row->fresh());

        $history = ContentKeywordRankHistory::query()->where('website_id', $website->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame(4, $history->first()->position);
    }

    public function test_history_survives_untracking_and_re_adding_the_keyword(): void
    {
        $website = $this->website();
        $row = $this->tracked($website);
        $this->fakeSerp(12);
        app(ContentSerpChecker::class)->check($row->fresh());

        $row->delete(); // untrack frees a quota slot

        $history = ContentKeywordRankHistory::query()->where('website_id', $website->id)->get();
        $this->assertCount(1, $history, 'history must outlive the tracked-keyword row');
        $this->assertNull($history->first()->tracked_keyword_id);

        $again = $this->tracked($website);
        $series = app(ContentRankHistoryService::class)->series($website, $again, 90);
        $this->assertTrue($series['has_rank_history']);
        $this->assertSame(12, $series['stats']['best']);
    }

    public function test_series_reports_movement_best_and_gaps(): void
    {
        $website = $this->website();
        $row = $this->tracked($website);

        foreach ([[20, 21], [11, 14], [6, 7]] as [$pos, $daysAgo]) {
            ContentKeywordRankHistory::create([
                'website_id' => $website->id,
                'tracked_keyword_id' => $row->id,
                'normalized_keyword' => $row->normalized_keyword,
                'checked_on' => today()->subDays($daysAgo)->toDateString(),
                'position' => $pos,
                'source' => ContentKeywordRankHistory::SOURCE_SERP,
            ]);
        }

        $series = app(ContentRankHistoryService::class)->series($website, $row, 30);

        $this->assertSame(6, $series['stats']['best']);
        $this->assertSame(20, $series['stats']['worst']);
        $this->assertSame(14, $series['stats']['change'], 'moved up 20 → 6 = +14 places');
        $this->assertSame(3, $series['stats']['checks']);
        $this->assertCount(30, $series['points']);
        // Only the checked days carry a rank; the rest are gaps, never zeros.
        $ranked = array_filter($series['points'], fn ($p) => $p['rank'] !== null);
        $this->assertCount(3, $ranked);
        // Newest check first in the log.
        $this->assertSame(6, $series['checks'][0]['position']);
    }

    public function test_history_page_renders_for_the_owner(): void
    {
        $user = User::factory()->create();
        $website = $this->website($user);
        $row = $this->tracked($website);
        ContentKeywordRankHistory::create([
            'website_id' => $website->id,
            'tracked_keyword_id' => $row->id,
            'normalized_keyword' => $row->normalized_keyword,
            'checked_on' => today()->subDays(3)->toDateString(),
            'position' => 5,
            'source' => ContentKeywordRankHistory::SOURCE_SERP,
        ]);

        Livewire::actingAs($user)
            ->test(KeywordRankHistory::class, ['keywordId' => $row->id])
            ->assertOk()
            ->assertSee('seo dubai')
            ->assertSee('#5')
            ->call('setRange', 180)
            ->assertSet('range', 180)
            ->call('setRange', 7)          // not an offered range
            ->assertSet('range', 180);
    }

    public function test_history_page_is_not_reachable_for_another_users_keyword(): void
    {
        $row = $this->tracked($this->website());
        $stranger = User::factory()->create();

        Livewire::actingAs($stranger)
            ->test(KeywordRankHistory::class, ['keywordId' => $row->id])
            ->assertStatus(404);
    }
}
