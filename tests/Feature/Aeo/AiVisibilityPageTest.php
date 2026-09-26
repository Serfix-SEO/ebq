<?php

namespace Tests\Feature\Aeo;

use App\Jobs\Content\AuditAeoReadinessJob;
use App\Livewire\Content\AiVisibility;
use App\Models\ContentAeoAudit;
use App\Models\ContentAeoBotHit;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Aeo\AeoSignalReader;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The AI Visibility page. The rule under test throughout: "we cannot see this"
 * and "this did not happen" are different claims and must never render the same.
 */
class AiVisibilityPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Queue::fake();
    }

    /** @return array{User, Website} */
    private function fixture(array $websiteAttrs = []): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create($websiteAttrs);
        ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        return [$user, $website];
    }

    public function test_the_page_says_tracking_is_not_installed_rather_than_showing_zero(): void
    {
        $this->fixture();

        Livewire::test(AiVisibility::class)
            ->assertOk()
            ->assertSee(__('AI crawlers'))
            ->assertSee('not tracked')
            ->assertDontSee(__(':n visits in the last 30 days', ['n' => 0]));
    }

    public function test_a_blocked_crawler_is_named_on_the_page(): void
    {
        [, $website] = $this->fixture();
        ContentAeoAudit::create([
            'website_id' => $website->id,
            'checked_at' => now(),
            'robots_ai' => ['gptbot' => ContentAeoAudit::BLOCKED, 'claudebot' => ContentAeoAudit::ALLOWED],
            'robots_fetched' => true,
            'llms_txt_present' => false,
            'readiness_score' => 55,
            'breakdown' => [['code' => 'robots_search', 'label' => 'AI search crawlers can read your site', 'passed' => false, 'weight' => 34, 'detail' => 'GPTBot']],
        ]);

        Livewire::test(AiVisibility::class)
            ->assertSee('GPTBot')
            ->assertSee(__('Blocked'))
            ->assertSee('55');
    }

    public function test_crawler_hits_are_summarised_once_a_site_reports(): void
    {
        [, $website] = $this->fixture();
        ContentAeoBotHit::create([
            'website_id' => $website->id, 'bot' => 'gptbot', 'hit_on' => now()->subDay()->toDateString(),
            'hits' => 42, 'pages' => 12, 'reporter' => ContentAeoBotHit::REPORTER_PLUGIN,
        ]);

        Livewire::test(AiVisibility::class)->assertSee('42')->assertSee('12');
    }

    public function test_a_site_that_stopped_reporting_is_flagged_not_read_as_quiet(): void
    {
        [, $website] = $this->fixture();
        ContentAeoBotHit::create([
            'website_id' => $website->id, 'bot' => 'gptbot',
            'hit_on' => now()->subDays(20)->toDateString(),
            'hits' => 10, 'pages' => 4, 'reporter' => ContentAeoBotHit::REPORTER_PLUGIN,
        ]);

        $hits = app(AeoSignalReader::class)->crawlerHits($website);
        $this->assertTrue($hits['instrumented']);
        $this->assertTrue($hits['stale']);

        Livewire::test(AiVisibility::class)->assertSee(__('Reporting paused — last report :date', ['date' => $hits['last_report']]));
    }

    public function test_ai_referrals_come_from_analytics_we_already_sync(): void
    {
        [, $website] = $this->fixture(['ga_property_id' => '12345']);
        foreach ([['chatgpt.com', 30], ['perplexity.ai', 4], ['google', 900]] as [$source, $sessions]) {
            DB::table('analytics_data')->insert([
                'id' => (string) Str::ulid(),
                'website_id' => $website->id, 'date' => now()->subDays(2)->toDateString(),
                'source' => $source, 'users' => $sessions, 'sessions' => $sessions, 'bounce_rate' => 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $referrals = app(AeoSignalReader::class)->referrals($website);

        $this->assertTrue($referrals['connected']);
        $this->assertSame(34, $referrals['total'], 'Plain Google traffic is not an AI answer.');
        $this->assertSame(['ChatGPT' => 30, 'Perplexity' => 4], $referrals['engines']);
    }

    public function test_without_analytics_the_page_offers_to_connect_instead_of_claiming_zero(): void
    {
        [, $website] = $this->fixture(['ga_property_id' => '']);

        $this->assertFalse(app(AeoSignalReader::class)->referrals($website)['connected']);
        Livewire::test(AiVisibility::class)->assertSee(__('Connect Google Analytics to see how many people reach your site from ChatGPT, Perplexity, Gemini and Copilot.'));
    }

    public function test_the_client_can_ask_for_a_fresh_check(): void
    {
        $this->fixture();

        Livewire::test(AiVisibility::class)->call('recheck')->assertSet('checkQueued', true);

        Queue::assertPushed(AuditAeoReadinessJob::class);
    }

    public function test_another_tenants_website_is_not_readable(): void
    {
        $this->fixture();
        $theirs = Website::factory()->for(User::factory())->create();

        Livewire::test(AiVisibility::class)
            ->call('switchWebsite', $theirs->id)
            ->call('recheck')
            ->assertSet('checkQueued', false);

        Queue::assertNotPushed(AuditAeoReadinessJob::class);
    }

    public function test_the_route_is_reachable_for_a_content_client(): void
    {
        $this->fixture();

        $this->get(route('content.ai-visibility'))->assertOk()->assertSee(__('AI Visibility'));
    }
}
