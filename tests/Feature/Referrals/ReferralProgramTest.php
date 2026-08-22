<?php

namespace Tests\Feature\Referrals;

use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\CaptureReferralCode;
use App\Models\Referral;
use App\Models\Setting;
use App\Models\User;
use App\Services\Content\ContentEntitlements;
use App\Services\ReferralProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Referral program: ?ref= cookie → pending row at signup →
 * invoice.payment_succeeded qualifies the referred account's first FULL
 * content base invoice → 50%-of-base Stripe balance credit to the referrer.
 * All Stripe touches are injected spies — zero network.
 */
class ReferralProgramTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{0:User,1:int,2:string}> */
    private array $credits = [];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('content.pricing.monthly_price_id', 'price_base_m');
        Setting::set('content.pricing.annual_price_id', 'price_base_y');
        Setting::set('content.pricing.addon_monthly_price_id', 'price_addon_m');
        $this->bindProgram();
    }

    /** Bind a network-free ReferralProgram (spy creditor + fixed price amounts). */
    private function bindProgram(?\Closure $creditor = null): void
    {
        $this->credits = [];
        $creditor ??= function (User $u, int $cents, string $desc): void {
            $this->credits[] = [$u, $cents, $desc];
        };
        $resolver = fn (string $priceId): ?int => match ($priceId) {
            'price_base_m' => 3900,
            'price_base_y' => 34800, // 29 × 12
            default => null,
        };
        $this->app->instance(ReferralProgram::class, new ReferralProgram($creditor, $resolver));
    }

    private function referrer(): User
    {
        $user = User::factory()->create(['stripe_id' => 'cus_referrer']);
        $user->forceFill(['referral_code' => 'abcd1234'])->save();

        return $user;
    }

    private function contentSub(User $user, string $basePrice = 'price_base_m'): void
    {
        $sub = $user->subscriptions()->create([
            'id' => (string) Str::ulid(),
            'type' => ContentEntitlements::SUBSCRIPTION,
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => $basePrice,
            'quantity' => 1,
        ]);
        $sub->items()->create([
            'id' => (string) Str::ulid(),
            'stripe_id' => 'si_'.uniqid(), 'stripe_product' => 'prod_base',
            'stripe_price' => $basePrice, 'quantity' => 1,
        ]);
        $user->load('subscriptions.items');
    }

    private function pendingReferral(User $referrer, ?User $referred = null): array
    {
        $referred ??= User::factory()->create(['stripe_id' => 'cus_referred']);
        $referral = Referral::query()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'code_used' => $referrer->referral_code,
            'status' => Referral::STATUS_PENDING,
        ]);

        return [$referral, $referred];
    }

    /** @param array<int, array{price:string, amount?:int}> $lines */
    private function invoicePayload(string $customer, array $lines, int $amountPaid, ?string $invoiceId = null): array
    {
        return ['data' => ['object' => [
            'id' => $invoiceId ?? 'in_'.uniqid(),
            'customer' => $customer,
            'amount_paid' => $amountPaid,
            'lines' => ['data' => array_map(
                static fn ($l) => ['price' => ['id' => $l['price']], 'amount' => $l['amount'] ?? 0],
                $lines
            )],
        ]]];
    }

    private function fireWebhook(array $payload): void
    {
        (new StripeWebhookController())->handleInvoicePaymentSucceeded($payload);
    }

    // ── Attribution ─────────────────────────────────────────────────────

    public function test_register_with_referral_cookie_creates_pending_row(): void
    {
        $referrer = $this->referrer();

        $this->withCookie(CaptureReferralCode::COOKIE, 'abcd1234')
            ->post(route('register'), [
                'name' => 'Ref Friend',
                'email' => 'friend@example.com',
                'password' => 'secret-password-1',
                'password_confirmation' => 'secret-password-1',
            ]);

        $new = User::query()->where('email', 'friend@example.com')->first();
        $this->assertNotNull($new);
        $this->assertDatabaseHas('referrals', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $new->id,
            'code_used' => 'abcd1234',
            'status' => Referral::STATUS_PENDING,
        ]);
    }

    public function test_capture_middleware_sets_sixty_day_cookie(): void
    {
        // Garbage codes are ignored (checked FIRST — the CookieJar is a
        // singleton, so a queued cookie from an earlier request in the same
        // test would leak onto this response).
        $response = $this->get('/?ref=<script>x');
        $this->assertNull(collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === CaptureReferralCode::COOKIE));

        $response = $this->get('/?ref=abcd1234');
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === CaptureReferralCode::COOKIE);
        $this->assertNotNull($cookie, 'ebq_ref cookie must be queued');
    }

    public function test_self_referral_and_unknown_and_system_users_never_attribute(): void
    {
        $referrer = $this->referrer();

        // Unknown code → no row.
        $this->withCookie(CaptureReferralCode::COOKIE, 'nosuchcode')
            ->post(route('register'), [
                'name' => 'A', 'email' => 'a@example.com',
                'password' => 'secret-password-1', 'password_confirmation' => 'secret-password-1',
            ]);
        $this->assertSame(0, Referral::query()->count());

        // System users (lead throwaways) → no row even with a valid cookie.
        request()->cookies->set(CaptureReferralCode::COOKIE, 'abcd1234');
        $sys = User::factory()->make(['email' => 'lead@leads.serfix.internal']);
        $sys->is_system = true;
        $sys->save();
        $this->assertSame(0, Referral::query()->where('referred_user_id', $sys->id)->count());

        // Self-referral: the referrer's own cookie on their own new account id.
        ReferralProgram::attributeFromRequest($referrer);
        $this->assertSame(0, Referral::query()->where('referred_user_id', $referrer->id)->count());
    }

    // ── Qualification matrix ────────────────────────────────────────────

    public function test_one_dollar_intro_invoice_does_not_qualify(): void
    {
        [$referral] = $this->pendingReferral($this->referrer());

        $this->fireWebhook($this->invoicePayload('cus_referred', [['price' => 'price_base_m']], 100));

        $this->assertSame(Referral::STATUS_PENDING, $referral->fresh()->status);
        $this->assertCount(0, $this->credits);
    }

    public function test_full_monthly_invoice_credits_half_of_monthly_base(): void
    {
        $referrer = $this->referrer();
        $this->contentSub($referrer, 'price_base_m');
        [$referral] = $this->pendingReferral($referrer);

        $this->fireWebhook($this->invoicePayload('cus_referred', [['price' => 'price_base_m']], 3900));

        $fresh = $referral->fresh();
        $this->assertSame(Referral::STATUS_CREDITED, $fresh->status);
        $this->assertSame(1950, $fresh->credit_cents);
        $this->assertNotNull($fresh->credited_at);
        $this->assertCount(1, $this->credits);
        $this->assertTrue($this->credits[0][0]->is($referrer));
        $this->assertSame(1950, $this->credits[0][1]);
        $this->assertDatabaseHas('client_activities', ['type' => 'referral_credited', 'user_id' => $referrer->id]);
    }

    public function test_annual_first_invoice_qualifies_immediately(): void
    {
        $referrer = $this->referrer();
        [$referral] = $this->pendingReferral($referrer);

        $this->fireWebhook($this->invoicePayload('cus_referred', [['price' => 'price_base_y']], 34800));

        $this->assertSame(Referral::STATUS_CREDITED, $referral->fresh()->status);
        // Referrer has no sub → monthly-rate credit.
        $this->assertSame(1950, $referral->fresh()->credit_cents);
    }

    public function test_addon_only_and_foreign_invoices_never_qualify(): void
    {
        [$referral] = $this->pendingReferral($this->referrer());

        // Addon-only invoice (mid-cycle website add) at any amount.
        $this->fireWebhook($this->invoicePayload('cus_referred', [['price' => 'price_addon_m']], 5000));
        // SEO 'default' product invoice.
        $this->fireWebhook($this->invoicePayload('cus_referred', [['price' => 'price_seo_pro']], 9900));
        // Unknown customer.
        $this->fireWebhook($this->invoicePayload('cus_stranger', [['price' => 'price_base_m']], 3900));

        $this->assertSame(Referral::STATUS_PENDING, $referral->fresh()->status);
        $this->assertCount(0, $this->credits);
    }

    public function test_reward_is_granted_exactly_once(): void
    {
        $referrer = $this->referrer();
        [$referral] = $this->pendingReferral($referrer);

        $payload = $this->invoicePayload('cus_referred', [['price' => 'price_base_m']], 3900, 'in_first_full');
        $this->fireWebhook($payload);
        $this->fireWebhook($payload); // webhook retry
        $this->fireWebhook($this->invoicePayload('cus_referred', [['price' => 'price_base_m']], 3900)); // next month

        $this->assertSame(Referral::STATUS_CREDITED, $referral->fresh()->status);
        $this->assertSame('in_first_full', $referral->fresh()->stripe_invoice_id);
        $this->assertCount(1, $this->credits);
    }

    public function test_basil_line_shape_is_understood(): void
    {
        [$referral] = $this->pendingReferral($this->referrer());

        $payload = ['data' => ['object' => [
            'id' => 'in_basil', 'customer' => 'cus_referred', 'amount_paid' => 3900,
            'lines' => ['data' => [['pricing' => ['price_details' => ['price' => 'price_base_m']]]]],
        ]]];
        $this->fireWebhook($payload);

        $this->assertSame(Referral::STATUS_CREDITED, $referral->fresh()->status);
    }

    // ── Credit math + retry ─────────────────────────────────────────────

    public function test_annual_referrer_gets_half_of_annual_price(): void
    {
        $referrer = $this->referrer();
        $this->contentSub($referrer, 'price_base_y');
        [$referral] = $this->pendingReferral($referrer);

        $this->fireWebhook($this->invoicePayload('cus_referred', [['price' => 'price_base_m']], 3900));

        $this->assertSame(intdiv(34800, 2), $referral->fresh()->credit_cents);
    }

    public function test_failed_credit_is_retried_by_sweep_command(): void
    {
        $referrer = $this->referrer();
        [$referral] = $this->pendingReferral($referrer);

        $this->bindProgram(function (): void {
            throw new \RuntimeException('stripe down');
        });
        $this->fireWebhook($this->invoicePayload('cus_referred', [['price' => 'price_base_m']], 3900));

        $fresh = $referral->fresh();
        $this->assertSame(Referral::STATUS_CREDIT_FAILED, $fresh->status);
        $this->assertStringContainsString('stripe down', $fresh->last_error);

        $this->bindProgram(); // working creditor again
        $this->artisan('ebq:grant-referral-rewards')->assertSuccessful();

        $this->assertSame(Referral::STATUS_CREDITED, $referral->fresh()->status);
        $this->assertCount(1, $this->credits);
    }

    // ── Page ────────────────────────────────────────────────────────────

    public function test_referral_page_renders_and_lazily_creates_code(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->referral_code);

        $response = $this->actingAs($user)->get(route('referrals.index'));

        $response->assertOk();
        $code = $user->fresh()->referral_code;
        $this->assertNotNull($code);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $code);
        $response->assertSee('?ref='.$code);
    }

    public function test_zero_website_user_reaches_referral_page(): void
    {
        // EnsureOnboarded normally bounces website-less users — referrals.* is allowlisted.
        $user = User::factory()->create();
        $this->assertSame(0, $user->websites()->count());

        $this->actingAs($user)->get(route('referrals.index'))->assertOk();
    }

    // ── Custom referral IDs ─────────────────────────────────────────────

    public function test_custom_code_is_saved_and_attributes_signups(): void
    {
        $referrer = $this->referrer();
        $program = app(ReferralProgram::class);

        $this->assertNull($program->setCustomCode($referrer, 'Malis-Agency'));
        $this->assertSame('malis-agency', $referrer->fresh()->referral_code); // stored lowercase

        // Hyphenated custom codes survive middleware capture + attribution.
        $response = $this->get('/?ref=malis-agency');
        $this->assertNotNull(collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === CaptureReferralCode::COOKIE));

        $this->withCookie(CaptureReferralCode::COOKIE, 'malis-agency')
            ->post(route('register'), [
                'name' => 'F', 'email' => 'custom@example.com',
                'password' => 'secret-password-1', 'password_confirmation' => 'secret-password-1',
            ]);
        $this->assertDatabaseHas('referrals', [
            'referrer_user_id' => $referrer->id,
            'code_used' => 'malis-agency',
            'status' => Referral::STATUS_PENDING,
        ]);
    }

    public function test_custom_code_rejects_taken_and_invalid_values(): void
    {
        $referrer = $this->referrer(); // holds abcd1234
        $other = User::factory()->create();
        $program = app(ReferralProgram::class);

        // Another user's code is refused; the holder keeps it.
        $this->assertSame('taken', $program->setCustomCode($other, 'abcd1234'));
        $this->assertSame('taken', $program->setCustomCode($other, 'ABCD1234')); // case-insensitive
        $this->assertNull($other->fresh()->referral_code);
        $this->assertSame('abcd1234', $referrer->fresh()->referral_code);

        // Shape violations.
        foreach (['ab', '-abcdef', 'abcdef-', 'has space', 'ünïcode1', str_repeat('a', 17)] as $bad) {
            $this->assertSame('invalid_format', $program->setCustomCode($other, $bad), $bad);
        }

        // Saving your own current code is a no-op success.
        $this->assertNull($program->setCustomCode($referrer, 'abcd1234'));
    }

    public function test_livewire_component_updates_code_and_url(): void
    {
        $user = User::factory()->create();
        $taken = User::factory()->create();
        $taken->forceFill(['referral_code' => 'claimed1'])->save();

        \Livewire\Livewire::actingAs($user)->test(\App\Livewire\Referrals\ReferralHub::class)
            ->set('editCode', 'claimed1')
            ->call('updateCode')
            ->assertHasErrors('editCode')
            ->set('editCode', 'my-brand')
            ->call('updateCode')
            ->assertHasNoErrors()
            ->assertSet('code', 'my-brand')
            ->assertSet('url', fn ($url) => str_ends_with($url, '/?ref=my-brand'));

        $this->assertSame('my-brand', $user->fresh()->referral_code);
    }

    public function test_referral_page_masks_referred_emails(): void
    {
        $referrer = User::factory()->create();
        $referred = User::factory()->create(['email' => 'johndoe@example.com']);
        Referral::query()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'code_used' => 'x', 'status' => Referral::STATUS_PENDING,
        ]);

        $response = $this->actingAs($referrer)->get(route('referrals.index'));

        $response->assertSee('j***@example.com');
        $response->assertDontSee('johndoe@example.com');
    }
}
