<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\PublicOnboarding;
use App\Models\ContentOnboardingSession;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentOnboardingConverter;
use App\Support\Audit\SafeHttpGuard;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Content-only mode (`features.seo_platform_ui` = false): every signup path
 * must funnel a no-website user into the content onboarding wizard.
 *
 * Before this, the get-started "Add your website" CTA was a CLOSED LOOP
 * (get-started → /websites → EnsureOnboarded → /onboarding → kill-switch →
 * get-started): a no-website user literally could not add a website. And an
 * authed visitor posting the landing domain form was converted INSTANTLY with
 * an empty profile — an active plan and topic generation with no business
 * description.
 */
class ContentAuthedOnboardingFunnelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config(['features.seo_platform_ui' => false]);
        // The convert path bootstraps a crawl subscription which reaches for
        // the network on sync queues — 80s per test without this.
        Http::fake();
        // SafeHttpGuard does live DNS/SSRF checks — stub it green for tests.
        $this->instance(SafeHttpGuard::class, new class extends SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
    }

    private function beginAs(User $user): ContentOnboardingSession
    {
        $this->actingAs($user)
            ->post(route('content.onboarding.begin'), ['domain' => 'funnel-example.com'])
            ->assertRedirect(route('content.onboarding'));

        return ContentOnboardingSession::query()->latest()->firstOrFail();
    }

    // ── Domain capture ──────────────────────────────────────────────────

    public function test_an_authed_domain_post_leads_to_the_wizard_not_an_instant_convert(): void
    {
        $user = User::factory()->create();
        $session = $this->beginAs($user);

        $this->assertNull($session->converted_at, 'no instant convert — the wizard collects the profile first');
        // The provisional site still belongs to the lead user, not the customer.
        $this->assertNotSame($user->id, Website::query()->whereKey($session->website_id)->value('user_id'));
        $this->assertSame($session->token, session('content_onboarding_token'));
    }

    public function test_the_get_started_page_carries_the_domain_form_for_a_no_website_user(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('content.get-started'))->assertOk()->getContent();

        $this->assertStringContainsString(route('content.onboarding.begin'), $html);
        $this->assertStringContainsString('name="domain"', $html);
        // The old closed-loop CTA is gone.
        $this->assertStringNotContainsString(__('Add your website'), $html);
    }

    // ── The wizard itself ───────────────────────────────────────────────

    public function test_an_authed_user_with_a_pending_session_walks_the_wizard(): void
    {
        $user = User::factory()->create();
        $session = $this->beginAs($user);
        $session->update(['step' => 2]);

        Livewire::actingAs($user)
            ->test(PublicOnboarding::class)
            ->assertSet('token', $session->token)
            ->assertSet('wizardStep', 2)
            // No account step for a signed-in user.
            ->assertDontSee(__('Create account'));
    }

    public function test_finish_converts_with_the_typed_profile(): void
    {
        $user = User::factory()->create();
        $session = $this->beginAs($user);

        Livewire::actingAs($user)
            ->test(PublicOnboarding::class)
            ->set('businessDescription', 'We build hand-made wooden furniture for small apartments.')
            ->set('sellItems', ['Custom shelving'])
            ->set('dontSellItems', ['Mass-produced flat packs'])
            ->call('finish')
            ->assertRedirect(route('content.index'));

        $session->refresh();
        $this->assertNotNull($session->converted_at);
        $website = Website::query()->whereKey($session->website_id)->first();
        $this->assertSame($user->id, $website->user_id, 'the provisional site becomes the customer\'s');
        $plan = ContentPlan::query()->where('website_id', $website->id)->firstOrFail();
        $this->assertSame(
            'We build hand-made wooden furniture for small apartments.',
            $plan->business_description,
            'the typed profile survives — the whole point of walking the wizard',
        );
        $this->assertNotNull($user->fresh()->content_trial_started_at, 'trial starts on finish');
    }

    public function test_finishing_twice_is_a_noop_redirect(): void
    {
        $user = User::factory()->create();
        $session = $this->beginAs($user);
        app(ContentOnboardingConverter::class)->convert($session, $user, []);

        Livewire::actingAs($user)
            ->withoutLazyLoading()
            ->test(PublicOnboarding::class)
            // mount() already sees a converted session → straight to get-started.
            ->assertRedirect();
    }

    // ── Login resumes an in-flight run ──────────────────────────────────

    public function test_login_resumes_an_in_flight_wizard(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-1']);
        // A GUEST starts the wizard…
        $this->post(route('content.onboarding.begin'), ['domain' => 'resume-me.com']);
        $this->assertNotNull(session('content_onboarding_token'));

        // …then logs in via the wizard header link.
        $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-password-1'])
            ->assertRedirect(route('content.onboarding'));
    }

    public function test_login_without_a_token_uses_the_normal_fallback(): void
    {
        $user = User::factory()->create(['password' => 'secret-password-1']);

        $response = $this->post(route('login'), ['email' => $user->email, 'password' => 'secret-password-1']);

        $this->assertNotSame(route('content.onboarding'), $response->headers->get('Location'));
    }

    // ── Chain-A closures ────────────────────────────────────────────────

    /** The closed loop, walked end to end: every hop must terminate. */
    public function test_a_no_website_user_never_loops(): void
    {
        $user = User::factory()->create();

        foreach (['websites.index', 'onboarding', 'dashboard'] as $start) {
            $response = $this->actingAs($user)->get(route($start));
            $seen = [];
            $hops = 0;
            while ($response->isRedirect()) {
                $target = $response->headers->get('Location');
                $this->assertNotContains($target, $seen, "loop from {$start}: ".implode(' -> ', array_merge($seen, [$target])));
                $this->assertLessThan(5, ++$hops, "chain too long from {$start}");
                $seen[] = $target;
                $response = $this->actingAs($user)->get($target);
            }
            $response->assertOk();
        }
    }

    public function test_first_accessible_route_falls_back_to_get_started(): void
    {
        $this->assertSame('content.get-started', User::factory()->create()->firstAccessibleRoute(null));

        config(['features.seo_platform_ui' => true]);
        $this->assertSame('websites.index', User::factory()->create()->firstAccessibleRoute(null));
    }

    // ── Flag-ON reversibility ───────────────────────────────────────────

    public function test_flag_on_keeps_the_instant_convert(): void
    {
        config(['features.seo_platform_ui' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('content.onboarding.begin'), ['domain' => 'flag-on.com']);

        $session = ContentOnboardingSession::query()->latest()->firstOrFail();
        $this->assertNotNull($session->converted_at, 'flag on = today\'s instant convert, unchanged');
        $this->assertSame($user->id, Website::query()->whereKey($session->website_id)->value('user_id'));
    }

    // ── GC still covers authed-abandoned runs ───────────────────────────

    public function test_gc_sweeps_an_abandoned_authed_session(): void
    {
        $session = $this->beginAs(User::factory()->create());
        ContentOnboardingSession::query()->whereKey($session->id)
            ->update(['created_at' => now()->subDays(10)]);

        $this->artisan('ebq:content-onboarding-gc')->assertSuccessful();

        $this->assertDatabaseMissing('content_onboarding_sessions', ['id' => $session->id]);
    }
}
