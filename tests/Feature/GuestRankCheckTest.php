<?php

namespace Tests\Feature;

use App\Jobs\RunGuestRankCheck;
use App\Models\GuestRankCheck;
use App\Services\SerperSearchClient;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class GuestRankCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config([
            'services.recaptcha.site_key' => '', 'services.recaptcha.secret_key' => '',
            'services.serper.key' => 'k',
        ]);
    }

    public function test_tool_page_loads(): void
    {
        $this->get(route('tools.rank-tracker'))->assertOk()->assertSee('rank checker', false);
    }

    public function test_keyword_and_domain_are_required(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        $this->postJson(route('guest-rank.store'), ['keyword' => '', 'domain' => ''])->assertStatus(422);
    }

    public function test_a_non_domain_is_rejected(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        $this->postJson(route('guest-rank.store'), ['keyword' => 'seo tools', 'domain' => 'notadomain'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('domain');
    }

    public function test_unconfigured_serper_is_handled(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        config(['services.serper.key' => '']);
        $this->postJson(route('guest-rank.store'), ['keyword' => 'seo tools', 'domain' => 'example.com'])
            ->assertStatus(503);
    }

    public function test_first_check_is_free_shown_on_screen_and_queued(): void
    {
        // Signed-in user — anonymous submits short-circuit to the signup
        // teaser (202, no validation, no job) before any of this logic runs.
        $this->actingAs(\App\Models\User::factory()->create());
        Queue::fake();

        $r = $this->postJson(route('guest-rank.store'), [
            'keyword' => 'best seo tools',
            'domain' => 'https://www.Example.com/pricing',
            'country' => 'us',
        ]);

        $r->assertStatus(202)
            ->assertJsonPath('emailed', false)
            ->assertJsonStructure(['results_url', 'status_url', 'token']);

        // Domain is normalized to a bare host before storage.
        $this->assertDatabaseHas('guest_rank_checks', [
            'keyword' => 'best seo tools',
            'domain' => 'example.com',
            'country' => 'us',
            'email' => null,
        ]);
        Queue::assertPushed(RunGuestRankCheck::class, 1);
    }

    public function test_job_finds_the_target_position(): void
    {
        $fake = new class extends SerperSearchClient
        {
            public function __construct() {}

            public function search(
                string $query,
                int $num = 10,
                ?string $gl = null,
                ?string $hl = null,
                ?string $websiteId = null,
                ?string $ownerUserId = null,
                ?string $source = null,
            ): ?array {
                return [
                    'organic' => [
                        ['position' => 1, 'title' => 'Competitor', 'link' => 'https://competitor.com/', 'snippet' => 'x'],
                        ['position' => 2, 'title' => 'Mine', 'link' => 'https://www.example.com/pricing', 'snippet' => 'y'],
                    ],
                ];
            }
        };
        $this->app->instance(SerperSearchClient::class, $fake);

        $row = GuestRankCheck::start('best seo tools', 'example.com', 'us');
        Bus::dispatchSync(new RunGuestRankCheck($row->id));

        $fresh = $row->fresh();
        $this->assertSame(GuestRankCheck::STATUS_COMPLETED, $fresh->status);
        $this->assertSame(2, $fresh->result['position']);
        $this->assertSame('https://www.example.com/pricing', $fresh->result['found_url']);
        $this->assertCount(2, $fresh->result['results']);
        $this->assertTrue($fresh->result['results'][1]['is_target']);
    }

    public function test_job_records_not_found_when_domain_absent(): void
    {
        $fake = new class extends SerperSearchClient
        {
            public function __construct() {}

            public function search(
                string $query,
                int $num = 10,
                ?string $gl = null,
                ?string $hl = null,
                ?string $websiteId = null,
                ?string $ownerUserId = null,
                ?string $source = null,
            ): ?array {
                return ['organic' => [['position' => 1, 'title' => 'Other', 'link' => 'https://other.com/']]];
            }
        };
        $this->app->instance(SerperSearchClient::class, $fake);

        $row = GuestRankCheck::start('best seo tools', 'example.com', 'us');
        Bus::dispatchSync(new RunGuestRankCheck($row->id));

        $fresh = $row->fresh();
        $this->assertSame(GuestRankCheck::STATUS_COMPLETED, $fresh->status);
        $this->assertNull($fresh->result['position']);
    }

    public function test_results_page_renders_completed_report(): void
    {
        $row = GuestRankCheck::create([
            'token' => (string) Str::uuid(),
            'keyword' => 'best seo tools',
            'domain' => 'example.com',
            'country' => 'us',
            'status' => GuestRankCheck::STATUS_COMPLETED,
            'result' => [
                'keyword' => 'best seo tools',
                'domain' => 'example.com',
                'country' => 'us',
                'position' => 2,
                'found_url' => 'https://example.com/pricing',
                'depth' => 100,
                'scanned' => 2,
                'results' => [
                    ['position' => 1, 'title' => 'Competitor', 'link' => 'https://competitor.com/', 'domain' => 'competitor.com', 'snippet' => '', 'is_target' => false],
                    ['position' => 2, 'title' => 'Mine', 'link' => 'https://example.com/pricing', 'domain' => 'example.com', 'snippet' => '', 'is_target' => true],
                ],
                'checked_at' => now()->toIso8601String(),
                'source' => 'serper',
            ],
        ]);

        $this->get(route('guest-rank.show', $row))
            ->assertOk()
            ->assertSee('Current position', false)
            ->assertSee('#2', false)
            ->assertSee('Start free', false);
    }
}
