<div wire:key="website-team-{{ $websiteId }}-{{ $useSessionWebsite ? 'session' : 'embed' }}">
    @if ($useSessionWebsite)
        <div class="mx-auto max-w-3xl space-y-2">
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-slate-100">Team &amp; access</h1>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Invite collaborators and control which features they can use on this site. Access can be edited anytime.
            </p>
        </div>
    @endif

    <div @class([
        'rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900',
        'mt-6' => $useSessionWebsite,
        'mt-4 border-t border-slate-100 pt-4 dark:border-slate-800' => ! $useSessionWebsite,
    ])>
        @if ($emptyReason === 'select_website')
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <svg class="h-12 w-12 text-slate-300 dark:text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3" /></svg>
                <p class="mt-3 text-sm font-medium text-slate-600 dark:text-slate-300">Select a website</p>
                <p class="mt-1 max-w-sm text-xs text-slate-500 dark:text-slate-400">Choose a site from the dropdown in the header, then return here to invite teammates.</p>
            </div>
        @elseif ($emptyReason === 'no_access')
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                You do not have access to this website, or it no longer exists. Select another site in the header or open a site you own or have been invited to.
            </div>
        @elseif ($website)
            @if ($useSessionWebsite)
                <div class="mb-6 flex flex-wrap items-end justify-between gap-4 border-b border-slate-100 pb-6 dark:border-slate-800">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $website->domain }}</h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ $website->gsc_site_url }}</p>
                    </div>
                    @if ($readonly)
                        <span class="inline-flex items-center rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300">View only</span>
                    @endif
                </div>
            @else
                <h4 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Team</h4>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Invite collaborators by email and scope their access to specific features.</p>
            @endif

            @if ($readonly)
                <p class="mb-6 text-sm text-slate-600 dark:text-slate-300">
                    Only the site owner or an admin can send invites, remove members, or change access. You can still see who has access below.
                </p>
            @endif

            {{-- Flash --}}
            @if (session('team_status'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                    class="mb-4 flex items-center justify-between rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">
                    <span>{{ session('team_status') }}</span>
                    <button @click="show = false" class="text-emerald-700/60">×</button>
                </div>
            @endif
            @if (session('team_error'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
                    class="mb-4 flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
                    <span>{{ session('team_error') }}</span>
                    <button @click="show = false" class="text-amber-800/60">×</button>
                </div>
            @endif

            {{-- Invite form --}}
            @if (! $readonly)
                <form wire:submit="inviteMember" class="rounded-lg border border-slate-200 bg-slate-50/50 p-4 dark:border-slate-800 dark:bg-slate-800/30">
                    <div class="mb-3 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Invite a teammate</div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] sm:items-end">
                        <div>
                            <label for="invite-email-{{ $websiteId }}" class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">Email address</label>
                            <input id="invite-email-{{ $websiteId }}" wire:model="inviteEmail" type="email" placeholder="colleague@example.com" autocomplete="email"
                                class="h-9 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-700 dark:bg-slate-800" />
                            @error('inviteEmail')<p class="mt-1 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">Role</label>
                            <select wire:model.live="inviteRole" class="h-9 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="member">Member (custom access)</option>
                                <option value="admin">Admin (full access)</option>
                            </select>
                        </div>
                        <button type="submit" wire:loading.attr="disabled" wire:target="inviteMember"
                            class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-md bg-indigo-600 px-3.5 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60">
                            <svg wire:loading wire:target="inviteMember" class="h-3.5 w-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle><path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path></svg>
                            Send invite
                        </button>
                    </div>

                    @if ($inviteRole === 'member')
                        <div class="mt-4 rounded-md border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
                            <div class="mb-2 flex items-center justify-between">
                                <div class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Feature access</div>
                                <div class="flex items-center gap-2 text-[10px]">
                                    <button type="button" wire:click="$set('invitePermissions', {{ json_encode(array_fill_keys(array_keys($features), true)) }})" class="text-indigo-600 hover:underline">Select all</button>
                                    <span class="text-slate-300">·</span>
                                    <button type="button" wire:click="$set('invitePermissions', {{ json_encode(array_fill_keys(array_keys($features), false)) }})" class="text-slate-500 hover:underline">Clear</button>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($features as $key => $feature)
                                    <label class="flex cursor-pointer items-start gap-2 rounded-md border border-transparent p-2 transition hover:border-slate-200 hover:bg-slate-50 dark:hover:border-slate-700 dark:hover:bg-slate-800/60">
                                        <input type="checkbox" wire:model="invitePermissions.{{ $key }}" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500" />
                                        <div class="min-w-0">
                                            <div class="text-xs font-semibold text-slate-800 dark:text-slate-200">{{ $feature['label'] }}</div>
                                            <div class="text-[10px] text-slate-500 dark:text-slate-400">{{ $feature['description'] }}</div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @else
                        <p class="mt-3 flex items-center gap-2 text-[11px] text-slate-500 dark:text-slate-400">
                            <svg class="h-3.5 w-3.5 text-indigo-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                            Admins have full access to every feature and can manage the team.
                        </p>
                    @endif
                </form>
            @endif

            {{-- Members --}}
            <div class="mt-8">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Members</h3>
                <ul class="mt-3 space-y-2">
                    {{-- Owner --}}
                    <li class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2.5 text-sm dark:bg-slate-800/50">
                        <div class="min-w-0">
                            <span class="font-medium text-slate-900 dark:text-slate-100">{{ $website->user->name }}</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $website->user->email }}</span>
                        </div>
                        <span class="rounded-md bg-indigo-100 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-indigo-800 dark:bg-indigo-500/20 dark:text-indigo-200">Owner</span>
                    </li>

                    {{-- Members --}}
                    @foreach ($website->members as $member)
                        @php
                            $role = $member->pivot->role ?? 'member';
                            $perms = null;
                            if ($member->pivot->permissions) {
                                $decoded = is_string($member->pivot->permissions) ? json_decode($member->pivot->permissions, true) : $member->pivot->permissions;
                                if (is_array($decoded)) $perms = array_values(array_filter($decoded, 'is_string'));
                            }
                            $hasFullAccess = $role === 'admin' || $perms === null;
                            $featureCount = count($features);
                            $grantedCount = $hasFullAccess ? $featureCount : count($perms ?? []);
                        @endphp
                        <li class="rounded-lg border border-slate-200 bg-white px-3 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-900" wire:key="member-{{ $member->id }}">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-medium text-slate-900 dark:text-slate-100">{{ $member->name }}</span>
                                        <span @class([
                                            'rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                            'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' => $role === 'admin',
                                            'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200' => $role !== 'admin',
                                        ])>{{ $role === 'admin' ? 'Admin' : 'Member' }}</span>
                                    </div>
                                    <span class="block text-xs text-slate-500 dark:text-slate-400">{{ $member->email }}</span>
                                    <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                        @if ($hasFullAccess)
                                            <span class="font-semibold text-emerald-700 dark:text-emerald-400">Full access</span>
                                        @else
                                            <span class="font-semibold">{{ $grantedCount }}/{{ $featureCount }} features:</span>
                                            @foreach ($perms ?? [] as $p)
                                                <span class="mr-1 inline-block rounded bg-slate-100 px-1.5 py-px text-[10px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $features[$p]['label'] ?? $p }}</span>
                                            @endforeach
                                        @endif
                                    </div>
                                </div>
                                @if (! $readonly)
                                    <div class="flex shrink-0 items-center gap-1">
                                        <button type="button" wire:click="startEditMember('{{ $member->id }}')"
                                            class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                            <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zM19.5 8.25l-3.75-3.75" /></svg>
                                            Edit access
                                        </button>
                                        <button type="button" wire:click="revokeMember('{{ $member->id }}')" wire:confirm="Remove this member from the site?"
                                            class="rounded-md px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">
                                            Remove
                                        </button>
                                    </div>
                                @endif
                            </div>

                            {{-- Inline edit --}}
                            @if (! $readonly && $editTargetType === 'member' && $editTargetId === $member->id)
                                @include('livewire.websites.partials.edit-access', ['features' => $features])
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Pending invitations --}}
            @if ($website->invitations->isNotEmpty())
                <div class="mt-8">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Pending invitations</h3>
                    <ul class="mt-3 space-y-2">
                        @foreach ($website->invitations as $inv)
                            @php
                                $expired = $inv->expires_at && $inv->expires_at->isPast();
                                $sentAt = $inv->updated_at ?? $inv->created_at;
                                $invRole = $inv->role ?: 'member';
                                $invPerms = $inv->permissions;
                                $invHasFullAccess = $invRole === 'admin' || $invPerms === null;
                                $invGrantedCount = $invHasFullAccess ? count($features) : count($invPerms ?? []);
                            @endphp
                            <li class="rounded-lg border border-dashed border-slate-300 px-3 py-2.5 text-sm dark:border-slate-700" wire:key="inv-{{ $inv->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium text-slate-800 dark:text-slate-200">{{ $inv->email }}</span>
                                            @if ($expired)
                                                <span class="rounded-full bg-red-50 px-2 py-px text-[10px] font-semibold uppercase text-red-600 dark:bg-red-500/10 dark:text-red-400">Expired</span>
                                            @else
                                                <span class="rounded-full bg-amber-50 px-2 py-px text-[10px] font-semibold uppercase text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">Pending</span>
                                            @endif
                                            <span @class([
                                                'rounded-md px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                                'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-300' => $invRole === 'admin',
                                                'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200' => $invRole !== 'admin',
                                            ])>{{ $invRole === 'admin' ? 'Admin' : 'Member' }}</span>
                                        </div>
                                        <div class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">
                                            @if ($sentAt)Sent {{ $sentAt->diffForHumans() }}@endif
                                            @if ($inv->expires_at)
                                                · {{ $expired ? 'Expired' : 'Expires' }} {{ $inv->expires_at->diffForHumans() }}
                                            @endif
                                        </div>
                                        <div class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                                            @if ($invHasFullAccess)
                                                <span class="font-semibold text-emerald-700 dark:text-emerald-400">Full access</span>
                                            @else
                                                <span class="font-semibold">{{ $invGrantedCount }}/{{ count($features) }} features:</span>
                                                @foreach ($invPerms ?? [] as $p)
                                                    <span class="mr-1 inline-block rounded bg-slate-100 px-1.5 py-px text-[10px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $features[$p]['label'] ?? $p }}</span>
                                                @endforeach
                                            @endif
                                        </div>
                                    </div>
                                    @if (! $readonly)
                                        <div class="flex shrink-0 flex-wrap items-center gap-1">
                                            <button type="button" wire:click="startEditInvitation('{{ $inv->id }}')"
                                                class="inline-flex items-center gap-1 rounded-md border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                                <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zM19.5 8.25l-3.75-3.75" /></svg>
                                                Edit access
                                            </button>
                                            <button type="button"
                                                wire:click="resendInvitation('{{ $inv->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="resendInvitation('{{ $inv->id }}')"
                                                class="inline-flex items-center gap-1 rounded-md bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white shadow-sm transition hover:bg-indigo-700 disabled:opacity-60">
                                                <svg wire:loading.remove wire:target="resendInvitation('{{ $inv->id }}')" class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 9v.906a2.25 2.25 0 0 1-1.183 1.981l-6.478 3.488M2.25 9v.906a2.25 2.25 0 0 0 1.183 1.981l6.478 3.488m8.839 2.51-4.66-2.51m0 0-1.023-.55a2.25 2.25 0 0 0-2.134 0l-1.022.55m0 0-4.661 2.51m16.5 1.615a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V8.844a2.25 2.25 0 0 1 1.183-1.98l7.5-4.04a2.25 2.25 0 0 1 2.134 0l7.5 4.04a2.25 2.25 0 0 1 1.183 1.98V19.5Z" /></svg>
                                                <svg wire:loading wire:target="resendInvitation('{{ $inv->id }}')" class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle><path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 018-8V0C5.4 0 0 5.4 0 12h4z"></path></svg>
                                                <span wire:loading.remove wire:target="resendInvitation('{{ $inv->id }}')">Resend</span>
                                                <span wire:loading wire:target="resendInvitation('{{ $inv->id }}')">Sending…</span>
                                            </button>
                                            <button type="button" wire:click="cancelInvitation('{{ $inv->id }}')" wire:confirm="Cancel this invitation?"
                                                class="rounded-md px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">
                                                Cancel
                                            </button>
                                        </div>
                                    @endif
                                </div>

                                @if (! $readonly && $editTargetType === 'invitation' && $editTargetId === $inv->id)
                                    @include('livewire.websites.partials.edit-access', ['features' => $features])
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
    </div>
</div>
