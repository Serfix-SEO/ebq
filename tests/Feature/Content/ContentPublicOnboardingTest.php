<?php

namespace Tests\Feature\Content;

use App\Jobs\PlanContentTopicsJob;
use App\Livewire\Content\PublicOnboarding;
use App\Models\ContentOnboardingSession;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Services\Content\ContentOnboardingConverter;
use App\Support\Audit\SafeHttpGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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
        // Covered → plan goes LIVE so the dashboard shows the calendar, not the wizard.
        $this->assertSame(ContentPlan::STATUS_ACTIVE, $plan->status);
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
        User::factory()->create(['email' => 'taken@x.com', 'password' => Hash::make('Real-Pass-999')]);

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
            'password' => Hash::make('Real-Pass-999'),
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
        $this->assertTrue(Auth::check());
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
            'password' => Hash::make('Real-Pass-999'),
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

    /**
     * Regression (prod 2026-07-20): a registrant who ALREADY owned the onboarded
     * domain lost the whole wizard setup. convert() folds into the existing site
     * and deletes the provisional one — and content_plans.website_id is ON DELETE
     * CASCADE, so the plan the wizard had been writing to went with it. When
     * convert() is then handed an EMPTY $profile (the documented Google-SSO
     * round-trip case), nothing was copied and the user landed on a bare stub:
     * no business description, no offerings, default cadence.
     */
    public function test_folding_into_an_owned_domain_keeps_the_wizard_profile_when_convert_gets_none(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $owned = Website::factory()->for($user)->create(['domain' => 'owned.com']);

        $converter = app(ContentOnboardingConverter::class);
        [$session, $provisional] = $converter->begin('owned.com', '127.0.0.1');
        $this->assertNotSame($owned->id, $provisional->id);

        // What the wizard persisted on the provisional plan before signup.
        ContentPlan::factory()->create([
            'website_id' => $provisional->id,
            'business_description' => 'We restore vintage mechanical watches and sell serviced classics.',
            'offerings' => ['sell' => ['Watch servicing'], 'dont_sell' => ['Smartwatch repair']],
            'articles_per_week' => 3,
            'article_length' => 1500,
            'language' => 'English',
            'country' => 'us',
        ]);

        // Empty profile — the Livewire state did not survive the round-trip.
        $converter->convert($session->fresh(), $user, []);

        $plan = ContentPlan::query()->where('website_id', $owned->id)->first();
        $this->assertNotNull($plan, 'the owned site ends up with a plan');
        $this->assertSame(
            'We restore vintage mechanical watches and sell serviced classics.',
            $plan->business_description,
            'business description carried off the deleted provisional plan'
        );
        $this->assertSame(['Watch servicing'], $plan->offerings['sell']);
        $this->assertSame(['Smartwatch repair'], $plan->offerings['dont_sell']);
        // Cadence chosen in the wizard must beat the stub's 7/2000 defaults.
        $this->assertSame(3, $plan->articles_per_week);
        $this->assertSame(1500, $plan->article_length);
        $this->assertSame('us', $plan->country);
    }

    /** An explicitly supplied profile still wins over the carried-over one. */
    public function test_supplied_profile_beats_the_carried_over_provisional_plan(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $owned = Website::factory()->for($user)->create(['domain' => 'owned2.com']);

        $converter = app(ContentOnboardingConverter::class);
        [$session, $provisional] = $converter->begin('owned2.com', '127.0.0.1');
        ContentPlan::factory()->create([
            'website_id' => $provisional->id,
            'business_description' => 'Stale description from an earlier step.',
            'offerings' => ['sell' => ['Old'], 'dont_sell' => []],
        ]);

        $converter->convert($session->fresh(), $user, [
            'business_description' => 'The description the user actually submitted at signup.',
            'sell' => ['New'],
            'dont_sell' => ['Nope'],
        ]);

        $plan = ContentPlan::query()->where('website_id', $owned->id)->first();
        $this->assertSame('The description the user actually submitted at signup.', $plan->business_description);
        $this->assertSame(['New'], $plan->offerings['sell']);
    }

    /** A site the user configured earlier must not be clobbered by a later funnel run. */
    public function test_carry_over_does_not_overwrite_an_already_configured_plan(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $owned = Website::factory()->for($user)->create(['domain' => 'owned3.com']);
        ContentPlan::factory()->create([
            'website_id' => $owned->id,
            'business_description' => 'The description this site was already set up with.',
            'articles_per_week' => 2,
        ]);

        $converter = app(ContentOnboardingConverter::class);
        [$session, $provisional] = $converter->begin('owned3.com', '127.0.0.1');
        ContentPlan::factory()->create([
            'website_id' => $provisional->id,
            'business_description' => 'Something the funnel guessed on a second pass.',
            'articles_per_week' => 7,
        ]);

        $converter->convert($session->fresh(), $user, []);

        $plan = ContentPlan::query()->where('website_id', $owned->id)->first();
        $this->assertSame('The description this site was already set up with.', $plan->business_description);
        $this->assertSame(2, $plan->articles_per_week);
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
