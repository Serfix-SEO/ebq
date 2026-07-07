<?php

namespace Tests\Feature;

use App\Mail\TrialExpiryMail;
use App\Models\Plan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Trial-expiry pipeline (2026-07-07): 14d trial (Trial plan trial_days) →
 * countdown emails at expiry/48h/24h/12h → data deletion after the 3-day
 * buffer. Login survives; no fresh trial; shared crawl data safe; admins,
 * subscribers and comped plans exempt. Plus the billing-page lockout.
 */
class TrialCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.free' => false]);
        Plan::create(['slug' => 'trial', 'name' => 'Trial', 'trial_days' => 14, 'is_active' => true, 'max_websites' => 1]);
    }

    private function trialUser(int $ageHours): User
    {
        $u = User::factory()->create(['email_verified_at' => now()]);
        $u->forceFill(['created_at' => now()->subHours($ageHours)])->saveQuietly();

        return $u->fresh();
    }

    public function test_expiry_email_sent_once_when_buffer_starts(): void
    {
        Mail::fake();
        $user = $this->trialUser(14 * 24 + 12); // 60h to deletion — only the 'expired' stage is crossed
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'once.test']);

        $this->artisan('ebq:trial-cleanup')->assertSuccessful();
        $this->artisan('ebq:trial-cleanup')->assertSuccessful(); // second run: no dupe

        Mail::assertSentCount(1);
        Mail::assertSent(TrialExpiryMail::class, fn (TrialExpiryMail $m) => $m->hasTo($user->email));
    }

    public function test_countdown_anchors_to_first_notice_and_stages_progress(): void
    {
        // Even a long-expired account (predating the feature) gets the FULL
        // 3-day countdown starting from its first 'expired' email — never a
        // "deleted in 12 hours" first contact.
        Mail::fake();
        $user = $this->trialUser(40 * 24); // way past schedule
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'stages.test']);

        $this->artisan('ebq:trial-cleanup');
        Mail::assertSent(TrialExpiryMail::class, fn (TrialExpiryMail $m) => $m->stage === 'expired');

        // Immediately after: 72h left, no further stage crossed.
        Mail::fake();
        $this->artisan('ebq:trial-cleanup');
        Mail::assertNothingSent();

        // 25h later: <48h left -> h48.
        $this->travel(25)->hours();
        Mail::fake();
        $this->artisan('ebq:trial-cleanup');
        Mail::assertSent(TrialExpiryMail::class, fn (TrialExpiryMail $m) => $m->stage === 'h48');

        // +24h: <24h left -> h24. +13h: <12h left -> h12.
        $this->travel(24)->hours();
        Mail::fake();
        $this->artisan('ebq:trial-cleanup');
        Mail::assertSent(TrialExpiryMail::class, fn (TrialExpiryMail $m) => $m->stage === 'h24');

        $this->travel(13)->hours();
        Mail::fake();
        $this->artisan('ebq:trial-cleanup');
        Mail::assertSent(TrialExpiryMail::class, fn (TrialExpiryMail $m) => $m->stage === 'h12');
    }

    public function test_data_deleted_after_buffer_login_survives(): void
    {
        Mail::fake();
        $user = $this->trialUser(18 * 24);
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'expired-solo.test']);

        // Run 1 anchors the countdown (sends 'expired'); deletion fires only
        // once the full 72h buffer from that first notice has passed.
        $this->artisan('ebq:trial-cleanup')->assertSuccessful();
        $this->assertDatabaseHas('websites', ['id' => $website->id]);

        $this->travel(73)->hours();
        $this->artisan('ebq:trial-cleanup')->assertSuccessful();

        $this->assertDatabaseMissing('websites', ['id' => $website->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]); // login survives
        $this->assertNotNull($user->fresh()->trial_data_deleted_at);

        // A re-added website is NOT exempt — it restarts a FRESH countdown
        // (warning email first, deletion only after the full 72h buffer), so
        // an expired-but-unlocked team member can't park data free forever.
        Mail::fake();
        $second = Website::factory()->create(['user_id' => $user->id, 'domain' => 'readded.test']);
        $this->artisan('ebq:trial-cleanup')->assertSuccessful();
        $this->assertDatabaseHas('websites', ['id' => $second->id]); // warned, not deleted
        Mail::assertSent(TrialExpiryMail::class, fn (TrialExpiryMail $m) => $m->stage === 'expired');

        $this->travel(73)->hours();
        $this->artisan('ebq:trial-cleanup')->assertSuccessful();
        $this->assertDatabaseMissing('websites', ['id' => $second->id]);
    }

    public function test_team_member_is_not_locked_out_and_membership_survives(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $ownerSite = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'owner-site.test']);

        $member = $this->trialUser(20 * 24); // way past trial
        $ownerSite->members()->attach($member->id, ['role' => 'member']);

        session(['current_website_id' => $ownerSite->id]);
        $this->actingAs($member)->get(route('dashboard'))->assertOk(); // no billing lockout

        // Cleanup: member owns no websites — no scary deletion emails, no
        // deletion, membership pivot untouched, owner's site untouched.
        $this->artisan('ebq:trial-cleanup')->assertSuccessful();
        Mail::assertNothingSent();
        $this->assertDatabaseHas('websites', ['id' => $ownerSite->id]);
        $this->assertDatabaseHas('website_user', ['website_id' => $ownerSite->id, 'user_id' => $member->id]);
        $this->assertNull($member->fresh()->trial_data_deleted_at);
    }

    public function test_expired_member_keeps_access_but_own_sites_still_deleted(): void
    {
        Mail::fake();
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $ownerSite = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'team-owner.test']);

        $member = $this->trialUser(20 * 24);
        $ownerSite->members()->attach($member->id, ['role' => 'member']);
        $ownSite = Website::factory()->create(['user_id' => $member->id, 'domain' => 'member-own.test']);
        $ownSite->forceFill(['created_at' => now()->subDays(10)])->saveQuietly(); // predates the anchor
        // Pre-anchor the countdown 73h ago so this run deletes immediately.
        $member->forceFill(['trial_deletion_notices' => ['expired' => now()->subHours(73)->toIso8601String()]])->saveQuietly();

        $this->artisan('ebq:trial-cleanup')->assertSuccessful();

        $this->assertDatabaseMissing('websites', ['id' => $ownSite->id]); // own trial data gone
        $this->assertDatabaseHas('websites', ['id' => $ownerSite->id]);   // team site untouched
        $this->assertDatabaseHas('website_user', ['website_id' => $ownerSite->id, 'user_id' => $member->id]);

        // Still not locked out afterwards — team access continues.
        session(['current_website_id' => $ownerSite->id]);
        $this->actingAs($member->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_h24_email_carries_winback_promo_offer(): void
    {
        config(['services.stripe.winback_promo_code' => 'SAVE20']);
        $user = $this->trialUser(15 * 24);

        $h24 = (new TrialExpiryMail($user, 'h24', now()->addHours(20)))->render();
        $this->assertStringContainsString('SAVE20', $h24);
        $this->assertStringContainsString('promo=SAVE20', $h24); // auto-apply link
        $this->assertStringContainsString('20% off', $h24);

        // Offer is h24-only, and disabled entirely when the code is unset.
        $expired = (new TrialExpiryMail($user, 'expired', now()->addHours(72)))->render();
        $this->assertStringNotContainsString('SAVE20', $expired);

        config(['services.stripe.winback_promo_code' => '']);
        $h24Off = (new TrialExpiryMail($user, 'h24', now()->addHours(20)))->render();
        $this->assertStringNotContainsString('20% off', $h24Off);
    }

    public function test_stale_anchor_resets_for_readded_site(): void
    {
        // User was warned, self-deleted everything, later re-adds a site:
        // the old anchor must NOT instant-delete the new site.
        Mail::fake();
        $user = $this->trialUser(30 * 24);
        $user->forceFill(['trial_deletion_notices' => ['expired' => now()->subDays(20)->toIso8601String()]])->saveQuietly();
        $site = Website::factory()->create(['user_id' => $user->id, 'domain' => 'fresh-after-stale.test']);

        $this->artisan('ebq:trial-cleanup')->assertSuccessful();

        $this->assertDatabaseHas('websites', ['id' => $site->id]); // fresh countdown, not deleted
        Mail::assertSent(TrialExpiryMail::class, fn (TrialExpiryMail $m) => $m->stage === 'expired');
        $this->assertArrayHasKey('expired', (array) $user->fresh()->trial_deletion_notices);
    }

    public function test_shared_crawl_site_survives_when_other_client_subscribes(): void
    {
        Mail::fake();
        $expired = $this->trialUser(18 * 24);
        // Pre-anchor the countdown 73h ago so this run deletes immediately.
        $expired->forceFill(['trial_deletion_notices' => ['expired' => now()->subHours(73)->toIso8601String()]])->saveQuietly();
        $siteA = Website::factory()->create(['user_id' => $expired->id, 'domain' => 'shared-domain.test']);
        $siteA->forceFill(['created_at' => now()->subDays(10)])->saveQuietly(); // predates the anchor

        $paying = User::factory()->create();
        $siteB = Website::factory()->create(['user_id' => $paying->id, 'domain' => 'shared-domain.test']);
        $this->assertSame($siteA->crawl_site_id, $siteB->crawl_site_id, 'both must share one crawl_site');

        \App\Models\WebsitePage::create([
            'crawl_site_id' => $siteA->crawl_site_id,
            'url' => 'https://shared-domain.test/', 'url_hash' => \App\Models\WebsitePage::hashUrl('https://shared-domain.test/'),
            'http_status' => 200, 'is_indexable' => true, 'last_crawled_at' => now(),
        ]);

        $this->artisan('ebq:trial-cleanup')->assertSuccessful();

        // Client A's website gone; Client B + the SHARED crawl data intact.
        $this->assertDatabaseMissing('websites', ['id' => $siteA->id]);
        $this->assertDatabaseHas('websites', ['id' => $siteB->id]);
        $this->assertDatabaseHas('crawl_sites', ['id' => $siteA->crawl_site_id]);
        $this->assertSame(1, \App\Models\WebsitePage::where('crawl_site_id', $siteA->crawl_site_id)->count());
    }

    public function test_admins_subscribers_and_comped_are_exempt(): void
    {
        Mail::fake();
        $admin = $this->trialUser(30 * 24);
        $admin->forceFill(['is_admin' => true])->saveQuietly();

        $comped = $this->trialUser(30 * 24);
        $comped->forceFill(['current_plan_slug' => 'agency'])->saveQuietly();

        $subscriber = $this->trialUser(30 * 24);
        DB::table('subscriptions')->insert([
            'id' => 1, 'user_id' => $subscriber->id, 'type' => 'default',
            'stripe_id' => 'sub_x', 'stripe_status' => 'active', 'stripe_price' => 'p', 'quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$admin, $comped, $subscriber] as $u) {
            Website::factory()->create(['user_id' => $u->id, 'domain' => 'keep-'.substr(md5($u->id), 0, 6).'.test']);
        }

        $this->artisan('ebq:trial-cleanup')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(3, Website::count());
    }

    public function test_expired_user_is_locked_to_billing_page(): void
    {
        $user = $this->trialUser(15 * 24);
        Website::factory()->create(['user_id' => $user->id, 'domain' => 'locked.test']);
        session(['current_website_id' => Website::where('user_id', $user->id)->value('id')]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('billing.show'));
        $this->actingAs($user)->get(route('billing.show'))->assertOk();
    }

    public function test_active_trial_user_is_not_locked(): void
    {
        $user = $this->trialUser(5 * 24); // day 5 of 14
        $w = Website::factory()->create(['user_id' => $user->id, 'domain' => 'active-trial.test']);
        session(['current_website_id' => $w->id]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }
}
