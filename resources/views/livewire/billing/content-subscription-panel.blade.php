{{--
    Content Autopilot billing on /billing. Sits under the dashboard/SEO panel,
    or above it for content-only customers ($first) — see billing/subscription.
    Renders nothing for users with no content relationship, so the page is
    unchanged for SEO-only customers.
--}}
<div>
@if ($show)
    @php
        $statusLabel = __('Free');
        $statusTone = 'slate';
        if ($isPastDue) { $statusLabel = __('Past due'); $statusTone = 'red'; }
        elseif ($isCancelled && $endsAt) { $statusLabel = __('Cancels :date', ['date' => $endsAt->toFormattedDateString()]); $statusTone = 'amber'; }
        elseif ($hasSub) { $statusLabel = __('Active'); $statusTone = 'emerald'; }
        elseif ($onTrial && $trialEndsAt) {
            $daysLeft = max(0, (int) floor(now()->diffInDays($trialEndsAt)));
            $statusLabel = $daysLeft === 1
                ? __('Trial — :days day left', ['days' => $daysLeft])
                : __('Trial — :days days left', ['days' => $daysLeft]);
            $statusTone = 'orange';
        }
        elseif ($compedSites > 0) { $statusLabel = __('Included'); $statusTone = 'emerald'; }
        $tones = [
            'emerald' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-300',
            'orange'  => 'bg-orange-100 text-orange-800 dark:bg-orange-500/10 dark:text-orange-300',
            'amber'   => 'bg-amber-100 text-amber-800 dark:bg-amber-500/10 dark:text-amber-300',
            'red'     => 'bg-rose-100 text-rose-800 dark:bg-rose-500/10 dark:text-rose-300',
            'slate'   => 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
        ];
        $money = fn ($value, $cur = 'USD') => ($cur === 'USD' ? '$' : $cur.' ').number_format((float) $value, 2);
    @endphp

    <section class="{{ $first ? 'mb-8' : 'mt-8' }} rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4 dark:border-slate-800">
            <div>
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">{{ __('Content AI Autopilot') }}</h2>
                <p class="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                    {{ __('Billed separately from your SEO platform plan.') }}
                </p>
            </div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $tones[$statusTone] }}">
                {{ $statusLabel }}
            </span>
        </div>

        <div class="grid gap-5 px-5 py-5 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Plan') }}</p>
                <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                    @if ($hasSub)
                        {{ $interval === 'annual' ? __('Yearly') : __('Monthly') }}
                    @elseif ($onTrial)
                        {{ __('Free trial') }}
                    @else
                        {{ __('Included by Serfix') }}
                    @endif
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Amount') }}</p>
                <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                    @if ($hasSub && $amount !== null)
                        {{ $money($amount, $currency) }}
                        <span class="font-normal text-slate-500 dark:text-slate-400">
                            {{ $interval === 'annual' ? __('/ year') : __('/ month') }}
                        </span>
                    @else
                        &mdash;
                    @endif
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    {{ $isCancelled ? __('Access until') : __('Next charge') }}
                </p>
                <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                    @if ($isCancelled && $endsAt)
                        {{ $endsAt->toFormattedDateString() }}
                    @elseif ($nextChargeAt)
                        {{ $nextChargeAt->toFormattedDateString() }}
                    @elseif ($onTrial && $trialEndsAt)
                        {{ $trialEndsAt->toFormattedDateString() }}
                    @else
                        &mdash;
                    @endif
                </p>
            </div>

            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Websites') }}</p>
                <p class="mt-1 text-sm font-semibold text-slate-900 dark:text-white">
                    {{ $sitesCovered }} / {{ $sitesAllowed }}
                    @if ($extraSites > 0)
                        <span class="font-normal text-slate-500 dark:text-slate-400">
                            ({{ trans_choice('1 extra website|:count extra websites', $extraSites, ['count' => $extraSites]) }})
                        </span>
                    @endif
                </p>
            </div>
        </div>

        @if ($coveredSites->isNotEmpty())
            <div class="border-t border-slate-200 px-5 py-4 dark:border-slate-800">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Covered websites') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($coveredSites as $site)
                        <span class="inline-flex items-center rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            {{ $site->domain }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        @if (! empty($invoices))
            <div class="border-t border-slate-200 px-5 py-4 dark:border-slate-800">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ __('Content invoices') }}</p>
                <ul class="mt-2 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    @foreach ($invoices as $invoice)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="text-slate-600 dark:text-slate-300">
                                {{ $invoice['date']?->toFormattedDateString() ?? '—' }}
                            </span>
                            <span class="flex items-center gap-3">
                                <span class="font-semibold text-slate-900 dark:text-white">
                                    {{ $money($invoice['total'] / 100, $invoice['currency']) }}
                                </span>
                                @if ($invoice['url'])
                                    <a href="{{ $invoice['url'] }}" target="_blank" rel="noopener"
                                       class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">
                                        {{ __('Receipt') }}
                                    </a>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3 border-t border-slate-200 px-5 py-4 dark:border-slate-800">
            @if ($hasContentSettings)
                <a href="{{ route('content.settings') }}" wire:navigate
                   class="inline-flex items-center rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800 dark:bg-white dark:text-slate-900 dark:hover:bg-slate-100">
                    {{ __('Content settings') }}
                </a>
            @endif
            @if ($hasPortal)
                <a href="{{ route('billing.portal') }}"
                   class="inline-flex items-center rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                    {{ __('Payment method & invoices') }}
                </a>
            @endif
            @if (! $hasSub && $hasGetStarted)
                <a href="{{ route('content.get-started') }}" wire:navigate
                   class="inline-flex items-center rounded-lg bg-orange-600 px-3 py-2 text-sm font-semibold text-white hover:bg-orange-700">
                    {{ $onTrial ? __('Choose a content plan') : __('See content plans') }}
                </a>
            @endif

            @if ($isCancelled)
                <form method="POST" action="{{ route('content.billing.resume') }}" class="ms-auto">
                    @csrf
                    <button type="submit"
                            class="inline-flex items-center rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">
                        {{ __('Resume content plan') }}
                    </button>
                </form>
            @elseif ($hasSub)
                <button type="button" wire:click="openCancelConfirm"
                        class="ms-auto text-sm font-semibold text-slate-500 hover:text-rose-600 dark:text-slate-400 dark:hover:text-rose-400">
                    {{ __('Cancel content plan') }}
                </button>
            @endif
        </div>

        @if ($confirmingCancel)
            <div class="border-t border-rose-200 bg-rose-50 px-5 py-4 dark:border-rose-500/30 dark:bg-rose-500/10">
                <p class="text-sm font-semibold text-rose-900 dark:text-rose-200">
                    {{ __('Cancel Content AI Autopilot?') }}
                </p>
                <p class="mt-1 text-sm text-rose-800 dark:text-rose-300">
                    {{ __('Your calendar keeps running until the end of the period you\'ve paid for. Already-published articles stay on your site. Your SEO platform plan is not affected.') }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <form method="POST" action="{{ route('content.billing.cancel') }}">
                        @csrf
                        <button type="submit"
                                class="inline-flex items-center rounded-lg bg-rose-600 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-700">
                            {{ __('Yes, cancel at period end') }}
                        </button>
                    </form>
                    <button type="button" wire:click="dismissCancelConfirm"
                            class="text-sm font-semibold text-slate-600 hover:underline dark:text-slate-300">
                        {{ __('Keep my plan') }}
                    </button>
                </div>
            </div>
        @endif
    </section>
@endif

@if ($showPlans ?? false)
    @php
        $annualMonthly = $prices['annual'];
        $annualTotal = $prices['annual'] * 12;
        $savePct = $prices['monthly'] > 0
            ? (int) round(100 * ($prices['monthly'] - $prices['annual']) / $prices['monthly'])
            : 0;
        $currentInterval = ($hasSub ?? false) ? $interval : null;
    @endphp

    <div class="grid gap-3 sm:grid-cols-2">
        {{-- Monthly --}}
        <div class="relative flex flex-col rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-baseline justify-between">
                <h4 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('Monthly') }}</h4>
                @if ($currentInterval === 'monthly')
                    <span class="rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-orange-700 dark:bg-orange-500/10 dark:text-orange-300">{{ __('Current') }}</span>
                @endif
            </div>
            <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ __('Cancel any time.') }}</p>
            <div class="mt-3 flex items-baseline gap-1">
                <span class="text-2xl font-bold text-slate-900 dark:text-white">${{ $prices['monthly'] }}</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ __('/mo') }}</span>
            </div>
            @if (! ($hasSub ?? false) && $prices['first_month'] > 0 && $prices['first_month'] < $prices['monthly'])
                <p class="text-[10px] text-slate-400 dark:text-slate-500">
                    {{ __('$:first for your first month', ['first' => $prices['first_month']]) }}
                </p>
            @endif
            @if ($currentInterval !== 'monthly' && ($checkoutReady['monthly'] ?? false))
                <a href="{{ route('content.billing.checkout', array_filter(['interval' => 'monthly', 'website' => $checkoutWebsiteId])) }}"
                   class="mt-4 inline-flex items-center justify-center rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-500">
                    {{ ($hasSub ?? false) ? __('Switch to monthly') : __('Choose monthly') }}
                </a>
            @elseif ($currentInterval !== 'monthly')
                <span class="mt-4 inline-flex items-center justify-center rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                    {{ __('Coming soon') }}
                </span>
            @endif
        </div>

        {{-- Yearly --}}
        <div class="relative flex flex-col rounded-xl border border-slate-200 bg-white p-4 shadow-sm ring-2 ring-orange-500/40 dark:border-slate-800 dark:bg-slate-900">
            @if ($savePct > 0)
                <span class="absolute -top-2 end-3 rounded-full bg-orange-600 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white">
                    {{ __('Save :percent%', ['percent' => $savePct]) }}
                </span>
            @endif
            <div class="flex items-baseline justify-between">
                <h4 class="text-base font-semibold text-slate-900 dark:text-white">{{ __('Yearly') }}</h4>
                @if ($currentInterval === 'annual')
                    <span class="rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-orange-700 dark:bg-orange-500/10 dark:text-orange-300">{{ __('Current') }}</span>
                @endif
            </div>
            <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ __('Best value for an always-on calendar.') }}</p>
            <div class="mt-3 flex items-baseline gap-1">
                <span class="text-2xl font-bold text-slate-900 dark:text-white">${{ $annualMonthly }}</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ __('/mo') }}</span>
            </div>
            <p class="text-[10px] text-slate-400 dark:text-slate-500">{{ __('$:price billed yearly', ['price' => $annualTotal]) }}</p>
            @if ($currentInterval !== 'annual' && ($checkoutReady['annual'] ?? false))
                <a href="{{ route('content.billing.checkout', array_filter(['interval' => 'annual', 'website' => $checkoutWebsiteId])) }}"
                   class="mt-4 inline-flex items-center justify-center rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-500">
                    {{ ($hasSub ?? false) ? __('Switch to yearly') : __('Choose yearly') }}
                </a>
            @elseif ($currentInterval !== 'annual')
                <span class="mt-4 inline-flex items-center justify-center rounded-md bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                    {{ __('Coming soon') }}
                </span>
            @endif
        </div>
    </div>

    {{-- What the price buys, and what an extra website costs. Both plans
         include the same thing — the only variable is how many websites. --}}
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-slate-200 bg-white p-4 text-[12px] shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs font-semibold text-slate-900 dark:text-white">{{ __('Every plan includes') }}</p>
            <ul class="mt-2 space-y-1 text-slate-600 dark:text-slate-300">
                <li>{{ __('Up to :n articles a month, per website', ['n' => $monthlyArticles]) }}</li>
                <li>{{ __('Research, writing, SEO scoring and original images') }}</li>
                <li>{{ __('Auto-publishing to WordPress, plus keyword tracking') }}</li>
                <li>{{ __('1 website included') }}</li>
            </ul>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-4 text-[12px] shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <p class="text-xs font-semibold text-slate-900 dark:text-white">{{ __('Extra websites') }}</p>
            <p class="mt-2 text-slate-600 dark:text-slate-300">
                {{ __('Add more sites to the same subscription at any time.') }}
            </p>
            <dl class="mt-2 space-y-1 text-slate-600 dark:text-slate-300">
                <div class="flex items-center justify-between gap-3">
                    <dt>{{ __('On the monthly plan') }}</dt>
                    <dd class="font-semibold text-slate-900 dark:text-white">${{ $prices['addon_monthly'] }} {{ __('/mo each') }}</dd>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <dt>{{ __('On the yearly plan') }}</dt>
                    <dd class="font-semibold text-slate-900 dark:text-white">${{ $prices['addon_annual'] }} {{ __('/mo each') }}</dd>
                </div>
            </dl>
            @if ($hasSub ?? false)
                <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">
                    {{ __('Add one from Content settings.') }}
                </p>
            @endif
        </div>
    </div>

    {{-- ── Rewrite credits: balance + purchasable packs ── --}}
    @if ($canBuyRewriteCredits ?? false)
        <div class="mt-3 rounded-xl border border-slate-200 bg-white p-4 text-[12px] shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p class="text-xs font-semibold text-slate-900 dark:text-white">{{ __('Rewrite credits') }}</p>
                    <p class="mt-1 text-slate-600 dark:text-slate-300">{{ __('Ask for changes on any article and we rewrite it — 1 credit per rewrite. Purchased credits never expire.') }}</p>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold tabular-nums text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                      title="{{ __(':free free this month + :bought purchased', ['free' => $rewriteCredits['free_remaining'], 'bought' => $rewriteCredits['purchased']]) }}">
                    {{ __(':n left', ['n' => $rewriteCredits['total']]) }}
                </span>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($rewritePacks as $i => $pack)
                    <a href="{{ route('content.credits.checkout', ['pack' => $i]) }}"
                       class="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3.5 py-2 text-xs font-semibold text-slate-700 hover:border-orange-400 hover:bg-orange-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-orange-950">
                        {{ trans_choice(':n credit|:n credits', $pack['credits'], ['n' => $pack['credits']]) }}
                        <span class="font-bold text-orange-600">${{ $pack['usd'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endif
</div>
