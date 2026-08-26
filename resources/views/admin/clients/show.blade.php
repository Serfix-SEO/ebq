<x-layouts.app>
    @php
        /**
         * Admin client detail — one screen with everything we know about an
         * account. Every number here comes pre-aggregated from
         * App\Services\Admin\ClientProfileService; the view does no queries of
         * its own beyond cheap relation reads already eager-loaded there.
         *
         * @var \App\Models\User $client
         * @var array $profile
         */
        $fmtN = fn ($n) => number_format((int) $n);
        $fmtMoney = fn (float $usd) => '$' . number_format($usd, $usd >= 100 ? 0 : ($usd >= 1 ? 2 : 4));
        $rel = function ($when): string {
            if (! $when) return '—';
            try { return \Illuminate\Support\Carbon::parse($when)->diffForHumans(); }
            catch (\Throwable) { return '—'; }
        };
        $date = function ($when): string {
            if (! $when) return '—';
            try { return format_user_datetime($when, 'M j, Y g:i A'); }
            catch (\Throwable) { return '—'; }
        };
        $initials = function (string $name, string $email): string {
            $n = trim($name);
            if ($n !== '') {
                $parts = preg_split('/\s+/', $n) ?: [];
                return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
            }
            return mb_strtoupper(mb_substr($email, 0, 2));
        };

        $planSlug = $client->current_plan_slug ?: 'trial';
        $trialDaysTotal = \App\Support\TrialStatus::trialDays();
        $showTrialClock = $trialDaysTotal > 0 && ! $client->is_admin && $planSlug === 'trial'
            && $profile['billing']['subscriptions']->where('stripe_status', 'active')->isEmpty();
        $trialDaysLeft = $showTrialClock
            ? (int) ceil(now()->diffInDays($client->created_at->copy()->addDays($trialDaysTotal), false))
            : 0;

        $spendMtd = collect($profile['spend']['mtd'])->sum('usd');
        $spendLife = collect($profile['spend']['lifetime'])->sum('usd');
        $clicks28 = collect($profile['websites'])->sum(fn ($w) => (int) ($w['gsc']['clicks'] ?? 0));
        $impr28 = collect($profile['websites'])->sum(fn ($w) => (int) ($w['gsc']['impressions'] ?? 0));
        $publishedTotal = (int) ($profile['content']['by_status'][\App\Models\ContentTopic::STATUS_PUBLISHED] ?? 0);

        // Section shell: same card chrome everywhere, so the page reads as one
        // system rather than a pile of one-off boxes.
        // `min-w-0` is load-bearing: these cards are grid items, and a grid
        // item's default `min-width: auto` refuses to shrink below its
        // content's min-content width — which pushed the cards ~55px past the
        // viewport on a phone (the layout's `overflow-x-clip` then silently
        // CLIPPED the overflow instead of scrolling, so values just vanished).
        $card = 'min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm';
    @endphp

    <div class="space-y-4 pb-10">
        {{-- Breadcrumb --}}
        <a href="{{ route('admin.clients.index') }}" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-slate-800">
            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
            All clients
        </a>

        @if (session('status'))
            <div class="flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">
                <svg class="mt-px h-4 w-4 flex-none" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ session('status') }}
            </div>
        @endif

        {{-- ── Hero ─────────────────────────────────────────────────────── --}}
        <div class="{{ $card }} overflow-hidden">
            <div class="flex flex-col gap-4 p-4 sm:flex-row sm:items-start sm:justify-between sm:p-5">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-12 w-12 flex-none items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-sm font-bold text-white shadow-lg shadow-orange-600/25 sm:h-14 sm:w-14 sm:text-base">
                        {{ $initials((string) $client->name, (string) $client->email) }}
                    </span>
                    <div class="min-w-0">
                        <h1 class="truncate text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">{{ $client->name }}</h1>
                        <a href="mailto:{{ $client->email }}" class="block truncate text-sm text-slate-500 hover:text-orange-600 hover:underline">{{ $client->email }}</a>
                        <div class="mt-2 flex flex-wrap items-center gap-1.5">
                            @if ($client->is_admin)
                                <span class="inline-flex items-center rounded border border-orange-200 bg-orange-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-orange-700">Admin</span>
                            @endif
                            @if ($client->is_disabled)
                                <span class="inline-flex items-center rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-700">Disabled</span>
                            @else
                                <span class="inline-flex items-center rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-emerald-700">Active</span>
                            @endif
                            <span @class([
                                'inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                                'border border-slate-200 bg-slate-50 text-slate-500' => $planSlug === 'trial',
                                'border border-emerald-200 bg-emerald-50 text-emerald-700' => $planSlug !== 'trial',
                            ])>{{ $planSlug }}</span>
                            @if ($showTrialClock)
                                <span @class([
                                    'inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                                    'border-amber-300 bg-amber-50 text-amber-700' => $trialDaysLeft > 0 && $trialDaysLeft <= 3,
                                    'border-sky-200 bg-sky-50 text-sky-700' => $trialDaysLeft > 3,
                                    'border-rose-200 bg-rose-50 text-rose-700' => $trialDaysLeft <= 0,
                                ])>{{ $trialDaysLeft > 0 ? $trialDaysLeft.'d trial left' : 'trial expired' }}</span>
                            @endif
                            @if ($profile['billing']['content_access'])
                                <span class="inline-flex items-center rounded border border-orange-200 bg-orange-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-orange-700">Content AI</span>
                            @endif
                            @if (! $client->email_verified_at)
                                <span class="inline-flex items-center rounded border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-amber-700">Email unverified</span>
                            @endif
                            @if ($client->marketing_emails_opted_out_at)
                                <span class="inline-flex items-center rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-slate-500">Unsubscribed</span>
                            @endif
                        </div>
                        <p class="mt-2 font-mono text-[10px] text-slate-400">{{ $client->id }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2 sm:justify-end">
                    @if (! $client->is_disabled)
                        <form method="POST" action="{{ route('admin.clients.impersonate', $client) }}" class="flex-1 sm:flex-none">
                            @csrf
                            <button type="submit" onclick="return confirm('Sign in as {{ $client->email }}?')"
                                    class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.5 20.25a7.5 7.5 0 1115 0v.75h-15v-.75z"/></svg>
                                Impersonate
                            </button>
                        </form>
                    @endif
                    {{-- Start a support thread with this client, pre-selected. --}}
                    <a href="{{ route('admin.support.create', ['user' => $client->id]) }}"
                       class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 sm:flex-none">
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm3.75 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm3.75 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/></svg>
                        Message
                    </a>
                    <a href="{{ route('admin.usage.index', ['user_id' => $client->id]) }}"
                       class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 sm:flex-none">
                        API usage
                    </a>
                    <a href="#admin-controls"
                       class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-orange-300 bg-orange-50 px-3 py-2 text-xs font-semibold text-orange-700 hover:bg-orange-100 sm:flex-none">
                        Edit account
                    </a>
                </div>
            </div>

            {{-- KPI strip --}}
            <div class="grid grid-cols-2 divide-x divide-y divide-slate-100 border-t border-slate-100 sm:grid-cols-3 xl:grid-cols-6 xl:divide-y-0">
                @foreach ([
                    ['label' => 'Websites', 'value' => $fmtN($profile['totals']['websites']), 'sub' => $profile['totals']['covered'].' on Content AI'],
                    ['label' => 'Articles published', 'value' => $fmtN($publishedTotal), 'sub' => $fmtN($profile['content']['published_30d']).' in 30 days'],
                    ['label' => 'Tracked keywords', 'value' => $fmtN($profile['keywords']['total']), 'sub' => $fmtN($profile['keywords']['buckets']['Top 3'] ?? 0).' in top 3'],
                    ['label' => 'Clicks · '.$profile['perf_days'].'d', 'value' => $fmtN($clicks28), 'sub' => $fmtN($impr28).' impressions'],
                    ['label' => 'API spend MTD', 'value' => $fmtMoney((float) $spendMtd), 'sub' => 'this calendar month'],
                    ['label' => 'API spend lifetime', 'value' => $fmtMoney((float) $spendLife), 'sub' => 'since signup'],
                ] as $kpi)
                    <div class="px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $kpi['label'] }}</p>
                        <p class="mt-0.5 text-lg font-bold tabular-nums text-slate-900">{{ $kpi['value'] }}</p>
                        <p class="text-[11px] text-slate-400">{{ $kpi['sub'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-3">
            {{-- ── Account details ──────────────────────────────────────── --}}
            <div class="{{ $card }} p-4">
                <h2 class="text-sm font-bold text-slate-900">Account</h2>
                <dl class="mt-3 space-y-2 text-xs">
                    @foreach ([
                        ['Joined', $date($client->created_at).' · '.$rel($client->created_at)],
                        ['Email verified', $client->email_verified_at ? $date($client->email_verified_at) : 'Not verified'],
                        ['Phone', $client->phone ?: '—'],
                        ['Timezone', $client->timezone ?: '—'],
                        ['Language', $client->locale ?: 'en'],
                        {{-- Impersonated admin actions are silent here (feed labels them). --}}
                        ['Last activity', $rel($profile['last_client_activity_at'] ? \Illuminate\Support\Carbon::parse($profile['last_client_activity_at']) : null)],
                        ['Marketing emails', $client->marketing_emails_opted_out_at ? 'Opted out '.$rel($client->marketing_emails_opted_out_at) : 'Subscribed'],
                    ] as [$k, $v])
                        <div class="flex items-start justify-between gap-3 border-b border-slate-50 pb-2 last:border-0 last:pb-0">
                            <dt class="flex-none text-slate-500">{{ $k }}</dt>
                            <dd class="min-w-0 flex-1 break-words text-end font-medium text-slate-800">{{ $v }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            {{-- ── Billing & entitlements ───────────────────────────────── --}}
            <div class="{{ $card }} p-4 xl:col-span-2">
                <h2 class="text-sm font-bold text-slate-900">Billing &amp; entitlements</h2>
                <div class="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        ['Plan', $planSlug, $planSlug !== 'trial'],
                        ['Card', $profile['billing']['card'] ?? 'None', (bool) $profile['billing']['has_card']],
                        ['Content AI', $profile['billing']['content_access'] ? 'Yes' : 'No', (bool) $profile['billing']['content_access']],
                        ['Sites allowed', $fmtN($profile['totals']['sites_allowed']), $profile['totals']['sites_allowed'] > 0],
                    ] as [$k, $v, $good])
                        <div @class([
                            'rounded-lg border px-3 py-2',
                            'border-emerald-200 bg-emerald-50/50' => $good,
                            'border-slate-200 bg-slate-50/60' => ! $good,
                        ])>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $k }}</p>
                            <p class="mt-0.5 truncate text-sm font-bold text-slate-800">{{ $v }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 grid gap-2 text-xs sm:grid-cols-3">
                    <div class="rounded-lg border border-slate-200 px-3 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Content trial</p>
                        <p class="mt-0.5 font-semibold text-slate-800">
                            {{ $profile['billing']['content_trial'] ? 'Running' : ($client->content_trial_ends_at ? 'Ended' : 'Never started') }}
                            @if ($client->content_trial_ends_at)
                                <span class="font-normal text-slate-400">· {{ $rel($client->content_trial_ends_at) }}</span>
                            @endif
                        </p>
                    </div>
                    <div class="rounded-lg border border-slate-200 px-3 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Comped content sites</p>
                        <p class="mt-0.5 font-semibold text-slate-800">
                            {{ $fmtN($profile['billing']['comp_sites']) }}
                            <span class="font-normal text-slate-400">
                                · {{ $profile['billing']['comp_sites'] > 0 ? ($profile['billing']['comp_until'] ? 'until '.$profile['billing']['comp_until']->toFormattedDateString() : 'permanent') : 'none' }}
                            </span>
                        </p>
                    </div>
                    <div class="rounded-lg border border-slate-200 px-3 py-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Extra site add-ons</p>
                        <p class="mt-0.5 font-semibold text-slate-800">{{ $fmtN($profile['billing']['addon_quantity']) }}</p>
                    </div>
                </div>

                @if ($profile['billing']['subscriptions']->isNotEmpty())
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="text-[10px] uppercase tracking-wider text-slate-400">
                                <tr class="border-b border-slate-100 text-start">
                                    <th class="py-1.5 pe-3 text-start font-semibold">Subscription</th>
                                    <th class="py-1.5 pe-3 text-start font-semibold">Status</th>
                                    <th class="py-1.5 pe-3 text-start font-semibold">Qty</th>
                                    <th class="hidden py-1.5 pe-3 text-start font-semibold sm:table-cell">Started</th>
                                    <th class="py-1.5 text-start font-semibold">Ends</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($profile['billing']['subscriptions'] as $sub)
                                    <tr class="border-b border-slate-50 last:border-0">
                                        <td class="py-1.5 pe-3">
                                            <span class="font-semibold text-slate-800">{{ $sub->type }}</span>
                                            <span class="block font-mono text-[10px] text-slate-400">{{ $sub->stripe_price }}</span>
                                        </td>
                                        <td class="py-1.5 pe-3">
                                            <span @class([
                                                'inline-flex rounded px-1.5 py-0.5 text-[10px] font-bold uppercase',
                                                'bg-emerald-50 text-emerald-700' => $sub->stripe_status === 'active',
                                                'bg-amber-50 text-amber-700' => in_array($sub->stripe_status, ['trialing', 'past_due'], true),
                                                'bg-slate-100 text-slate-500' => ! in_array($sub->stripe_status, ['active', 'trialing', 'past_due'], true),
                                            ])>{{ $sub->stripe_status }}</span>
                                        </td>
                                        <td class="py-1.5 pe-3 tabular-nums text-slate-700">{{ $fmtN($sub->quantity ?? 1) }}</td>
                                        <td class="hidden py-1.5 pe-3 text-slate-500 sm:table-cell">{{ $date($sub->created_at) }}</td>
                                        <td class="py-1.5 text-slate-500">{{ $sub->ends_at ? $date($sub->ends_at) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="mt-3 rounded-lg border border-dashed border-slate-200 px-3 py-4 text-center text-xs text-slate-400">
                        No Stripe subscriptions — trial or comped access only.
                    </p>
                @endif
            </div>
        </div>

        {{-- ── Websites ─────────────────────────────────────────────────── --}}
        <div class="{{ $card }} p-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-bold text-slate-900">Websites</h2>
                <span class="text-[11px] text-slate-400">Performance over the last {{ $profile['perf_days'] }} days</span>
            </div>

            @forelse ($profile['websites'] as $w)
                @php $plan = $w['plan']; @endphp
                <div class="mt-3 rounded-lg border border-slate-200">
                    <div class="flex flex-col gap-2 border-b border-slate-100 p-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <a href="https://{{ $w['domain'] }}" target="_blank" rel="noopener noreferrer"
                                   class="truncate text-sm font-bold text-slate-900 hover:text-orange-600 hover:underline">{{ $w['domain'] }}</a>
                                @if ($w['covered'])
                                    <span class="inline-flex items-center rounded border border-orange-200 bg-orange-50 px-1.5 py-0.5 text-[10px] font-bold uppercase text-orange-700">Content AI</span>
                                @endif
                                @if ($w['gsc_connected'])
                                    <span class="inline-flex items-center rounded border border-sky-200 bg-sky-50 px-1.5 py-0.5 text-[10px] font-bold uppercase text-sky-700">GSC</span>
                                @endif
                                @if ($w['ga_connected'])
                                    <span class="inline-flex items-center rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-bold uppercase text-emerald-700">GA4</span>
                                @endif
                            </div>
                            <p class="mt-0.5 text-[11px] text-slate-400">Added {{ $date($w['created_at']) }}</p>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @if ($w['integrations']->isEmpty())
                                <span class="inline-flex items-center rounded border border-dashed border-slate-300 px-2 py-1 text-[10px] font-semibold text-slate-400">No publishing integration</span>
                            @else
                                <span class="inline-flex items-center rounded border border-slate-200 bg-slate-50 px-2 py-1 text-[10px] font-semibold text-slate-500">
                                    {{ $w['integrations']->count() }} {{ \Illuminate\Support\Str::plural('integration', $w['integrations']->count()) }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <dl class="grid grid-cols-2 gap-x-3 gap-y-2 p-3 text-xs sm:grid-cols-4 lg:grid-cols-7">
                        @php
                            $topics = $w['topics'];
                            $cells = [
                                ['Autopilot', $plan ? $plan->status : 'not set up'],
                                ['Cadence', $plan ? $plan->articles_per_week.'/week' : '—'],
                                ['Published', $fmtN($topics[\App\Models\ContentTopic::STATUS_PUBLISHED] ?? 0)],
                                ['In pipeline', $fmtN(collect($topics)->only(\App\Models\ContentTopic::IN_FLIGHT)->sum() + ($topics[\App\Models\ContentTopic::STATUS_SCHEDULED] ?? 0))],
                                ['Failed', $fmtN($topics[\App\Models\ContentTopic::STATUS_FAILED] ?? 0)],
                                ['Tracked keywords', $fmtN($w['tracked'])],
                                ['Last published', $rel($w['last_published_at'])],
                            ];
                        @endphp
                        @foreach ($cells as [$k, $v])
                            <div>
                                <dt class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $k }}</dt>
                                <dd class="font-semibold text-slate-800">{{ $v }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="grid grid-cols-2 gap-x-3 gap-y-2 border-t border-slate-100 bg-slate-50/60 p-3 text-xs sm:grid-cols-4">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Clicks</p>
                            <p class="font-bold tabular-nums text-slate-800">{{ $w['gsc'] ? $fmtN($w['gsc']['clicks']) : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Impressions</p>
                            <p class="font-bold tabular-nums text-slate-800">{{ $w['gsc'] ? $fmtN($w['gsc']['impressions']) : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Article pageviews</p>
                            <p class="font-bold tabular-nums text-slate-800">{{ $w['ga'] ? $fmtN($w['ga']['pageviews']) : '—' }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Sessions</p>
                            <p class="font-bold tabular-nums text-slate-800">{{ $w['ga'] ? $fmtN($w['ga']['sessions']) : '—' }}</p>
                        </div>
                    </div>

                    {{-- Publishing integrations. Shows WHERE articles go and whether they
                         landed — never a credential: targetSummary() opts in the addressing
                         fields only (tokens/keys/app passwords/webhook secrets stay in the
                         encrypted cast and are never rendered). --}}
                    @if ($w['integrations']->isNotEmpty())
                        <div class="border-t border-slate-100 p-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Publishing integrations</p>
                            <div class="mt-2 grid gap-2 lg:grid-cols-2">
                                @foreach ($w['integrations'] as $integration)
                                    @php
                                        $stats = $profile['publications'][$integration->id] ?? null;
                                        $delivered = (int) (($stats['confirmed'] ?? 0) + ($stats['sent'] ?? 0));
                                        $failedDeliveries = (int) ($stats['failed'] ?? 0);
                                        $isError = $integration->status === \App\Models\ContentIntegration::STATUS_ERROR;
                                        $isConnected = $integration->status === \App\Models\ContentIntegration::STATUS_CONNECTED;
                                    @endphp
                                    <div @class([
                                        'min-w-0 rounded-lg border px-3 py-2.5',
                                        'border-emerald-200 bg-emerald-50/40' => $isConnected,
                                        'border-rose-200 bg-rose-50/40' => $isError,
                                        'border-slate-200 bg-slate-50/60' => ! $isConnected && ! $isError,
                                    ])>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-sm font-bold text-slate-800">{{ $integration->platformLabel() }}</span>
                                            <span @class([
                                                'inline-flex rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide',
                                                'bg-emerald-100 text-emerald-700' => $isConnected,
                                                'bg-rose-100 text-rose-700' => $isError,
                                                'bg-slate-200 text-slate-600' => ! $isConnected && ! $isError,
                                            ])>{{ $integration->status }}</span>
                                            <span class="ms-auto text-[11px] text-slate-400">
                                                {{ $integration->last_verified_at ? 'Verified '.$rel($integration->last_verified_at) : 'Never verified' }}
                                            </span>
                                        </div>

                                        @php $target = $integration->targetSummary(); @endphp
                                        @if ($target !== [])
                                            <dl class="mt-2 space-y-1 text-xs">
                                                @foreach ($target as $k => $v)
                                                    <div class="flex items-start justify-between gap-3">
                                                        <dt class="flex-none text-slate-500">{{ $k }}</dt>
                                                        <dd class="min-w-0 flex-1 break-all text-end font-medium text-slate-800">{{ $v }}</dd>
                                                    </div>
                                                @endforeach
                                            </dl>
                                        @endif

                                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px]">
                                            <span class="font-semibold text-slate-700">{{ $fmtN($delivered) }} delivered</span>
                                            @if ($failedDeliveries > 0)
                                                <span class="font-semibold text-rose-600">{{ $fmtN($failedDeliveries) }} failed</span>
                                            @endif
                                            @if (($stats['queued'] ?? 0) > 0)
                                                <span class="text-amber-600">{{ $fmtN($stats['queued']) }} queued</span>
                                            @endif
                                            <span class="text-slate-400">Last delivery {{ $rel($stats['last_at'] ?? null) }}</span>
                                        </div>

                                        @if ($integration->last_error)
                                            <p class="mt-2 break-words rounded bg-rose-50 px-2 py-1.5 text-[11px] text-rose-700">
                                                {{ \Illuminate\Support\Str::limit($integration->last_error, 300) }}
                                            </p>
                                        @endif

                                        <p class="mt-2 text-[10px] text-slate-400">Connected {{ $date($integration->created_at) }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <p class="mt-3 rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-xs text-slate-400">
                    This client has no websites yet.
                </p>
            @endforelse
        </div>

        {{-- ── Content directives (admin steering prompt) ───────────────── --}}
        @php
            $dirSites = collect($profile['websites'])->filter(fn ($w) => $w['plan'] !== null)->values();
            // Keep the picked website selected across the save/clear reload
            // (?directives_site=<id> is appended by the redirect).
            $dirSelected = $dirSites->firstWhere('id', (string) request('directives_site')) ?? $dirSites->first();
        @endphp
        @if ($dirSites->isNotEmpty())
            <div id="content-directives" class="{{ $card }} p-4">
                <h2 class="text-sm font-bold text-slate-900">Content directives</h2>
                <p class="mt-0.5 text-xs text-slate-500">
                    Steering prompt appended to <span class="font-semibold">every</span> content-generation AI call for the selected website
                    (topic planning, writing, revisions, cleanups, inline edits, image prompts). Additive — it never replaces the pipeline's
                    own rules. Leave empty for stock behavior.
                </p>
                <form method="POST" class="mt-3 space-y-2" id="content-directives-form"
                      action="{{ route('admin.clients.content-prompt', [$client, $dirSelected['id']]) }}">
                    @csrf
                    @method('PUT')
                    {{-- No px-* here: @tailwindcss/forms reserves the right padding
                         for its chevron — px-3 made the arrow overlap the domain. --}}
                    <select id="content-directives-site"
                            class="w-full rounded-lg border-slate-300 text-sm focus:border-orange-500 focus:ring-orange-500 sm:w-auto">
                        @foreach ($dirSites as $w)
                            <option value="{{ route('admin.clients.content-prompt', [$client, $w['id']]) }}"
                                    data-prompt="{{ $w['plan']->admin_content_prompt }}"
                                    @selected($w['id'] === $dirSelected['id'])>
                                {{ $w['domain'] }}{{ trim((string) $w['plan']->admin_content_prompt) !== '' ? ' — prompt set' : '' }}
                            </option>
                        @endforeach
                    </select>
                    <textarea name="admin_content_prompt" id="content-directives-text" rows="4" maxlength="2000"
                              placeholder="e.g. Never mention pricing. Always write from a sustainability angle. Avoid comparisons with US brands."
                              class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ $dirSelected['plan']->admin_content_prompt }}</textarea>
                    <p class="text-[11px] leading-4 text-slate-400">
                        "Save and clear future articles" also deletes the website's unwritten planned topics and re-runs the
                        planner under the new directives — written, scheduled and published articles are never touched, and the
                        client's own confirmed keywords are re-added automatically.
                    </p>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-[11px] text-slate-400"><span id="content-directives-count">0</span>/2000</span>
                        <div class="flex items-center gap-2">
                            <button type="submit" class="rounded-lg bg-orange-600 px-3 py-1.5 text-xs font-bold text-white hover:brightness-110">
                                Save
                            </button>
                            <button type="submit" name="clear_future" value="1"
                                    onclick="return confirm('Save the directives AND delete all unwritten planned topics for this website, then re-plan?')"
                                    class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-600 hover:bg-rose-50">
                                Save and clear future articles
                            </button>
                        </div>
                    </div>
                </form>
                <script>
                    (function () {
                        const sel = document.getElementById('content-directives-site');
                        const form = document.getElementById('content-directives-form');
                        const text = document.getElementById('content-directives-text');
                        const count = document.getElementById('content-directives-count');
                        const sync = () => { count.textContent = String(text.value.length); };
                        sel.addEventListener('change', () => {
                            form.action = sel.value;
                            text.value = sel.options[sel.selectedIndex].dataset.prompt || '';
                            sync();
                        });
                        text.addEventListener('input', sync);
                        sync();
                    })();
                </script>
            </div>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- ── Content production ───────────────────────────────────── --}}
            <div class="{{ $card }} p-4">
                <h2 class="text-sm font-bold text-slate-900">Content production</h2>
                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ([
                        ['Topics', $fmtN($profile['content']['total'])],
                        ['Articles', $fmtN($profile['content']['articles'] ?? 0)],
                        ['Avg SEO score', $profile['content']['avg_score'] !== null ? $profile['content']['avg_score'].'/100' : '—'],
                        ['Words written', $fmtN($profile['content']['words'])],
                    ] as [$k, $v])
                        <div class="rounded-lg border border-slate-200 px-3 py-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $k }}</p>
                            <p class="mt-0.5 text-base font-bold tabular-nums text-slate-900">{{ $v }}</p>
                        </div>
                    @endforeach
                </div>

                @if (! empty($profile['content']['by_status']))
                    <p class="mt-4 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Topics by status</p>
                    <div class="mt-1.5 space-y-1.5">
                        @php $maxStatus = max($profile['content']['by_status']); @endphp
                        @foreach ($profile['content']['by_status'] as $status => $count)
                            <div class="flex items-center gap-2 text-xs">
                                <span class="w-24 flex-none truncate text-slate-500">{{ $status }}</span>
                                <span class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                    <span @class([
                                        'block h-full rounded-full',
                                        'bg-emerald-500' => $status === \App\Models\ContentTopic::STATUS_PUBLISHED,
                                        'bg-rose-500' => $status === \App\Models\ContentTopic::STATUS_FAILED,
                                        'bg-orange-400' => ! in_array($status, [\App\Models\ContentTopic::STATUS_PUBLISHED, \App\Models\ContentTopic::STATUS_FAILED], true),
                                    ]) style="width: {{ $maxStatus > 0 ? max(4, round($count / $maxStatus * 100)) : 0 }}%"></span>
                                </span>
                                <span class="w-10 flex-none text-end font-semibold tabular-nums text-slate-700">{{ $fmtN($count) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if (! empty($profile['content']['feedback']))
                    <p class="mt-4 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Client feedback on articles</p>
                    <div class="mt-1.5 flex flex-wrap gap-2 text-xs">
                        @foreach ($profile['content']['feedback'] as $rating => $count)
                            <span @class([
                                'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1 font-semibold',
                                'border-emerald-200 bg-emerald-50 text-emerald-700' => $rating === \App\Models\ContentArticleFeedback::RATING_LOVE,
                                'border-amber-200 bg-amber-50 text-amber-700' => $rating === \App\Models\ContentArticleFeedback::RATING_REWRITES,
                                'border-rose-200 bg-rose-50 text-rose-700' => $rating === \App\Models\ContentArticleFeedback::RATING_WRONG,
                            ])>{{ $rating }} <span class="tabular-nums">{{ $fmtN($count) }}</span></span>
                        @endforeach
                    </div>
                    @foreach ($profile['content']['feedback_recent'] ?? [] as $fb)
                        <p class="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-[11px] italic text-slate-600">
                            “{{ \Illuminate\Support\Str::limit($fb->comment, 220) }}”
                            <span class="not-italic text-slate-400">— {{ $rel($fb->created_at) }}</span>
                        </p>
                    @endforeach
                @endif
            </div>

            {{-- ── Keyword rankings ─────────────────────────────────────── --}}
            <div class="{{ $card }} p-4">
                <h2 class="text-sm font-bold text-slate-900">Keyword rankings</h2>
                <p class="text-[11px] text-slate-400">{{ $fmtN($profile['keywords']['total']) }} keywords tracked across all websites</p>

                <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-5">
                    @foreach ($profile['keywords']['buckets'] as $label => $count)
                        <div @class([
                            'rounded-lg border px-2.5 py-2 text-center',
                            'border-emerald-200 bg-emerald-50/60' => $label === 'Top 3',
                            'border-sky-200 bg-sky-50/60' => $label === '4–10',
                            'border-slate-200' => ! in_array($label, ['Top 3', '4–10'], true),
                        ])>
                            <p class="text-base font-bold tabular-nums text-slate-900">{{ $fmtN($count) }}</p>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $label }}</p>
                        </div>
                    @endforeach
                </div>

                @if ($profile['keywords']['best']->isNotEmpty())
                    <div class="mt-3 overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="text-[10px] uppercase tracking-wider text-slate-400">
                                <tr class="border-b border-slate-100">
                                    <th class="py-1.5 pe-3 text-start font-semibold">Best-ranking keywords</th>
                                    <th class="py-1.5 pe-3 text-end font-semibold">Position</th>
                                    <th class="py-1.5 text-end font-semibold">Checked</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($profile['keywords']['best'] as $kw)
                                    <tr class="border-b border-slate-50 last:border-0">
                                        <td class="max-w-[220px] truncate py-1.5 pe-3 text-slate-700">{{ $kw->keyword }}</td>
                                        <td class="py-1.5 pe-3 text-end">
                                            <span @class([
                                                'inline-flex min-w-[2rem] justify-center rounded px-1.5 py-0.5 text-[11px] font-bold tabular-nums',
                                                'bg-emerald-50 text-emerald-700' => $kw->serp_position <= 3,
                                                'bg-sky-50 text-sky-700' => $kw->serp_position > 3 && $kw->serp_position <= 10,
                                                'bg-slate-100 text-slate-600' => $kw->serp_position > 10,
                                            ])>{{ $kw->serp_position }}</span>
                                        </td>
                                        <td class="py-1.5 text-end text-slate-400">{{ $rel($kw->serp_checked_at) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="mt-3 rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-xs text-slate-400">
                        No SERP positions recorded yet.
                    </p>
                @endif
            </div>
        </div>

        {{-- ── API usage & spend ───────────────────────────────────────── --}}
        <div class="{{ $card }} p-4">
            <h2 class="text-sm font-bold text-slate-900">API usage &amp; spend</h2>
            <p class="text-[11px] text-slate-400">Billed third-party calls made on this account. Rates from config/services.php.</p>

            <div class="mt-3 grid gap-4 lg:grid-cols-3">
                <div class="min-w-0 overflow-x-auto lg:col-span-2">
                    <table class="min-w-full text-xs">
                        <thead class="text-[10px] uppercase tracking-wider text-slate-400">
                            <tr class="border-b border-slate-100">
                                <th class="py-1.5 pe-3 text-start font-semibold">Provider</th>
                                <th class="hidden py-1.5 pe-3 text-end font-semibold sm:table-cell">Units MTD</th>
                                <th class="py-1.5 pe-3 text-end font-semibold">Cost MTD</th>
                                <th class="hidden py-1.5 pe-3 text-end font-semibold sm:table-cell">Units lifetime</th>
                                <th class="py-1.5 text-end font-semibold">Cost lifetime</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $lifeByProvider = collect($profile['spend']['lifetime'])->keyBy('provider'); @endphp
                            @forelse ($profile['spend']['lifetime'] as $row)
                                @php $mtd = collect($profile['spend']['mtd'])->firstWhere('provider', $row['provider']); @endphp
                                <tr class="border-b border-slate-50 last:border-0">
                                    <td class="py-1.5 pe-3 font-semibold text-slate-700">{{ $row['provider'] ?: 'unknown' }}</td>
                                    <td class="hidden py-1.5 pe-3 text-end tabular-nums text-slate-600 sm:table-cell">{{ $fmtN($mtd['units'] ?? 0) }}</td>
                                    <td class="py-1.5 pe-3 text-end tabular-nums font-semibold text-slate-800">{{ $fmtMoney((float) ($mtd['usd'] ?? 0)) }}</td>
                                    <td class="hidden py-1.5 pe-3 text-end tabular-nums text-slate-600 sm:table-cell">{{ $fmtN($row['units']) }}</td>
                                    <td class="py-1.5 text-end tabular-nums font-semibold text-slate-800">{{ $fmtMoney((float) $row['usd']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-6 text-center text-slate-400">No billed API calls recorded.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div>
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">Last 6 months</p>
                    @php $maxMonth = max(array_merge([0.0], array_values($profile['spend']['months']))); @endphp
                    <div class="mt-2 flex h-28 items-end gap-1.5">
                        @foreach ($profile['spend']['months'] as $ym => $usd)
                            <div class="flex flex-1 flex-col items-center gap-1">
                                <span class="text-[9px] tabular-nums text-slate-400">{{ $usd > 0 ? $fmtMoney((float) $usd) : '' }}</span>
                                <span class="w-full rounded-t bg-orange-500/80"
                                      style="height: {{ $maxMonth > 0 ? max(2, round($usd / $maxMonth * 72)) : 2 }}px"></span>
                                <span class="text-[9px] text-slate-400">{{ \Illuminate\Support\Carbon::parse($ym.'-01')->format('M') }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- ── Support ──────────────────────────────────────────────── --}}
            <div class="{{ $card }} p-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-bold text-slate-900">Support tickets</h2>
                    <span class="text-[11px] text-slate-400">{{ $fmtN($profile['support']['open']) }} open · {{ $fmtN($profile['support']['total']) }} total</span>
                </div>
                @forelse ($profile['support']['tickets'] as $ticket)
                    <a href="{{ route('admin.support.show', $ticket) }}"
                       class="mt-2 flex items-start justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2 hover:border-orange-300 hover:bg-orange-50/40">
                        <div class="min-w-0">
                            <p class="truncate text-xs font-semibold text-slate-800">{{ $ticket->subject }}</p>
                            <p class="text-[11px] text-slate-400">{{ $fmtN($ticket->messages_count) }} messages · {{ $rel($ticket->last_reply_at ?? $ticket->created_at) }}</p>
                        </div>
                        <span @class([
                            'inline-flex flex-none rounded px-1.5 py-0.5 text-[10px] font-bold uppercase',
                            'bg-rose-50 text-rose-700' => $ticket->status === \App\Models\SupportTicket::STATUS_OPEN,
                            'bg-sky-50 text-sky-700' => $ticket->status === \App\Models\SupportTicket::STATUS_ANSWERED,
                            'bg-slate-100 text-slate-500' => $ticket->status === \App\Models\SupportTicket::STATUS_CLOSED,
                        ])>{{ $ticket->status }}</span>
                    </a>
                @empty
                    <p class="mt-2 rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-xs text-slate-400">No tickets from this client.</p>
                @endforelse
            </div>

            {{-- ── Lifecycle emails ─────────────────────────────────────── --}}
            <div class="{{ $card }} p-4">
                <h2 class="text-sm font-bold text-slate-900">Lifecycle emails</h2>
                @forelse ($profile['lifecycle'] as $mail)
                    <div class="mt-2 flex items-start justify-between gap-3 border-b border-slate-50 pb-2 last:border-0">
                        <div class="min-w-0">
                            <p class="truncate text-xs font-medium text-slate-700">{{ $mail->subject }}</p>
                            <p class="text-[11px] text-slate-400">{{ $mail->segment }} · {{ $mail->stage }} · {{ $rel($mail->created_at) }}</p>
                        </div>
                        <span @class([
                            'inline-flex flex-none rounded px-1.5 py-0.5 text-[10px] font-bold uppercase',
                            'bg-emerald-50 text-emerald-700' => $mail->converted_at,
                            'bg-slate-100 text-slate-500' => ! $mail->converted_at,
                        ])>{{ $mail->converted_at ? 'converted' : $mail->status }}</span>
                    </div>
                @empty
                    <p class="mt-2 rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-xs text-slate-400">No lifecycle emails sent.</p>
                @endforelse
            </div>
        </div>

        {{-- ── Activity feed ───────────────────────────────────────────── --}}
        <div class="{{ $card }} p-4">
            <h2 class="text-sm font-bold text-slate-900">Recent activity</h2>
            @forelse ($profile['activity'] as $act)
                <div class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-slate-50 pb-2 text-xs last:border-0">
                    <span class="font-semibold text-slate-700">{{ $act->type }}</span>
                    @if ($act->provider)
                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500">{{ $act->provider }}</span>
                    @endif
                    @if ($act->units_consumed)
                        <span class="text-[11px] tabular-nums text-slate-400">{{ $fmtN($act->units_consumed) }} units</span>
                    @endif
                    @if ($act->is_impersonated)
                        <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-700">impersonated</span>
                    @endif
                    <span class="ms-auto text-[11px] text-slate-400">{{ $rel($act->created_at) }}</span>
                </div>
            @empty
                <p class="mt-2 rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-xs text-slate-400">No activity recorded.</p>
            @endforelse
        </div>

        {{-- ── Admin controls (same editor as the list) ─────────────────── --}}
        <div id="admin-controls" class="{{ $card }} border-orange-200 p-4">
            <h2 class="text-sm font-bold text-slate-900">Admin controls</h2>
            <p class="mb-3 text-[11px] text-slate-400">Profile, admin/disabled flags, comped plan and comped Content Autopilot slots.</p>
            @include('admin.clients.partials.edit-panel', ['returnTo' => 'show'])
        </div>
    </div>
</x-layouts.app>
