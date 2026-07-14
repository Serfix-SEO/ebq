<?php

namespace Tests\Feature;

use App\Livewire\Websites\WebsiteTeam;
use App\Mail\WebsiteAccessGrantedMail;
use App\Mail\WebsiteTeamInvitationMail;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class WebsiteTeamAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_with_only_shared_website_can_access_dashboard_and_backlinks(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $website = Website::factory()->create([
            'user_id' => $owner->id,
            'domain' => 'shared-example.test',
        ]);
        $website->members()->attach($member->id);

        $this->actingAs($member)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('dashboard'))
            ->assertOk();

        $this->actingAs($member)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('backlinks.index'))
            ->assertOk();
    }

    public function test_member_cannot_view_foreign_website(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id]);
        $foreign = Website::factory()->create(['user_id' => User::factory()->create()->id]);

        $website->members()->attach($member->id);

        $this->assertTrue($member->can('view', $website));
        $this->assertFalse($member->can('view', $foreign));
        $this->assertFalse($member->canViewWebsiteId($foreign->id));
    }

    public function test_owner_inviting_existing_user_attaches_and_sends_mail(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $member = User::factory()->create(['email' => 'member-invite@example.com']);
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'invite-owner.test']);

        Livewire::actingAs($owner)
            ->test(WebsiteTeam::class, ['websiteId' => $website->id])
            ->set('inviteEmail', 'member-invite@example.com')
            ->call('inviteMember')
            ->assertHasNoErrors();

        $this->assertTrue($website->members()->whereKey($member->id)->exists());
        Mail::assertSent(WebsiteAccessGrantedMail::class, function (WebsiteAccessGrantedMail $mail) use ($member): bool {
            return $mail->hasTo($member->email);
        });
    }

    public function test_owner_inviting_new_user_creates_invitation_and_sends_mail(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'new-invite.test']);

        Livewire::actingAs($owner)
            ->test(WebsiteTeam::class, ['websiteId' => $website->id])
            ->set('inviteEmail', 'brand-new@example.com')
            ->call('inviteMember')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('website_invitations', [
            'website_id' => $website->id,
            'email' => 'brand-new@example.com',
        ]);
        Mail::assertSent(WebsiteTeamInvitationMail::class, function (WebsiteTeamInvitationMail $mail): bool {
            return $mail->hasTo('brand-new@example.com');
        });
    }

    public function test_register_with_valid_invite_attaches_member(): void
    {
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'register-invite.test']);

        [, $plain] = WebsiteInvitation::issue($website, 'joiner@example.com', $owner->id);

        $this->post(route('register'), [
            'name' => 'Joiner',
            'email' => 'joiner@example.com',
            'dial_code' => '+1',
            'phone' => '5551234567',
            'password' => 'password',
            'password_confirmation' => 'password',
            'invite' => $plain,
            // Fresh registrants are unverified and the app sits behind the
            // `verified` middleware, so registration lands on verify-email
            // (not the dashboard). The invite is still accepted immediately.
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'joiner@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->can('view', $website));
        $this->assertDatabaseMissing('website_invitations', [
            'website_id' => $website->id,
            'email' => 'joiner@example.com',
        ]);
    }

    public function test_revoke_member_removes_access(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id]);
        $website->members()->attach($member->id);

        Livewire::actingAs($owner)
            ->test(WebsiteTeam::class, ['websiteId' => $website->id])
            ->call('revokeMember', $member->id);

        $this->assertFalse($member->fresh()->can('view', $website));
    }

    public function test_team_page_loads_for_owner_with_selected_website(): void
    {
        $owner = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'team-page.test']);

        $this->actingAs($owner)
            ->withSession(['current_website_id' => $website->id])
            ->get(route('team.index'))
            ->assertOk()
            ->assertSee('team-page.test')
            ->assertSee('Send invite', false);
    }

    public function test_member_cannot_update_website_policy(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $owner->id]);
        $website->members()->attach($member->id);

        $this->assertFalse(Gate::forUser($member)->allows('update', $website));
    }
}
