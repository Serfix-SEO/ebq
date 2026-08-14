{{-- Client identity cell: avatar, name, id, email, plan badge and trial clock.
     Shared by the desktop table row and the mobile card.

     Expects: $client, $initialsFor, $avatarBg --}}
    <div class="flex items-center gap-2.5">
        <span @class(['flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-[11px] font-bold', $avatarBg($client->id)])>
            {{ $initialsFor((string) $client->name, (string) $client->email) }}
        </span>
        <div class="min-w-0">
            <div class="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                <span class="truncate">{{ $client->name }}</span>
                <span class="text-[10px] font-normal tabular-nums text-slate-400">#{{ $client->id }}</span>
            </div>
            <div class="flex items-center gap-1.5 truncate text-xs text-slate-500">
                <span class="truncate">{{ $client->email }}</span>
                {{-- 'free' was renamed to 'trial' in the 5-tier rework (User::TIER_FREE
                     is now just an alias for TIER_TRIAL) — null current_plan_slug means
                     "no comp set, falls back to Trial", not a literal 'free' plan row. --}}
                @php $planSlug = $client->current_plan_slug ?: 'trial'; @endphp
                <span @class([
                    'inline-flex flex-shrink-0 items-center rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide',
                    'border border-slate-200 bg-slate-50 text-slate-500' => $planSlug === 'trial',
                    'border border-emerald-200 bg-emerald-50 text-emerald-700' => $planSlug !== 'trial',
                ])
                      title="Current plan ({{ $planSlug === 'trial' ? 'trial / no comp' : 'comped or paid' }})">
                    {{ $planSlug }}
                </span>
                {{-- Trial countdown: only meaningful for trial-tier non-admins
                     without an active subscription (mirrors TrialStatus rules). --}}
                @php
                    $trialDaysTotal = \App\Support\TrialStatus::trialDays();
                    $showTrialClock = $trialDaysTotal > 0
                        && ! $client->is_admin
                        && $planSlug === 'trial'
                        && (int) $client->active_subs_count === 0;
                    $trialDaysLeft = $showTrialClock
                        ? (int) ceil(now()->diffInDays($client->created_at->copy()->addDays($trialDaysTotal), false))
                        : 0;
                @endphp
                @if ($showTrialClock)
                    @if ($trialDaysLeft > 0)
                        <span @class([
                            'inline-flex flex-shrink-0 items-center rounded px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide border',
                            'border-amber-300 bg-amber-50 text-amber-700' => $trialDaysLeft <= 3,
                            'border-sky-200 bg-sky-50 text-sky-700' => $trialDaysLeft > 3,
                        ]) title="Trial ends {{ $client->created_at->copy()->addDays($trialDaysTotal)->toFormattedDateString() }}">
                            {{ $trialDaysLeft }}d left
                        </span>
                    @else
                        <span class="inline-flex flex-shrink-0 items-center rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-rose-700"
                              title="Trial ended {{ $client->created_at->copy()->addDays($trialDaysTotal)->toFormattedDateString() }}{{ $client->trial_data_deleted_at ? ' — data deleted' : ' — in deletion countdown' }}">
                            {{ $client->trial_data_deleted_at ? 'expired · data deleted' : 'expired' }}
                        </span>
                    @endif
                @endif
            </div>
        </div>
    </div>
