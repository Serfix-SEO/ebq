<?php

namespace Tests\Feature;

use App\Jobs\RunGuestKeywordVolume;
use App\Models\GuestKeywordVolume;
use App\Models\KeywordMetric;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestKeywordVolumeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config([
            'services.recaptcha.site_key' => '', 'services.recaptcha.secret_key' => '',
            'services.keywords_everywhere.key' => 'k',
            'services.keywords_everywhere.base_url' => 'https://api.keywordseverywhere.com',
            'services.keywords_everywhere.fresh_days' => 30,
        ]);
    }

    public function test_tool_page_loads(): void
    {
        $this->get(route('tools.keyword-volume'))->assertOk()->assertSee('volume checker', false);
    }

    public function test_keyword_is_required(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        $this->postJson(route('guest-volume.store'), ['keyword' => ''])->assertStatus(422);
    }

    public function test_invalid_country_is_rejected(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        $this->postJson(route('guest-volume.store'), ['keyword' => 'seo tools', 'country' => 'zz'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('country');
    }

    public function test_unconfigured_keywords_everywhere_is_handled(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        config(['services.keywords_everywhere.key' => '']);
        $this->postJson(route('guest-volume.store'), ['keyword' => 'seo tools'])->assertStatus(503);
    }

    public function test_first_check_is_free_shown_on_screen_and_queued(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        Queue::fake();

        $r = $this->postJson(route('guest-volume.store'), ['keyword' => 'best seo tools', 'country' => 'us']);

        $r->assertStatus(202)
            ->assertJsonPath('emailed', false)
            ->assertJsonStructure(['results_url', 'status_url', 'token']);

        $this->assertDatabaseHas('guest_keyword_volumes', [
            'keyword' => 'best seo tools', 'country' => 'us', 'email' => null,
        ]);
        Queue::assertPushed(RunGuestKeywordVolume::class, 1);
    }

    public function test_job_serves_a_fresh_cached_keyword_without_calling_ke(): void
    {
        Http::fake(); // any call would be recorded; we assert none happens

        KeywordMetric::create([
            'keyword' => 'best seo tools',
            'keyword_hash' => KeywordMetric::hashKeyword('best seo tools'),
            'country' => 'us',
            'data_source' => 'gkp',
            'search_volume' => 5400,
            'cpc' => 3.10,
            'currency' => 'USD',
            'competition' => 0.42,
            'trend_12m' => null,
            'fetched_at' => Carbon::now()->subDay(),
            'expires_at' => Carbon::now()->addDays(29),
        ]);

        $row = GuestKeywordVolume::start('best seo tools', 'us');
        Bus::dispatchSync(new RunGuestKeywordVolume($row->id));

        $fresh = $row->fresh();
        $this->assertSame(GuestKeywordVolume::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(5400, $fresh->result['volume']);
        $this->assertTrue($fresh->result['cached']);
        Http::assertNothingSent();
    }

    public function test_job_fetches_and_caches_on_a_miss(): void
    {
        Http::fake([
            'api.keywordseverywhere.com/*' => Http::response([
                'data' => [[
                    'keyword' => 'long tail keyword',
                    'vol' => 880,
                    'cpc' => ['currency' => 'USD', 'value' => 1.25],
                    'competition' => 0.31,
                    'trend' => [['month' => 1, 'year' => 2026, 'value' => 700]],
                ]],
                'credits' => 9999,
            ], 200),
        ]);

        $row = GuestKeywordVolume::start('long tail keyword', 'global');
        Bus::dispatchSync(new RunGuestKeywordVolume($row->id));

        $fresh = $row->fresh();
        $this->assertSame(GuestKeywordVolume::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(880, $fresh->result['volume']);
        $this->assertFalse($fresh->result['cached']);
        // It wrote into the shared cache so future lookups (any user / GSC) reuse it.
        $this->assertDatabaseHas('keyword_metrics', [
            'keyword_hash' => KeywordMetric::hashKeyword('long tail keyword'),
            'country' => 'global',
            'search_volume' => 880,
        ]);
    }

    public function test_results_page_renders_completed_report(): void
    {
        $row = GuestKeywordVolume::create([
            'token' => (string) Str::uuid(),
            'keyword' => 'best seo tools',
            'country' => 'us',
            'status' => GuestKeywordVolume::STATUS_COMPLETED,
            'result' => [
                'keyword' => 'best seo tools', 'country' => 'us',
                'volume' => 5400, 'cpc' => 3.10, 'currency' => 'USD', 'competition' => 0.42,
                'trend' => [['month' => 1, 'year' => 2026, 'value' => 700]],
                'cached' => false, 'fetched_at' => now()->toIso8601String(),
            ],
        ]);

        $this->get(route('guest-volume.show', $row))
            ->assertOk()
            ->assertSee('Monthly searches', false)
            ->assertSee('5,400', false)
            ->assertSee('Start free', false);
    }
}
