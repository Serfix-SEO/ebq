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

    /** Begin an onboarding session via the landing-page POST, return the resumable component. */
    private function beginAndResume(string $domain)
    {
        $this->post(route('content.onboarding.begin'), ['domain' => $domain])
            ->assertRedirect(route('content.onboarding'));

        $token = ContentOnboardingSession::query()->latest('id')->first()->token;
        session(['content_onboarding_token' => $token]);

        return Livewire::test(PublicOnboarding::class);
    }

    public function test_domain_step_creates_provisional_site_under_system_user(): void
    {
        Queue::fake();

        $this->post(route('content.onboarding.begin'), ['domain' => 'example.com'])
            ->assertRedirect(route('content.onboarding'));

        $session = ContentOnboardingSession::query()->first();
        $this->assertNotNull($session);
        $this->assertNull($session->converted_at);
        $this->assertTrue($session->website->user->is_system);
    }

    public function test_full_flow_creates_account_starts_trial_and_converts(): void
    {
        Queue::fake();

        $this->beginAndResume('newclient.com')
            ->assertSet('wizardStep', 1)
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

        // The throwaway per-session lead user is retired once the site moves.
        $this->assertSame(0, User::query()->where('is_system', true)->count());
    }

    public function test_repeat_domain_creates_isolated_sites_per_session(): void
    {
        Queue::fake();

        $this->post(route('content.onboarding.begin'), ['domain' => 'dup.com'])->assertRedirect();
        $this->post(route('content.onboarding.begin'), ['domain' => 'dup.com'])->assertRedirect();

        // Many visitors can onboard the SAME domain: each gets its own throwaway
        // owner + site (no shared-owner UNIQUE crash, no cross-visitor hijack).
        $this->assertSame(2, Website::query()->where('domain', 'dup.com')->count());
        $this->assertSame(2, ContentOnboardingSession::query()->count());
        $this->assertSame(2, User::query()->where('is_system', true)->count());
    }

    public function test_duplicate_email_is_rejected(): void
    {
        Queue::fake();
        User::factory()->create(['email' => 'taken@x.com', 'password' => \Illuminate\Support\Facades\Hash::make('Real-Pass-999')]);

        // Existing account + WRONG password → login fails (error on password).
        $this->beginAndResume('x-site.com')
            ->set('businessDescription', 'We sell widgets and gadgets to small businesses everywhere.')
            ->call('toOfferings')
            ->call('toHowItWorks')
            ->call('toAccount')
            ->set('name', 'Bob')
            ->set('email', 'taken@x.com')
            ->set('password', 'Wrong-Pass-123')
            ->call('createAccount')
            ->assertHasErrors('password');

        $this->assertNull(ContentOnboardingSession::query()->first()->converted_at);
    }

    public function test_existing_user_logs_in_and_gets_the_website_attached(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'email' => 'owner@acct.com',
            'password' => \Illuminate\Support\Facades\Hash::make('Real-Pass-999'),
        ]);

        $this->beginAndResume('brandnew.com')
            ->set('businessDescription', 'We sell handmade candles and home fragrance for cozy apartments.')
            ->call('toOfferings')
            ->call('toHowItWorks')
            ->call('toAccount')
            ->set('email', 'owner@acct.com')
            ->set('password', 'Real-Pass-999')
            ->call('createAccount');

        $user->refresh();
        $this->assertTrue(\Illuminate\Support\Facades\Auth::check());
        $website = $user->websites()->where('domain', 'brandnew.com')->first();
        $this->assertNotNull($website, 'onboarded website attached to the existing account');
        // Never trialed before → trial starts + site is covered.
        $this->assertTrue(app(ContentEntitlements::class)->hasContentAccessFor($user, $website));
        $this->assertNotNull(ContentOnboardingSession::query()->first()->converted_at);
    }

    public function test_second_site_on_trial_is_attached_but_uncovered(): void
    {
        Queue::fake();
        $user = User::factory()->create([
            'email' => 'ontrial@acct.com',
            'password' => \Illuminate\Support\Facades\Hash::make('Real-Pass-999'),
        ]);
        // Already on trial with one covered site (trial allows exactly one).
        $firstSite = Website::factory()->for($user)->create();
        $user->forceFill([
            'content_trial_started_at' => now(), 'content_trial_ends_at' => now()->addDays(5),
        ])->save();
        ContentPlan::factory()->create(['website_id' => $firstSite->id, 'billing_covered_at' => now()]);

        $this->beginAndResume('secondsite.com')
            ->set('businessDescription', 'We offer dog walking and pet sitting services across the city.')
            ->call('toOfferings')
            ->call('toHowItWorks')
            ->call('toAccount')
            ->set('email', 'ontrial@acct.com')
            ->set('password', 'Real-Pass-999')
            ->call('createAccount')
            ->assertRedirect(route('content.get-started'));

        $user->refresh();
        $newSite = $user->websites()->where('domain', 'secondsite.com')->first();
        $this->assertNotNull($newSite, 'second site attached to the account');
        // Trial = 1 site, so the second is attached but NOT covered (needs payment).
        $this->assertFalse(app(ContentEntitlements::class)->hasContentAccessFor($user, $newSite));
    }

    public function test_gc_removes_stale_unconverted_sessions_and_sites(): void
    {
        Queue::fake();
        $this->post(route('content.onboarding.begin'), ['domain' => 'stale.com'])
            ->assertRedirect(route('content.onboarding'));

        $session = ContentOnboardingSession::query()->first();
        $websiteId = $session->website_id;
        $session->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->artisan('ebq:content-onboarding-gc')->assertSuccessful();

        $this->assertDatabaseMissing('content_onboarding_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('websites', ['id' => $websiteId]);
    }
}
