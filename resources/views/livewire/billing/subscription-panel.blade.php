<div class="space-y-5">
    {{-- Header + status pill --}}
    <div class="flex items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Billing</h1>
        @php
            $statusLabel = 'Free';
            $statusTone = 'slate';
            if ($isPastDue) { $statusLabel = 'Past due'; $statusTone = 'red'; }
            elseif ($isCancelled && $endsAt) { $statusLabel = 'Cancels '.$endsAt->toFormattedDateString(); $statusTone = 'amber'; }
            elseif ($isOnTrial && $trialEndsAt) {
                // Carbon 3 returns signed diffs by default; floor the
                // positive direction (now → future) so we never see
                // "29.99 days left" or accidentally negative numbers.
                $daysLeft = max(0, (int) floor(now()->diffInDays($trialEndsAt)));
                $statusLabel = 'Trial — '.$daysLeft.' '.\Illuminate\Support\Str::plural('day', $daysLeft).' left';
                $statusTone = 'orange';
            }
            elseif ($subscription && $subscription->active()) { $statusLabel = 'Active'; $statusTone = 'emerald'; }
            $tones = [
                'emerald' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300',
                'orange'  => 'bg-orange-100 text-orange-800 dark:bg-orange-500/10 dark:text-orange-300',
                'amber'   => 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
                'red'     => 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300',
                'slate'   => 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
            ];
        @endphp
        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $tones[$statusTone] }}">
            {{ $statusLabel }}
        </span>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
            {{ session('status') }}
        </div>
    @endif
    @if (session('error'))
        <div class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
            {{ session('error') }}
        </div>
    @endif

    {{-- Trial-expired winback offer: discount is auto-applied at checkout --}}
    @if ($showWinback)
        <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-orange-600 to-orange-500 px-5 py-5 text-white shadow-lg sm:px-7">
            <div class="pointer-events-none absolute -right-8 -top-10 h-40 w-40 rounded-full bg-white/10"></div>
            <div class="pointer-events-none absolute -bottom-12 right-24 h-32 w-32 rounded-full bg-white/10"></div>
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-orange-100">Limited-time offer</p>
                    <p class="mt-1 text-2xl font-extrabold leading-tight sm:text-3xl">
                        {{ $winbackPercent }}% OFF any plan
                    </p>
                    <p class="mt-1 max-w-xl text-sm text-orange-50">
                        Your trial has ended — subscribe now and we'll take {{ $winbackPercent }}% off your first payment.
                        The discount is <strong>applied automatically at checkout</strong>, no code needed.
                    </p>
                </div>
                <div class="shrink-0 text-center">
                    <span class="inline-block rounded-xl border-2 border-dashed border-white/70 bg-white/15 px-5 py-2.5 text-lg font-extrabold tracking-widest">
                        {{ $winbackCode }}
                    </span>
                    <p class="mt-1.5 text-[11px] font-medium text-orange-100">auto-applied for you</p>
                </div>
            </div>
        </div>
    @endif

    {{-- Frozen-sites banner --}}
    @if ($frozenSites->isNotEmpty())
        <div class="rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
            <p class="font-semibold">{{ $frozenSites->count() }} {{ Str::plural('site', $frozenSites->count()) }} {{ Str::plural('is', $frozenSites->count()) }} frozen.</p>
            <p class="mt-1 text-[13px] leading-5">
                You're over your plan's website limit. Frozen sites stay viewable but can't sync data, run audits, or use AI features. Upgrade to a higher plan to unfreeze them.
            </p>
            <details class="mt-2 text-[12px]">
                <summary class="cursor-pointer text-rose-800 hover:text-rose-950 dark:text-rose-300">Show frozen sites</summary>
                <ul class="mt-1.5 list-disc pl-5">
                    @foreach ($frozenSites as $site)
                        <li>{{ $site->domain ?: '(no domain set)' }}</li>
                    @endforeach
                </ul>
            </details>
        </div>
    @endif

    {{-- Free-for-limited-time promo. When APP_FREE=true on the
         server, every billing surface (the marketing /pricing page
         AND this in-app Subscription page) collapses to a single
         celebratory panel: no plan grid, no upgrade CTA, no cancel,
         because there's nothing to buy and nothing to cancel during
         the promo window. Mirrors resources/views/pricing.blade.php's
         `@if ($free)` branch so customers see consistent messaging
         no matter which surface they land on. --}}
    @if ($isFreePromo)
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 px-6 py-10 text-center dark:border-emerald-500/30 dark:bg-emerald-500/10">
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-emerald-700 dark:text-emerald-300">Limited-time offer</p>
            <h2 class="mt-3 text-balance text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
                You currently have full Pro access at no cost.
            </h2>
            <p class="mx-auto mt-3 max-w-2xl text-sm leading-6 text-slate-600 dark:text-slate-300">
                Every Pro feature is unlocked for your account during this launch period. There's nothing to subscribe to and nothing to cancel right now. We'll notify all active accounts at least 30 days before standard pricing resumes.
            </p>
            <ul class="mx-auto mt-6 grid max-w-xl gap-2 text-left sm:grid-cols-2 text-[13px] text-slate-700 dark:text-slate-200">
                <li class="flex items-start gap-2">
                    <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                    <span>AI Writer, Rank Assist chatbot, and inline AI for the editor</span>
                </li>
                <li class="flex items-start gap-2">
                    <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                    <span>Live SEO scoring with GSC and audit signals</span>
                </li>
                <li class="flex items-start gap-2">
                    <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                    <span>Page audits, topical gap, entity coverage</span>
                </li>
                <li class="flex items-start gap-2">
                    <svg class="mt-0.5 h-4 w-4 flex-none text-emerald-600" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                    <span>Unlimited connected websites during the promo</span>
                </li>
            </ul>
            <p class="mt-7 text-[11px] text-slate-500 dark:text-slate-400">
                Standard plans (Free / Starter / Pro / Agency) will be re-enabled with at least 30 days' notice to existing accounts.
            </p>
        </div>
    @endif

    {{-- Current plan card --}}
    @if (! $isFreePromo && $currentPlan && $subscription)
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-start justify-between gap-3 px-5 py-4">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold text-slate-900 dark:text-white">{{ $currentPlan->name }}</h2>
                        @if ($currentPlan->price_monthly_usd > 0)
                            <span class="text-sm text-slate-500 dark:text-slate-400">·</span>
                            <span class="text-sm text-slate-700 dark:text-slate-300">${{ $currentPlan->price_monthly_usd }}/mo, billed yearly</span>
                        @endif
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                        @if ($isCancelled && $endsAt)
                            Cancels on {{ $endsAt->toFormattedDayDateString() }}.
                        @elseif ($isOnTrial && $trialEndsAt)
                            Trial ends {{ $trialEndsAt->toFormattedDayDateString() }}.
                        @elseif ($nextChargeAt)
                            Next charge {{ $nextChargeAt->toFormattedDayDateString() }}.
                        @endif
                        @if ($user->pm_type && $user->pm_last_four)
                            · {{ ucfirst((string) $user->pm_type) }} ●●●● {{ $user->pm_last_four }}
                        @endif
                    </p>
                </div>
                <div class="flex flex-col items-end gap-1.5">
                    @php
                        $sitesUsedTone = 'emerald';
                        if ($websiteLimit !== null) {
                            $sitesUsedTone = $totalWebsites > $websiteLimit ? 'rose' : ($totalWebsites === $websiteLimit ? 'amber' : 'emerald');
                        }
                        $tonesChip = [
                            'emerald' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300',
                            'amber'   => 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
                            'rose'    => 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300',
                        ];
                    @endphp
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $tonesChip[$sitesUsedTone] }}">
                        {{ $totalWebsites }} of {{ $websiteLimit === null ? 'unlimited' : $websiteLimit }} {{ Str::plural('website', $websiteLimit ?? 2) }}
                    </span>
                    <a href="{{ route('billing.portal') }}" class="text-[11px] text-slate-500 underline hover:text-slate-700 dark:text-slate-400">
                        Manage in Stripe Portal
                    </a>
                </div>
            </div>

            @if ($isCancelled)
                <div class="border-t border-slate-200 px-5 py-3 dark:border-slate-800">
                    <form method="POST" action="{{ route('billing.resume') }}">
                        @csrf
                        <button type="submit" class="inline-flex items-center rounded-md bg-orange-600 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-orange-500">
                            Resume subscription
                        </button>
                        <span class="ml-2 text-[12px] text-slate-500 dark:text-slate-400">Undo the pending cancellation. Stripe keeps billing as normal.</span>
                    </form>
                </div>
            @endif
        </div>
    @endif

    {{-- Plan grid — hidden during the free-promo window since there
         is nothing to switch to or buy. --}}
    @if (! $isFreePromo)
    <div x-data="{ billing: 'yearly' }">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
                @if ($subscription && $subscription->valid())
                    Switch plan
                @else
                    Available plans
                @endif
            </h3>
            {{-- Monthly / yearly toggle --}}
            <div class="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs font-semibold dark:border-slate-700 dark:bg-slate-800">
                <button type="button"
                        @click="billing = 'monthly'"
                        :class="billing === 'monthly' ? 'bg-white shadow text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                        class="rounded-md px-3 py-1 transition-all">Monthly</button>
                <button type="button"
                        @click="billing = 'yearly'"
                        :class="billing === 'yearly' ? 'bg-white shadow text-slate-900 dark:bg-slate-700 dark:text-white' : 'text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200'"
                        class="rounded-md px-3 py-1 transition-all">
                    Yearly
                    <span class="ml-1 rounded bg-emerald-100 px-1 py-0.5 text-[10px] text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">Save ~30%</span>
                </button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($plans->reject(fn ($p) => $p->slug === 'trial') as $plan)
                @php
                    $isCurrent = $currentPlan && $currentPlan->id === $plan->id;
                    $isFree = (int) $plan->price_yearly_usd === 0 && (int) $plan->price_monthly_usd === 0;
                    $isEnterprise = $plan->slug === 'enterprise';
                    $isReady = $plan->isCheckoutReady('annual') || $plan->isCheckoutReady('monthly') || $isFree;
                @endphp
                <div class="relative flex flex-col rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 {{ $plan->is_highlighted ? 'ring-2 ring-orange-500/40' : '' }}">
                    @if ($plan->is_highlighted)
                        <span class="absolute -top-2 right-3 rounded-full bg-orange-600 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">Most popular</span>
                    @endif

                    <div class="flex items-baseline justify-between">
                        <h4 class="text-base font-semibold text-slate-900 dark:text-white">{{ $plan->name }}</h4>
                        @if ($isCurrent)
                            <span class="rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-orange-700 dark:bg-orange-500/10 dark:text-orange-300">Current</span>
                        @endif
                    </div>

                    <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ $plan->tagline }}</p>

                    @if ($isEnterprise)
                        <div class="mt-3">
                            <span class="text-2xl font-bold text-slate-900 dark:text-white">Custom</span>
                        </div>
                        <p class="text-[10px] text-slate-400 dark:text-slate-500">Contact us for pricing</p>
                    @elseif ($isFree)
                        <div class="mt-3 flex items-baseline gap-1">
                            <span class="text-2xl font-bold text-slate-900 dark:text-white">$0</span>
                            <span class="text-xs text-slate-500 dark:text-slate-400">forever</span>
                        </div>
                    @elseif ($showWinback)
                        {{-- Winback pricing: strikethrough + discounted first payment
                             (coupon is duration=once, so only the first invoice). --}}
                        @php
                            $fmtMoney = fn (float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
                            $discYearly = $plan->price_yearly_usd * (100 - $winbackPercent) / 100;
                            $discMonthly = $plan->price_monthly_usd * (100 - $winbackPercent) / 100;
                        @endphp
                        <div x-show="billing === 'yearly'" class="mt-3">
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-sm font-semibold text-slate-400 line-through dark:text-slate-500">${{ round($plan->price_yearly_usd / 12) }}</span>
                                <span class="text-2xl font-bold text-orange-600 dark:text-orange-400">${{ $fmtMoney($discYearly / 12) }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400">/mo</span>
                            </div>
                            <p class="text-[10px] text-slate-400 dark:text-slate-500">
                                <span class="line-through">${{ $plan->price_yearly_usd }}</span>
                                <span class="font-semibold text-orange-600 dark:text-orange-400">${{ $fmtMoney($discYearly) }}</span>
                                first year with {{ $winbackCode }}
                            </p>
                        </div>
                        <div x-show="billing === 'monthly'" style="display:none" class="mt-3">
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-sm font-semibold text-slate-400 line-through dark:text-slate-500">${{ $plan->price_monthly_usd }}</span>
                                <span class="text-2xl font-bold text-orange-600 dark:text-orange-400">${{ $fmtMoney($discMonthly) }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400">/mo</span>
                            </div>
                            <p class="text-[10px] text-slate-400 dark:text-slate-500">
                                first month with {{ $winbackCode }}, then ${{ $plan->price_monthly_usd }}/mo
                            </p>
                        </div>
                    @else
                        {{-- Yearly price (default) --}}
                        <div x-show="billing === 'yearly'" class="mt-3">
                            <div class="flex items-baseline gap-1">
                                <span class="text-2xl font-bold text-slate-900 dark:text-white">${{ round($plan->price_yearly_usd / 12) }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400">/mo</span>
                            </div>
                            <p class="text-[10px] text-slate-400 dark:text-slate-500">${{ $plan->price_yearly_usd }} billed yearly</p>
                        </div>
                        {{-- Monthly price --}}
                        <div x-show="billing === 'monthly'" style="display:none" class="mt-3">
                            <div class="flex items-baseline gap-1">
                                <span class="text-2xl font-bold text-slate-900 dark:text-white">${{ $plan->price_monthly_usd }}</span>
                                <span class="text-xs text-slate-500 dark:text-slate-400">/mo</span>
                            </div>
                            <p class="text-[10px] text-slate-400 dark:text-slate-500">billed monthly</p>
                        </div>
                    @endif

                    <p class="mt-3 text-[11px] font-semibold text-slate-700 dark:text-slate-300">
                        {{ $plan->maxWebsitesLabel() }}
                    </p>

                    @if (is_array($plan->features) && count($plan->features))
                        <ul class="mt-2 space-y-1 text-[12px] text-slate-600 dark:text-slate-300">
                            @foreach ($plan->features as $feature)
                                <li class="flex items-start gap-1.5">
                                    <svg class="mt-0.5 h-3 w-3 flex-none text-emerald-500" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                                    <span>{{ __($feature) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="mt-auto pt-4">
                        @if ($isCurrent)
                            <button type="button" disabled class="w-full cursor-not-allowed rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                Current plan
                            </button>
                        @elseif ($isEnterprise)
                            <a href="mailto:hello@serfix.io" class="block w-full rounded-md border border-slate-200 px-3 py-1.5 text-center text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                                Contact us
                            </a>
                        @elseif (! $isReady)
                            <button type="button" disabled class="w-full cursor-not-allowed rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                                Coming soon
                            </button>
                        @elseif ($subscription && $subscription->valid() && ! $isFree)
                            <div>
                                <div x-show="billing === 'yearly'">
                                    <button type="button" wire:click="openSwapConfirm('{{ $plan->slug }}')" class="w-full rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-500">
                                        Switch to {{ $plan->name }}
                                    </button>
                                </div>
                                <div x-show="billing === 'monthly'" style="display:none">
                                    <a href="{{ route('billing.checkout', ['plan' => $plan->slug, 'interval' => 'monthly']) }}" class="block w-full rounded-md bg-orange-600 px-3 py-1.5 text-center text-xs font-semibold text-white hover:bg-orange-500">
                                        Switch to {{ $plan->name }}
                                    </a>
                                </div>
                            </div>
                        @elseif ($isFree)
                            @if ($subscription && $subscription->valid())
                                <button type="button" wire:click="openCancelConfirm" class="w-full rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800">
                                    Downgrade to Free
                                </button>
                            @else
                                <button type="button" disabled class="w-full cursor-not-allowed rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-500 dark:bg-slate-800 dark:text-slate-400">
                                    Current plan
                                </button>
                            @endif
                        @else
                            <div>
                                <div x-show="billing === 'yearly'">
                                    <a href="{{ route('billing.checkout', ['plan' => $plan->slug, 'interval' => 'annual']) }}" class="block w-full rounded-md bg-orange-600 px-3 py-1.5 text-center text-xs font-semibold text-white hover:bg-orange-500">
                                        Subscribe — yearly
                                    </a>
                                </div>
                                <div x-show="billing === 'monthly'" style="display:none">
                                    <a href="{{ route('billing.checkout', ['plan' => $plan->slug, 'interval' => 'monthly']) }}" class="block w-full rounded-md bg-orange-600 px-3 py-1.5 text-center text-xs font-semibold text-white hover:bg-orange-500">
                                        Subscribe — monthly
                                    </a>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Recent invoices --}}
    @if ($invoices && count($invoices) > 0)
        <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3 dark:border-slate-800">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Recent invoices</h3>
                <a href="{{ route('billing.portal') }}" class="text-[11px] text-orange-600 hover:underline dark:text-orange-400">All invoices in Stripe Portal →</a>
            </div>
            <ul class="divide-y divide-slate-200 dark:divide-slate-800">
                @foreach ($invoices as $invoice)
                    <li class="flex items-center justify-between px-5 py-3 text-sm">
                        <div class="flex items-center gap-3">
                            <span class="text-slate-700 dark:text-slate-200">{{ \Illuminate\Support\Carbon::createFromTimestamp((int) $invoice->date()->getTimestamp())->toFormattedDayDateString() }}</span>
                            <span class="text-slate-500 dark:text-slate-400">{{ $invoice->total() }}</span>
                        </div>
                        @if ($invoice->invoice_pdf)
                            <a href="{{ $invoice->invoice_pdf }}" target="_blank" rel="noopener" class="text-[12px] font-medium text-orange-600 hover:underline dark:text-orange-400">PDF</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Danger zone — hidden during the free-promo window since
         there is no Stripe subscription to cancel. --}}
    @if (! $isFreePromo && $subscription && $subscription->valid() && ! $isCancelled)
        <div class="rounded-xl border border-rose-200 bg-rose-50/50 p-4 dark:border-rose-500/30 dark:bg-rose-500/5">
            <h3 class="text-sm font-semibold text-rose-900 dark:text-rose-200">Cancel subscription</h3>
            <p class="mt-1 text-[12px] text-rose-800 dark:text-rose-300">
                You'll keep Pro access until the end of your current billing period, then drop to Free automatically. Frozen sites past the Free 1-website limit will lock to read-only.
            </p>
            <button type="button" wire:click="openCancelConfirm" class="mt-3 inline-flex items-center rounded-md border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-500/40 dark:bg-rose-950/30 dark:text-rose-300">
                Cancel subscription
            </button>
        </div>
    @endif

    {{-- Cancel confirmation modal --}}
    @if ($confirmingCancel)
        <div class="fixed inset-0 z-50 flex items-center justify-center px-4">
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" wire:click="dismissCancelConfirm"></div>
            <div role="dialog" aria-modal="true" class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-800">
                <div class="p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Cancel subscription?</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
                        You'll keep Pro access until <strong>{{ $endsAt ? $endsAt->toFormattedDayDateString() : ($nextChargeAt ? $nextChargeAt->toFormattedDayDateString() : 'the end of the current period') }}</strong>. After that you'll drop to Free and lose AI features, the chatbot, and Pro-tier limits.
                    </p>
                    @if ($websiteLimit !== null && $totalWebsites > 1)
                        <p class="mt-2 text-xs text-rose-700 dark:text-rose-300">
                            You currently have {{ $totalWebsites }} websites; the Free plan only supports 1. The other {{ $totalWebsites - 1 }} will become read-only on the cancellation date.
                        </p>
                    @endif
                    <div class="mt-5 flex justify-end gap-2">
                        <button type="button" wire:click="dismissCancelConfirm" class="inline-flex items-center rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                            Keep subscription
                        </button>
                        <form method="POST" action="{{ route('billing.cancel-subscription') }}">
                            @csrf
                            <button type="submit" class="inline-flex items-center rounded-md bg-rose-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-rose-500">
                                Confirm cancel
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Swap confirmation modal --}}
    @if ($confirmingSwap)
        @php
            $targetPlan = $plans->firstWhere('slug', $confirmingSwap);
            $isUpgrade = $currentPlan && $targetPlan && $targetPlan->price_yearly_usd > $currentPlan->price_yearly_usd;
        @endphp
        @if ($targetPlan)
            <div class="fixed inset-0 z-50 flex items-center justify-center px-4">
                <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" wire:click="dismissSwapConfirm"></div>
                <div role="dialog" aria-modal="true" class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-800">
                    <div class="p-6">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Switch to {{ $targetPlan->name }}?</h2>
                        <p class="mt-2 text-sm leading-6 text-slate-600 dark:text-slate-300">
                            @if ($isUpgrade)
                                Your card will be charged the prorated difference today. New limits and features kick in immediately.
                            @else
                                You'll keep your current features until the next billing date, then switch. Stripe will issue a credit for any unused time on your current plan.
                            @endif
                        </p>
                        @php
                            $futureLimit = $targetPlan->max_websites;
                        @endphp
                        @if ($futureLimit !== null && $totalWebsites > $futureLimit)
                            <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                                {{ $targetPlan->name }} supports {{ $futureLimit }} {{ Str::plural('website', $futureLimit) }}. You currently have {{ $totalWebsites }}; the {{ $totalWebsites - $futureLimit }} oldest will keep working and the rest will be frozen.
                            </p>
                        @endif
                        <div class="mt-5 flex justify-end gap-2">
                            <button type="button" wire:click="dismissSwapConfirm" class="inline-flex items-center rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                                Keep current plan
                            </button>
                            <form method="POST" action="{{ route('billing.swap') }}">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $targetPlan->slug }}">
                                <button type="submit" class="inline-flex items-center rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-500">
                                    Confirm switch
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
