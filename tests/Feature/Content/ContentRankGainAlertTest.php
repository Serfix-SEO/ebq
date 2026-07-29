<?php

namespace Tests\Feature\Content;

use App\Jobs\CheckTrackedKeywordSerpJob;
use App\Mail\ContentRankGainsMail;
use App\Models\ContentKeywordRankHistory;
use App\Models\ContentTrackedKeyword;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentSerpChecker;
use App\Services\SerperSearchClient;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Your rankings moved up" digest: one email per website per rank-check run,
 * only for moves worth reporting (noise floor + milestone crossings).
 */
class ContentRankGainAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    private function website(): Website
    {
        return Website::factory()
            ->for(User::factory()->create(['email' => 'owner@example.com', 'name' => 'Owner']))
            ->create(['domain' => 'daomarketing.com']);
    }

    private function tracked(Website $website, string $kw): ContentTrackedKeyword
    {
        return ContentTrackedKeyword::create([
            'website_id' => $website->id,
            'keyword' => $kw,
            'normalized_keyword' => ContentTrackedKeyword::normalize($kw),
            'source' => ContentTrackedKeyword::SOURCE_AUTO,
        ]);
    }

    /** Seed the "previous check" this keyword will be compared against. */
    private function priorPosition(ContentTrackedKeyword $kw, ?int $position, int $daysAgo = 7): void
    {
        ContentKeywordRankHistory::create([
            'website_id' => $kw->website_id,
            'tracked_keyword_id' => $kw->id,
            'normalized_keyword' => $kw->normalized_keyword,
            'checked_on' => today()->subDays($daysAgo)->toDateString(),
            'position' => $position,
            'source' => ContentKeywordRankHistory::SOURCE_SERP,
        ]);
    }

    /** @param array<string,int> $positions keyword => position Serper reports */
    private function fakeSerp(array $positions): void
    {
        $this->mock(SerperSearchClient::class, function ($m) use ($positions) {
            $m->shouldReceive('query')->andReturnUsing(function (array $args) use ($positions) {
                $pos = $positions[$args['q']] ?? 90;

                return ['organic' => [['position' => $pos, 'link' => 'https://daomarketing.com/blog/x']]];
            });
        });
    }

    private function runJob(Website $website): void
    {
        (new CheckTrackedKeywordSerpJob($website->id))->handle(app(ContentSerpChecker::class));
    }

    public function test_a_real_climb_emails_the_owner(): void
    {
        Mail::fake();
        $website = $this->website();
        $kw = $this->tracked($website, 'seo dubai');
        $this->priorPosition($kw, 8);
        $this->fakeSerp(['seo dubai' => 3]);

        $this->runJob($website);

        Mail::assertQueued(ContentRankGainsMail::class, function (ContentRankGainsMail $mail) {
            $top = $mail->headline();

            return $mail->hasTo('owner@example.com')
                && count($mail->movements) === 1
                && $top['previous'] === 8
                && $top['current'] === 3
                && $top['gain'] === 5
                && $top['milestone'] === 'top_3';
        });
    }

    public function test_noise_sized_moves_and_drops_do_not_email(): void
    {
        Mail::fake();
        $website = $this->website();
        $up = $this->tracked($website, 'small climb');   // 22 → 21, below the floor
        $down = $this->tracked($website, 'slipped');     // 12 → 30
        $this->priorPosition($up, 22);
        $this->priorPosition($down, 12);
        $this->fakeSerp(['small climb' => 21, 'slipped' => 30]);

        $this->runJob($website);

        Mail::assertNothingQueued();
    }

    public function test_a_single_place_move_still_emails_when_it_crosses_a_milestone(): void
    {
        Mail::fake();
        $website = $this->website();
        $kw = $this->tracked($website, 'page one push');
        $this->priorPosition($kw, 11);                   // 11 → 10 is one place…
        $this->fakeSerp(['page one push' => 10]);        // …but it reaches page 1

        $this->runJob($website);

        Mail::assertQueued(ContentRankGainsMail::class, function (ContentRankGainsMail $mail) {
            return $mail->headline()['milestone'] === 'page_1';
        });
    }

    public function test_first_time_ranking_counts_as_a_win(): void
    {
        Mail::fake();
        $website = $this->website();
        $kw = $this->tracked($website, 'brand new');
        $this->priorPosition($kw, null);                 // checked before, wasn't in the top 100
        $this->fakeSerp(['brand new' => 46]);

        $this->runJob($website);

        Mail::assertQueued(ContentRankGainsMail::class, function (ContentRankGainsMail $mail) {
            $top = $mail->headline();

            return $top['milestone'] === 'now_ranking' && $top['previous'] === null;
        });
    }

    public function test_one_digest_per_website_per_day(): void
    {
        Mail::fake();
        $website = $this->website();
        $kw = $this->tracked($website, 'seo dubai');
        $this->priorPosition($kw, 20);
        $this->fakeSerp(['seo dubai' => 4]);

        $this->runJob($website);
        // The manual "Check rank now" button dispatches the same job again.
        $kw->fresh()->forceFill(['serp_checked_at' => null])->save();
        $this->runJob($website);

        Mail::assertQueuedCount(1);
    }

    public function test_the_digest_can_be_switched_off_without_a_deploy(): void
    {
        Mail::fake();
        Setting::set('content.rank_alerts.enabled', false);
        $website = $this->website();
        $kw = $this->tracked($website, 'seo dubai');
        $this->priorPosition($kw, 20);
        $this->fakeSerp(['seo dubai' => 2]);

        $this->runJob($website);

        Mail::assertNothingQueued();
    }

    public function test_digest_renders_the_biggest_win_first(): void
    {
        $website = $this->website();
        $small = $this->tracked($website, 'modest gain');
        $big = $this->tracked($website, 'headline keyword');
        $this->priorPosition($small, 30);
        $this->priorPosition($big, 40);

        $html = (new ContentRankGainsMail($website->owner, $website, [
            ['keyword_id' => $small->id, 'keyword' => 'modest gain', 'previous' => 30, 'current' => 24, 'gain' => 6, 'milestone' => null],
            ['keyword_id' => $big->id, 'keyword' => 'headline keyword', 'previous' => 40, 'current' => 2, 'gain' => 38, 'milestone' => 'top_3'],
        ]))->render();

        $this->assertStringContainsString('headline keyword', $html);
        $this->assertStringContainsString('modest gain', $html);
        $this->assertStringContainsString('#2', $html);
        // Milestone move is promoted above the plain climb.
        $this->assertLessThan(
            strpos($html, 'modest gain'),
            strpos($html, 'headline keyword'),
        );
    }
}
