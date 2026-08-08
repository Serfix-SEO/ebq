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
</div>
