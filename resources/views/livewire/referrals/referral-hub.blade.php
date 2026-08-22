<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ __('Refer & earn') }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Share Serfix with a friend — when they make their first full payment, you get 50% off your next bill.') }}</p>
    </div>

    {{-- Referral link --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
        <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Your referral link') }}</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Anyone who signs up through this link counts as your referral.') }}</p>
        <div class="mt-3 flex flex-col gap-2 sm:flex-row" x-data="{ copied: false }">
            <input type="text" readonly value="{{ $this->url }}" onclick="this.select()"
                class="min-w-0 flex-1 rounded-xl border border-slate-300 bg-slate-50 px-3.5 py-2 font-mono text-sm text-slate-700 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200" />
            <button type="button"
                x-on:click="await navigator.clipboard.writeText(@js($this->url)); copied = true; setTimeout(() => copied = false, 2000)"
                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-orange-600 px-4 py-2 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                <svg x-show="! copied" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75"/></svg>
                <svg x-show="copied" x-cloak class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                <span x-show="! copied">{{ __('Copy link') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </button>
        </div>

        {{-- How it works --}}
        <div class="mt-5 grid gap-3 border-t border-slate-100 pt-4 sm:grid-cols-3 dark:border-slate-800">
            @foreach ([
                ['n' => '1', 't' => __('Share your link'), 'd' => __('Send it to friends, clients, or your audience.')],
                ['n' => '2', 't' => __('They subscribe'), 'd' => __('Your friend signs up and subscribes to Serfix.')],
                ['n' => '3', 't' => __('You save 50%'), 'd' => __('After their first full payment, 50% of your subscription price is credited to your next bill — automatically.')],
            ] as $step)
                <div class="flex items-start gap-3">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-orange-100 text-xs font-bold text-orange-700 dark:bg-orange-950 dark:text-orange-300">{{ $step['n'] }}</span>
                    <div>
                        <p class="text-xs font-bold text-slate-900 dark:text-slate-100">{{ $step['t'] }}</p>
                        <p class="mt-0.5 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ $step['d'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>
        <p class="mt-3 text-[11px] leading-4 text-slate-400 dark:text-slate-500">
            {{ __('The discount is calculated on your base subscription (additional-website add-ons are not included) and appears as a credit on your invoice. Every successful referral earns another 50% — credits add up.') }}
        </p>
    </div>

    {{-- Stats --}}
    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach ([
            [__('Sign-ups'), $stats['signups']],
            [__('Pending'), $stats['pending']],
            [__('Rewards earned'), $stats['matured']],
            [__('Total saved'), '$'.number_format($stats['earned_usd'], 2)],
        ] as [$label, $value])
            <div class="rounded-2xl border border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-slate-900">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">{{ $label }}</p>
                <p class="mt-1 text-xl font-bold tabular-nums text-slate-900 dark:text-slate-100">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    {{-- Referral list --}}
    <div class="mt-4 rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="border-b border-slate-100 px-5 py-4 dark:border-slate-800">
            <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Your referrals') }}</h2>
        </div>
        @if ($referrals->isEmpty())
            <div class="px-5 py-10 text-center">
                <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">{{ __('No referrals yet') }}</p>
                <p class="mx-auto mt-1 max-w-sm text-xs leading-5 text-slate-500 dark:text-slate-400">{{ __('Copy your link above and share it — you’ll see every sign-up and reward here.') }}</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-start text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                            <th class="px-5 py-2.5 text-start">{{ __('Referred account') }}</th>
                            <th class="px-5 py-2.5 text-start">{{ __('Signed up') }}</th>
                            <th class="px-5 py-2.5 text-start">{{ __('Status') }}</th>
                            <th class="px-5 py-2.5 text-end">{{ __('Your reward') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($referrals as $referral)
                            <tr wire:key="ref-{{ $referral->id }}">
                                <td class="px-5 py-3 font-medium text-slate-700 dark:text-slate-200">
                                    {{ \App\Livewire\Referrals\ReferralHub::maskEmail($referral->referred?->email) }}
                                </td>
                                <td class="px-5 py-3 text-slate-500 dark:text-slate-400">{{ $referral->created_at?->translatedFormat('M j, Y') }}</td>
                                <td class="px-5 py-3">
                                    @if ($referral->status === \App\Models\Referral::STATUS_CREDITED)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                                            {{ __('Reward credited') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>
                                            {{ __('Awaiting first payment') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-end tabular-nums text-slate-700 dark:text-slate-200">
                                    @if ($referral->status === \App\Models\Referral::STATUS_CREDITED && $referral->credit_cents !== null)
                                        <span class="font-semibold text-emerald-600 dark:text-emerald-400">${{ number_format($referral->credit_cents / 100, 2) }}</span>
                                        <span class="ms-1 text-[11px] text-slate-400">{{ $referral->credited_at?->translatedFormat('M j') }}</span>
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
