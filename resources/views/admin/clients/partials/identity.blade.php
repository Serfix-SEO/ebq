{{-- Client identity cell: avatar, name, id, email, plan badge, trial clock and
     the client's website domains. Shared by the desktop table row and the
     mobile card.

     Domains come from the listing's eager-loaded `websites` relation (already
     loaded for the recrawl picker) — never a query per row.

     Expects: $client, $initialsFor, $avatarBg --}}
    <div class="flex items-center gap-2.5">
        <span @class(['flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-full text-[11px] font-bold', $avatarBg($client->id)])>
            {{ $initialsFor((string) $client->name, (string) $client->email) }}
        </span>
        <div class="min-w-0">
            <div class="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
                <a href="{{ route('admin.clients.show', $client) }}" class="truncate hover:text-orange-700 hover:underline">{{ $client->name }}</a>
                {{-- Short ULID tail: the full 26-char id ate ~130px of the row and
                     pushed the Actions column out of view. Full id in the tooltip
                     and on the client detail page. --}}
                <span class="font-mono text-[10px] font-normal text-slate-400" title="{{ $client->id }}">#{{ \Illuminate\Support\Str::substr($client->id, -6) }}</span>
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
            @if ($client->relationLoaded('websites') && $client->websites->isNotEmpty())
                {{-- Admins recognise accounts by domain, not by email. Two shown
                     inline, the rest folded into a +N with the full list on hover. --}}
                <div class="mt-1 flex flex-wrap items-center gap-1">
                    @foreach ($client->websites->take(2) as $site)
                        <a href="https://{{ $site->domain }}" target="_blank" rel="noopener noreferrer"
                           class="inline-flex max-w-[12rem] items-center gap-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-600 hover:bg-orange-50 hover:text-orange-700">
                            <svg class="h-2.5 w-2.5 flex-none opacity-60" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.75 3.75-9S14.5 5.5 12 3m0 18c-2.5-2.5-3.75-5.75-3.75-9S9.5 5.5 12 3M3.6 9h16.8M3.6 15h16.8"/></svg>
                            <span class="truncate">{{ $site->domain }}</span>
                        </a>
                    @endforeach
                    @if ($client->websites->count() > 2)
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500"
                              title="{{ $client->websites->pluck('domain')->join(', ') }}">+{{ $client->websites->count() - 2 }}</span>
                    @endif
                </div>
            @endif
        </div>
    </div>
