<?php

namespace App\Livewire\Websites;

use App\Mail\WebsiteAccessGrantedMail;
use App\Mail\WebsiteTeamInvitationMail;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteInvitation;
use App\Support\TeamPermissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class WebsiteTeam extends Component
{
    public ?string $websiteId = null;

    public bool $useSessionWebsite = false;

    public bool $readonly = false;

    // Invite form
    public string $inviteEmail = '';
    public string $inviteRole = TeamPermissions::ROLE_MEMBER;
    /** @var array<string, bool> */
    public array $invitePermissions = [];

    // Edit access modal (works for both members and pending invitations)
    public ?string $editTargetType = null; // 'member' | 'invitation'
    public ?string $editTargetId = null;
    public string $editRole = TeamPermissions::ROLE_MEMBER;
    /** @var array<string, bool> */
    public array $editPermissions = [];

    public function mount(?string $websiteId = null, bool $useSessionWebsite = false): void
    {
        $this->useSessionWebsite = $useSessionWebsite;

        if ($useSessionWebsite) {
            $this->websiteId = session('current_website_id');
            $this->applySessionModeFlags();
        } else {
            $this->websiteId = $websiteId;
            Gate::authorize('update', Website::findOrFail($this->websiteId));
            $this->readonly = false;
        }

        $this->invitePermissions = $this->defaultPermissionMap();
    }

    /** @return array<string, bool> */
    private function defaultPermissionMap(): array
    {
        $out = [];
        foreach (TeamPermissions::featureKeys() as $key) {
            $out[$key] = true;
        }

        return $out;
    }

    #[On('website-changed')]
    public function onWebsiteChanged(string $websiteId): void
    {
        if (! $this->useSessionWebsite) {
            return;
        }

        $this->websiteId = $websiteId;
        $this->applySessionModeFlags();
        $this->cancelEdit();
    }

    public function updatedInviteRole(string $value): void
    {
        if ($value === TeamPermissions::ROLE_ADMIN) {
            $this->invitePermissions = $this->defaultPermissionMap();
        }
    }

    public function updatedEditRole(string $value): void
    {
        if ($value === TeamPermissions::ROLE_ADMIN) {
            $this->editPermissions = $this->defaultPermissionMap();
        }
    }

    public function inviteMember(): void
    {
        if ($this->readonly || ($this->websiteId === null || $this->websiteId === '')) {
            return;
        }

        $website = Website::findOrFail($this->websiteId);
        Gate::authorize('update', $website);

        $this->validate([
            'inviteEmail' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'inviteRole' => ['required', 'in:admin,member'],
        ]);

        $email = Str::lower(trim($this->inviteEmail));

        if (Str::lower($website->user->email) === $email) {
            $this->addError('inviteEmail', 'This email belongs to the site owner.');

            return;
        }

        $permissions = $this->inviteRole === TeamPermissions::ROLE_ADMIN
            ? null
            : TeamPermissions::normalize(array_keys(array_filter($this->invitePermissions)));

        $target = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($target) {
            if ($website->members()->whereKey($target->id)->exists()) {
                $this->addError('inviteEmail', 'This user is already a team member.');

                return;
            }

            WebsiteInvitation::query()
                ->where('website_id', $website->id)
                ->where('email', $email)
                ->delete();

            $website->members()->syncWithoutDetaching([
                $target->id => [
                    'role' => $this->inviteRole,
                    'permissions' => $permissions !== null ? json_encode(array_values($permissions)) : null,
                ],
            ]);

            Mail::to($target)->send(new WebsiteAccessGrantedMail($website, $target));
        } else {
            WebsiteInvitation::query()
                ->where('website_id', $website->id)
                ->where('email', $email)
                ->delete();

            [$invitation, $plain] = WebsiteInvitation::issue(
                $website,
                $email,
                Auth::id(),
                14,
                $this->inviteRole,
                $permissions,
            );
            Mail::to($email)->send(new WebsiteTeamInvitationMail($invitation, $plain));
        }

        $this->reset('inviteEmail');
        $this->inviteRole = TeamPermissions::ROLE_MEMBER;
        $this->invitePermissions = $this->defaultPermissionMap();
        session()->flash('team_status', 'Invitation sent.');
        $this->dispatch('website-team-updated');
    }

    public function revokeMember(string $userId): void
    {
        if ($this->readonly || ($this->websiteId === null || $this->websiteId === '')) {
            return;
        }

        $website = Website::findOrFail($this->websiteId);
        Gate::authorize('update', $website);

        if ($userId === (string) $website->user_id) {
            return;
        }

        $website->members()->detach($userId);
        $this->cancelEdit();
        $this->dispatch('website-team-updated');
    }

    public function resendInvitation(string $invitationId): void
    {
        if ($this->readonly || ($this->websiteId === null || $this->websiteId === '')) {
            return;
        }

        $invitation = WebsiteInvitation::findOrFail($invitationId);
        Gate::authorize('update', $invitation->website);

        if ((string) $invitation->website_id !== $this->websiteId) {
            return;
        }

        $limiterKey = 'invite-resend:'.$invitation->id;
        if (RateLimiter::tooManyAttempts($limiterKey, 3)) {
            $seconds = RateLimiter::availableIn($limiterKey);
            session()->flash(
                'team_error',
                'Please wait '.$seconds.' seconds before resending this invitation again.'
            );

            return;
        }
        RateLimiter::hit($limiterKey, 300);

        $plain = Str::random(64);
        $invitation->forceFill([
            'token' => hash('sha256', $plain),
            'expires_at' => now()->addDays(14),
            'invited_by_user_id' => (int) (Auth::id() ?? $invitation->invited_by_user_id),
        ])->save();

        Mail::to($invitation->email)->send(new WebsiteTeamInvitationMail($invitation, $plain));

        session()->flash('team_status', 'Invitation resent to '.$invitation->email.'.');
        $this->dispatch('website-team-updated');
    }

    public function cancelInvitation(string $invitationId): void
    {
        if ($this->readonly || ($this->websiteId === null || $this->websiteId === '')) {
            return;
        }

        $invitation = WebsiteInvitation::findOrFail($invitationId);
        Gate::authorize('update', $invitation->website);

        if ((string) $invitation->website_id !== $this->websiteId) {
            return;
        }

        $invitation->delete();
        $this->cancelEdit();
        $this->dispatch('website-team-updated');
    }

    public function startEditMember(string $userId): void
    {
        if ($this->readonly || ($this->websiteId === null || $this->websiteId === '')) {
            return;
        }

        $website = Website::findOrFail($this->websiteId);
        Gate::authorize('update', $website);

        if ($userId === (string) $website->user_id) {
            return; // owner can't be edited
        }

        $row = DB::table('website_user')
            ->where('website_id', $this->websiteId)
            ->where('user_id', $userId)
            ->first();

        if (! $row) {
            return;
        }

        $this->editTargetType = 'member';
        $this->editTargetId = $userId;
        $this->editRole = (string) ($row->role ?: TeamPermissions::ROLE_MEMBER);

        $decoded = $row->permissions ? json_decode((string) $row->permissions, true) : null;
        $this->editPermissions = $this->permissionsToMap(is_array($decoded) ? $decoded : null);
    }

    public function startEditInvitation(string $invitationId): void
    {
        if ($this->readonly || ($this->websiteId === null || $this->websiteId === '')) {
            return;
        }

        $invitation = WebsiteInvitation::findOrFail($invitationId);
        Gate::authorize('update', $invitation->website);

        if ((string) $invitation->website_id !== $this->websiteId) {
            return;
        }

        $this->editTargetType = 'invitation';
        $this->editTargetId = $invitation->id;
        $this->editRole = (string) ($invitation->role ?: TeamPermissions::ROLE_MEMBER);
        $this->editPermissions = $this->permissionsToMap($invitation->permissions);
    }

    public function cancelEdit(): void
    {
        $this->editTargetType = null;
        $this->editTargetId = null;
        $this->editRole = TeamPermissions::ROLE_MEMBER;
        $this->editPermissions = $this->defaultPermissionMap();
    }

    public function saveEdit(): void
    {
        if ($this->readonly || ($this->websiteId === null || $this->websiteId === '') || $this->editTargetId === null) {
            return;
        }

        $this->validate([
            'editRole' => ['required', 'in:admin,member'],
        ]);

        $website = Website::findOrFail($this->websiteId);
        Gate::authorize('update', $website);

        $permissions = $this->editRole === TeamPermissions::ROLE_ADMIN
            ? null
            : TeamPermissions::normalize(array_keys(array_filter($this->editPermissions)));

        if ($this->editTargetType === 'member') {
            if ((string) $this->editTargetId === (string) $website->user_id) {
                return;
            }

            DB::table('website_user')
                ->where('website_id', $this->websiteId)
                ->where('user_id', $this->editTargetId)
                ->update([
                    'role' => $this->editRole,
                    'permissions' => $permissions !== null ? json_encode(array_values($permissions)) : null,
                    'updated_at' => now(),
                ]);

            session()->flash('team_status', 'Member access updated.');
        } elseif ($this->editTargetType === 'invitation') {
            $invitation = WebsiteInvitation::find($this->editTargetId);
            if ($invitation && (string) $invitation->website_id === $this->websiteId) {
                $invitation->forceFill([
                    'role' => $this->editRole,
                    'permissions' => $permissions,
                ])->save();
                session()->flash('team_status', 'Invitation access updated.');
            }
        }

        $this->cancelEdit();
        $this->dispatch('website-team-updated');
    }

    /**
     * @param  list<string>|null  $permissions
     * @return array<string, bool>
     */
    private function permissionsToMap(?array $permissions): array
    {
        $map = $this->defaultPermissionMap();
        if ($permissions === null) {
            return $map;
        }
        foreach ($map as $k => $_) {
            $map[$k] = in_array($k, $permissions, true);
        }

        return $map;
    }

    public function render()
    {
        $emptyReason = null;
        $website = null;

        if ($this->useSessionWebsite) {
            if (($this->websiteId === null || $this->websiteId === '')) {
                $emptyReason = 'select_website';
            } else {
                $website = Website::query()
                    ->with(['members', 'invitations', 'user'])
                    ->find($this->websiteId);

                $user = Auth::user();
                if (! $website || ! $user?->can('view', $website)) {
                    $website = null;
                    $emptyReason = 'no_access';
                }
            }
        } else {
            $website = Website::query()
                ->with(['members', 'invitations', 'user'])
                ->find($this->websiteId);
        }

        return view('livewire.websites.website-team', [
            'website' => $website,
            'emptyReason' => $emptyReason,
            'features' => TeamPermissions::FEATURES,
        ]);
    }

    private function applySessionModeFlags(): void
    {
        if (($this->websiteId === null || $this->websiteId === '')) {
            $this->readonly = true;

            return;
        }

        $website = Website::find($this->websiteId);
        $user = Auth::user();

        if (! $website || ! $user?->can('view', $website)) {
            $this->readonly = true;

            return;
        }

        $this->readonly = ! $user->can('update', $website);
    }
}
