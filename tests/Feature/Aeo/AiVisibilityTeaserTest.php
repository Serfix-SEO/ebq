<?php

namespace Tests\Feature\Aeo;

use App\Console\Commands\AuditAeoReadiness;
use App\Jobs\Content\AuditAeoReadinessJob;
use App\Livewire\Content\AiVisibility;
use App\Models\ContentAeoAudit;
use App\Models\ContentAeoBotHit;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * AI Visibility is a paid feature with a worked example in front of it.
 *
 * The two things that must hold: a free signup is told loudly that what they
 * are looking at is not their site, and their real figures are neither shown
 * nor fetched while they are on the free side of the gate.
 */
class AiVisibilityTeaserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        Queue::fake();
    }

    /** @return array{User, Website} a free signup on the article trial, never paid */
    private function freeSignup(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);

        return [$user, $this->siteFor($user)];
    }

    /** @return array{User, Website} comped = someone decided they get the product */
    private function paidUser(): array
    {
        $user = User::factory()->create(['content_comp_sites' => 1]);

        return [$user, $this->siteFor($user)];
    }

    private function siteFor(User $user): Website
    {
        $website = Website::factory()->for($user)->create(['ga_property_id' => '999']);
        ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'billing_covered_at' => now(),
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        return $website;
    }

    public function test_a_free_signup_is_told_in_large_type_that_the_report_is_an_example(): void
    {
        $this->freeSignup();

        Livewire::test(AiVisibility::class)
            ->assertSee(__('This is sample data — not your website'))
            ->assertSee(__('Start for $:p', ['p' => 1]))
            ->assertSee(__('That was an example. Want to see yours?'));
    }

    public function test_the_sample_never_shows_the_free_users_own_numbers(): void
    {
        [, $website] = $this->freeSignup();

        // Real data exists for this site — it must not surface behind the label.
        ContentAeoBotHit::create([
            'website_id' => $website->id, 'bot' => 'gptbot',
            'hit_on' => now()->subDay()->toDateString(),
            'hits' => 777, 'pages' => 333, 'reporter' => ContentAeoBotHit::REPORTER_PLUGIN,
        ]);
        ContentAeoAudit::create([
            'website_id' => $website->id, 'checked_at' => now(),
            'robots_ai' => ['gptbot' => ContentAeoAudit::BLOCKED], 'robots_fetched' => true,
            'llms_txt_present' => true, 'readiness_score' => 41, 'breakdown' => [],
        ]);
        DB::table('analytics_data')->insert([
            'id' => (string) Str::ulid(), 'website_id' => $website->id,
            'date' => now()->subDay()->toDateString(), 'source' => 'chatgpt.com',
            'users' => 555, 'sessions' => 555, 'bounce_rate' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Livewire::test(AiVisibility::class)
            ->assertDontSee('777')
            ->assertDontSee('555')
            ->assertDontSee('>41<', false)
            ->assertSee('74');   // the sample's readiness score
    }

    public function test_a_free_signup_cannot_trigger_a_real_check(): void
    {
        $this->freeSignup();

        Livewire::test(AiVisibility::class)
            ->call('recheck')
            ->assertSet('checkQueued', false);

        Queue::assertNotPushed(AuditAeoReadinessJob::class);
    }

    public function test_a_paying_client_sees_their_own_report_with_no_sample_banner(): void
    {
        [, $website] = $this->paidUser();
        ContentAeoBotHit::create([
            'website_id' => $website->id, 'bot' => 'gptbot',
            'hit_on' => now()->subDay()->toDateString(),
            'hits' => 777, 'pages' => 333, 'reporter' => ContentAeoBotHit::REPORTER_PLUGIN,
        ]);

        Livewire::test(AiVisibility::class)
            ->assertDontSee(__('This is sample data — not your website'))
            ->assertSee('777');
    }

    public function test_a_subscriber_counts_as_paid_and_the_free_article_trial_does_not(): void
    {
        $entitlements = app(\App\Services\Content\ContentEntitlements::class);

        $trialOnly = User::factory()->create([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ]);
        $comped = User::factory()->create(['content_comp_sites' => 2]);

        $this->assertTrue($entitlements->hasContentAccess($trialOnly), 'the free trial still grants article access');
        $this->assertFalse($entitlements->hasPaidContentAccess($trialOnly), 'but it is not a paid plan');
        $this->assertTrue($entitlements->hasPaidContentAccess($comped));
    }

    public function test_the_weekly_sweep_skips_sites_that_are_not_on_a_paid_plan(): void
    {
        [, $freeSite] = $this->freeSignup();
        [, $paidSite] = $this->paidUser();

        $this->artisan(AuditAeoReadiness::class)->assertSuccessful();

        Queue::assertPushed(AuditAeoReadinessJob::class, fn ($job) => $job->websiteId === (string) $paidSite->id);
        Queue::assertNotPushed(AuditAeoReadinessJob::class, fn ($job) => $job->websiteId === (string) $freeSite->id);
    }

    public function test_the_sample_is_identical_on_every_load(): void
    {
        $this->freeSignup();

        // A sample that moves between refreshes reads as live data.
        $first = Livewire::test(AiVisibility::class)->html();
        $second = Livewire::test(AiVisibility::class)->html();

        $strip = fn (string $html): string => (string) preg_replace('/wire:(snapshot|id|effects)="[^"]*"/', '', $html);
        $this->assertSame($strip($first), $strip($second));
    }
}
