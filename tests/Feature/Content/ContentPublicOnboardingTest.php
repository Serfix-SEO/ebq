<?php

namespace Tests\Feature\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Livewire\Content\PublicOnboarding;
use App\Models\ContentOnboardingSession;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Support\Audit\SafeHttpGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ContentPublicOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // SafeHttpGuard does live DNS/SSRF checks — stub it green for tests.
        $this->instance(SafeHttpGuard::class, new class extends SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
    }

    public function test_domain_step_creates_provisional_site_under_system_user(): void
    {
        Queue::fake();

        Livewire::test(PublicOnboarding::class)
            ->set('domain', 'example.com')
            ->call('startWithDomain')
            ->assertSet('wizardStep', 1)
            ->assertSet('websiteId', fn ($v) => $v !== null);

        $session = ContentOnboardingSession::query()->first();
        $this->assertNotNull($session);
        $this->assertNull($session->converted_at);
        $this->assertTrue($session->website->user->is_system);
    }

    public function test_full_flow_creates_account_starts_trial_and_converts(): void
    {
        Queue::fake();

        $component = Livewire::test(PublicOnboarding::class)
            ->set('domain', 'newclient.com')
            ->call('startWithDomain')
            ->set('businessDescription', 'We sell handmade wooden tables and chairs for small apartments.')
            ->set('sellItems', ['Tables', 'Chairs'])
            ->call('toOfferings')
            ->call('toHowItWorks')
            ->call('toAccount')
            ->assertSet('wizardStep', 8)
            ->set('name', 'Jane Doe')
            ->set('email', 'jane@newclient.com')
            ->set('password', 'Str0ng-Pass-123')
            ->set('password_confirmation', 'Str0ng-Pass-123')
            ->call('createAccount');

        $user = User::query()->where('email', 'jane@newclient.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->is_system);

        // Website re-parented to the new user; trial + coverage active.
        $website = $user->websites()->first();
        $this->assertNotNull($website);
        $this->assertTrue(app(ContentEntitlements::class)->hasContentAccessFor($user, $website));
        $this->assertNotNull($user->content_trial_ends_at);

        // Plan persisted from the wizard + research dispatched.
        $plan = ContentPlan::query()->where('website_id', $website->id)->first();
        $this->assertSame(['Tables', 'Chairs'], $plan->offerings['sell']);
        Queue::assertPushed(PlanContentTopicsJob::class);

        // Session marked converted.
        $this->assertNotNull(ContentOnboardingSession::query()->first()->converted_at);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        Queue::fake();
        User::factory()->create(['email' => 'taken@x.com']);

        Livewire::test(PublicOnboarding::class)
            ->set('domain', 'x-site.com')
            ->call('startWithDomain')
            ->set('businessDescription', 'We sell widgets and gadgets to small businesses everywhere.')
            ->call('toOfferings')
            ->call('toHowItWorks')
            ->call('toAccount')
            ->set('name', 'Bob')
            ->set('email', 'taken@x.com')
            ->set('password', 'Str0ng-Pass-123')
            ->set('password_confirmation', 'Str0ng-Pass-123')
            ->call('createAccount')
            ->assertHasErrors('email');

        $this->assertNull(ContentOnboardingSession::query()->first()->converted_at);
    }

    public function test_gc_removes_stale_unconverted_sessions_and_sites(): void
    {
        Queue::fake();
        Livewire::test(PublicOnboarding::class)->set('domain', 'stale.com')->call('startWithDomain');

        $session = ContentOnboardingSession::query()->first();
        $websiteId = $session->website_id;
        $session->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->artisan('ebq:content-onboarding-gc')->assertSuccessful();

        $this->assertDatabaseMissing('content_onboarding_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('websites', ['id' => $websiteId]);
    }
}
