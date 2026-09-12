{{-- Pricing.

     Every figure is read from ContentAutopilotConfig — the landing page, the
     pricing page and the in-app screens share one source and can never drift.
     The yearly saving is DERIVED from the two prices, never written down.

     The monthly/yearly toggle and the extra-sites block are load-bearing, not
     decoration: they are what render the monthly price, both add-on prices and
     the worked example, all of which ContentLandingPageTest asserts move when
     an admin changes a Setting. The add-on block renders twice on purpose —
     stacked cards on a phone, a table from `sm` — because the table used to
     carry a min-width and scrolled the PRICE column off-screen on mobile, the
     one thing the block exists to show. --}}
<section id="pricing" class="scroll-mt-20 border-b border-slate-200 bg-white">
    <div class="mx-auto max-w-5xl px-6 py-16 lg:px-8 lg:py-20" x-data="{ billing: 'annual' }">
        <div class="text-center">
            <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ __('Pricing') }}</p>
            <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('One plan. Everything included.') }}</h2>
            <p class="mx-auto mt-3 max-w-xl text-base text-slate-600">{{ __('Start free for :days days — no card required.', ['days' => $trialDays]) }}</p>
        </div>

        {{-- Billing switch --}}
        <div class="mt-8 flex justify-center">
            <div class="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-100 p-1">
                <button type="button" @click="billing = 'monthly'"
                    :class="billing === 'monthly' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                    class="rounded-full px-5 py-2 text-sm font-semibold transition">{{ __('Monthly') }}</button>
                <button type="button" @click="billing = 'annual'"
                    :class="billing === 'annual' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                    class="rounded-full px-5 py-2 text-sm font-semibold transition">
                    {{ __('Yearly') }}
                    @if ($savePct > 0)
                        <span class="ms-1.5 rounded-full bg-success/15 px-2 py-0.5 text-[10px] font-bold uppercase text-success">{{ __('save :p%', ['p' => $savePct]) }}</span>
                    @endif
                </button>
            </div>
        </div>

        {{-- The plan --}}
        <div class="mt-10">
            <div class="overflow-hidden rounded-3xl border-2 border-orange-500 bg-white shadow-2xl shadow-orange-600/10">
                <div class="grid gap-8 p-8 sm:grid-cols-5 lg:p-10">
                    <div class="sm:col-span-2">
                        <p class="text-[11px] font-bold uppercase tracking-[0.18em] text-slate-500">{{ __('Content AI Autopilot') }}</p>
                        <div class="mt-4 flex items-baseline gap-1.5">
                            <span class="text-5xl font-extrabold tracking-tight text-slate-900" x-text="billing === 'annual' ? '${{ $annual }}' : '${{ $monthly }}'">${{ $annual }}</span>
                            <span class="text-lg font-medium text-slate-500">/{{ __('mo') }}</span>
                        </div>
                        <p class="mt-2 text-sm text-slate-500">
                            <span x-show="billing === 'annual'">{{ __('per website, billed yearly') }}</span>
                            <span x-show="billing === 'monthly'" x-cloak>{{ __('per website, billed monthly') }}</span>
                        </p>
                        @if ($first && $first < $monthly)
                            <p class="mt-3" x-show="billing === 'monthly'" x-cloak>
                                <span class="inline-block rounded-full bg-emerald-100 px-3 py-1 text-xs font-bold text-emerald-700">{{ __('First month $:f', ['f' => $first]) }}</span>
                            </p>
                        @endif

                        <a href="#start" class="mt-7 block rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3.5 text-center text-sm font-bold text-white shadow-lg shadow-orange-600/30 transition hover:brightness-110">{{ __('Start your free :days-day trial', ['days' => $trialDays]) }}</a>
                        <p class="mt-3 text-center text-xs text-slate-500">{{ __('No card required · cancel anytime') }}</p>
                    </div>

                    <ul class="grid gap-x-6 gap-y-2.5 sm:col-span-3 sm:grid-cols-2">
                        @foreach ([
                            __(':n SEO articles per month', ['n' => $articles]),
                            __('Website analysis'),
                            __('Competitor and keyword research'),
                            __('Content strategy and calendar'),
                            __('Original images for every article'),
                            __('On-page optimization'),
                            __('Automatic publishing to your site'),
                            __('Schema markup and full meta control'),
                            __(':n tracked keywords with rank history', ['n' => number_format($trackerKeywords)]),
                            __('Search Console and Analytics reporting'),
                            __('English and Arabic'),
                            __('Review and approve everything'),
                        ] as $item)
                            <li class="flex gap-2.5 text-sm text-slate-700">
                                <svg class="mt-0.5 h-4 w-4 flex-none text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                <span>{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        {{-- Additional websites --}}
        <div class="mx-auto mt-12 max-w-3xl">
            <h3 class="text-center text-lg font-extrabold tracking-tight text-slate-900">{{ __('Running more than one website?') }}</h3>
            <p class="mx-auto mt-2 max-w-xl text-center text-sm text-slate-600">{{ __('Your plan covers one website. Add as many more as you like — each one gets its own research, calendar, articles and tracker.') }}</p>

            {{-- One source for the numbers, rendered two ways: stacked cards on
                 a phone, the table from `sm` up. --}}
            @php
                $siteRows = [
                    [
                        'label' => __('First website'),
                        'included' => true,
                        'articles' => number_format($articles),
                        'note' => __(':n articles per month', ['n' => number_format($articles)]),
                        'annual' => '$'.$annual,
                        'monthly' => '$'.$monthly,
                        'highlight' => false,
                    ],
                    [
                        'label' => __('Each additional website'),
                        'included' => false,
                        'articles' => __('+:n each', ['n' => number_format($articles)]),
                        'note' => __(':n more articles per month, per site', ['n' => number_format($articles)]),
                        'annual' => '$'.$addonA,
                        'monthly' => '$'.$addonM,
                        'highlight' => false,
                    ],
                    [
                        'label' => __('Example: 3 websites'),
                        'included' => false,
                        'articles' => number_format($articles * 3),
                        'note' => __(':n articles per month', ['n' => number_format($articles * 3)]),
                        'annual' => '$'.$threeSitesAnnual,
                        'monthly' => '$'.$threeSitesMonthly,
                        'highlight' => true,
                    ],
                ];
            @endphp

            {{-- Phone: one card per row, nothing clipped --}}
            <div class="mt-6 space-y-3 sm:hidden">
                @foreach ($siteRows as $row)
                    <div @class([
                        'rounded-2xl border border-slate-200 p-4',
                        'bg-white' => ! $row['highlight'],
                        'bg-slate-50' => $row['highlight'],
                    ])>
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm font-semibold text-slate-900">
                                {{ $row['label'] }}
                                @if ($row['included'])
                                    <span class="ms-1.5 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-orange-700">{{ __('Included') }}</span>
                                @endif
                            </p>
                            <p class="whitespace-nowrap text-base font-bold text-slate-900">
                                <span x-show="billing === 'annual'">{{ $row['annual'] }}</span><span x-show="billing === 'monthly'" x-cloak>{{ $row['monthly'] }}</span><span class="text-xs font-medium text-slate-500">/{{ __('mo') }}</span>
                            </p>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ $row['note'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Tablet and up: the table --}}
            <div class="mt-6 hidden overflow-hidden rounded-2xl border border-slate-200 sm:block">
                <table class="w-full border-collapse text-start text-sm">
                    <thead>
                        <tr class="bg-slate-50 text-slate-500">
                            <th scope="col" class="px-5 py-3 text-start text-xs font-bold uppercase tracking-wider">{{ __('Websites') }}</th>
                            <th scope="col" class="px-5 py-3 text-start text-xs font-bold uppercase tracking-wider">{{ __('Articles per month') }}</th>
                            <th scope="col" class="px-5 py-3 text-end text-xs font-bold uppercase tracking-wider">{{ __('Price') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 bg-white">
                        @foreach ($siteRows as $row)
                            <tr @class(['bg-slate-50/70' => $row['highlight']])>
                                <th scope="row" class="px-5 py-4 text-start font-semibold text-slate-900">
                                    {{ $row['label'] }}
                                    @if ($row['included'])
                                        <span class="ms-2 rounded bg-orange-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-orange-700">{{ __('Included') }}</span>
                                    @endif
                                </th>
                                <td class="px-5 py-4 text-slate-600">{{ $row['articles'] }}</td>
                                <td class="px-5 py-4 text-end font-bold text-slate-900">
                                    <span x-show="billing === 'annual'">{{ $row['annual'] }}</span><span x-show="billing === 'monthly'" x-cloak>{{ $row['monthly'] }}</span><span class="font-medium text-slate-500">/{{ __('mo') }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-4 text-center text-xs text-slate-500">{{ __('Prices in USD. Add or remove websites anytime. Content AI Autopilot is billed separately from the Serfix SEO platform.') }}</p>
            <p class="mt-4 text-center">
                <a href="{{ route('content.pricing') }}" class="inline-flex items-center gap-1.5 text-sm font-bold text-orange-600 underline-offset-4 hover:underline">
                    {{ __('See everything included, feature by feature') }}
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                </a>
            </p>
        </div>
    </div>
</section>
