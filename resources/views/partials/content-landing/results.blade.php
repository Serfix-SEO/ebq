{{-- Real results (anonymised case study).

     Placed BEFORE the money sections on purpose: nobody weighs a monthly price
     against an agency's $3,000 until they believe the thing works.

     Rules this block must keep:
      - ANONYMISED. No client name, domain or logo. The four screenshots were
        checked before committing: none carries a property selector, URL bar or
        site name. The calendar's article titles reveal the niche, not the
        company.
      - Per-day rates are the headline. The two windows are unequal (21 days
        before, 7 after), so raw totals mislead — clicks read as flat (40 → 38)
        when the daily rate nearly tripled.
      - The CTR FALL IS SHOWN, not buried. A page that prints only the numbers
        that went up is a page nobody believes.
      - Numbers are literal, typed from the Search Console UI. NEVER derive
        them from our own `search_console_data` table: query-level sampling
        makes our stored totals materially lower (our DB has 323 impressions
        for the before window; Google's UI says 383), so a DB-driven figure
        would contradict the screenshot beside it. Same reason this is a
        before/after comparison and not a daily series.

     Redesigned 2026-09-12 (owner: "keep the data, enhance the design"): the
     three-panel before/published/after summary is new; every figure below it
     is unchanged. --}}
<section id="results" class="scroll-mt-20 border-b border-slate-200 bg-white">
    @php
        $caseStart = \Illuminate\Support\Carbon::create(2026, 7, 23);
        $caseShots = [
            'month' => 'cases/gsc-28-days.webp',
            'before' => 'cases/gsc-before.webp',
            'after' => 'cases/gsc-after.webp',
            'calendar' => 'cases/calendar-run.webp',
        ];
        // Each screenshot is optional: a missing file degrades to the copy
        // rather than rendering a broken image.
        $caseHas = array_map(fn ($p) => is_file(public_path($p)), $caseShots);

        // The three metrics that GREW. before/after carry both the display
        // string and the number the bar is drawn from; each metric is scaled
        // to its OWN max, since the units are not comparable to each other.
        // Click-through rate is deliberately NOT in this list — it fell, and
        // burying it among the wins would read as either a miss or a dodge.
        // It gets its own explained panel below.
        $caseMetrics = [
            [
                'label' => __('Impressions a day'),
                'help' => __('How often the site appeared in Google.'),
                'before' => ['text' => '18', 'n' => 18.2],
                'after' => ['text' => '118', 'n' => 118.3],
                'delta' => __(':n× more', ['n' => '6.5']),
            ],
            [
                'label' => __('Clicks a day'),
                'help' => __('How many people actually came through.'),
                'before' => ['text' => '1.9', 'n' => 1.9],
                'after' => ['text' => '5.4', 'n' => 5.4],
                'delta' => __(':n× more', ['n' => '2.9']),
            ],
            [
                'label' => __('Average position'),
                'help' => __('Where the site ranks on average. Lower is better.'),
                'before' => ['text' => '14.7', 'n' => 14.7],
                'after' => ['text' => '10.8', 'n' => 10.8],
                'delta' => __(':n places better', ['n' => '3.9']),
            ],
        ];
    @endphp

    <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8 lg:py-20">
        <div class="mx-auto max-w-3xl text-center">
            <p class="text-[11px] font-bold uppercase tracking-[0.2em] text-orange-600">{{ __('Real results') }}</p>
            <h2 class="mt-3 text-balance text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">{{ __('We tested Serfix on a real website.') }}</h2>
            <p class="mt-3 text-base leading-7 text-slate-600">
                {{ __('Not a demo site. Not a simulated graph. A Dubai brand-strategy consultancy put their blog on Content AI Autopilot, and the first article went live on :date — here is their Search Console before and after, measured per day, because the two windows are not the same length.', ['date' => $caseStart->translatedFormat('j F Y')]) }}
            </p>
        </div>

        {{-- The summary a visitor reads in four seconds. Everything in it is
             restated in full, with receipts, underneath. --}}
        <div class="mt-12 grid items-stretch gap-5 lg:grid-cols-3">
            @foreach ([
                ['label' => __('Before'), 'range' => __('4 – 24 July'), 'impressions' => '383', 'clicks' => '40', 'perDay' => __(':i impressions and :c clicks a day', ['i' => '18', 'c' => '1.9'])],
                ['label' => __('After'), 'range' => __('24 – 30 July'), 'impressions' => '828', 'clicks' => '38', 'perDay' => __(':i impressions and :c clicks a day', ['i' => '118', 'c' => '5.4'])],
            ] as $i => $panel)
                @php $isAfter = $i === 1; @endphp
                <div @class([
                    'order-1 rounded-2xl border p-6',
                    'border-slate-200 bg-slate-50' => ! $isAfter,
                    'order-3 border-orange-200 bg-orange-50/50' => $isAfter,
                ])>
                    <div class="flex items-baseline justify-between gap-2">
                        <p @class(['text-[11px] font-bold uppercase tracking-[0.16em]', 'text-slate-500' => ! $isAfter, 'text-orange-600' => $isAfter])>{{ $panel['label'] }}</p>
                        <p class="text-xs text-slate-400">{{ $panel['range'] }}</p>
                    </div>
                    <dl class="mt-5 grid grid-cols-2 gap-4">
                        @foreach ([[__('Impressions'), $panel['impressions']], [__('Clicks'), $panel['clicks']]] as [$dt, $dd])
                            <div>
                                <dt class="text-xs font-medium text-slate-500">{{ $dt }}</dt>
                                <dd @class(['mt-0.5 text-3xl font-extrabold tracking-tight', 'text-slate-900' => ! $isAfter, 'text-orange-600' => $isAfter])>{{ $dd }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-4 border-t border-slate-200/70 pt-3 text-xs leading-5 text-slate-500">{{ $panel['perDay'] }}</p>
                </div>
            @endforeach

            {{-- Middle column on desktop, first on mobile: what was actually
                 done to earn the difference. --}}
            <div class="order-2 rounded-2xl border-2 border-slate-900 bg-slate-900 p-6 shadow-xl shadow-slate-900/20">
                <p class="text-[11px] font-bold uppercase tracking-[0.16em] text-orange-400">{{ __('Serfix published') }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ __('First 10 days') }}</p>
                <dl class="mt-5 grid grid-cols-2 gap-4">
                    @foreach ([
                        ['11', __('articles')],
                        ['3,250', __('words each')],
                        ['42', __('original images')],
                        ['90', __('average SEO score')],
                    ] as [$value, $label])
                        <div>
                            <dt class="text-2xl font-extrabold tracking-tight text-white">{{ $value }}</dt>
                            <dd class="mt-0.5 text-[11px] leading-4 text-slate-400">{{ $label }}</dd>
                        </div>
                    @endforeach
                </dl>
                <p class="mt-4 border-t border-slate-700 pt-3 text-xs leading-5 text-slate-400">{{ __('No writer, no brief, no scheduling calls.') }}</p>
            </div>
        </div>

        {{-- The receipt, FULL WIDTH. One chart where the flat "before" and the
             climb from the 24th are obvious at a glance. --}}
        @if ($caseHas['month'])
            <figure class="mt-6 overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <figcaption class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-slate-200 px-5 py-3">
                    <span class="h-1.5 w-1.5 flex-none rounded-full bg-orange-500"></span>
                    <span class="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">{{ __('Their Search Console, unedited') }}</span>
                    <span class="text-xs text-slate-400">{{ __('28 days · publishing starts 24 July') }}</span>
                </figcaption>
                <a href="{{ asset($caseShots['month']) }}" target="_blank" rel="noopener" class="block bg-slate-50">
                    <img src="{{ asset($caseShots['month']) }}"
                         alt="{{ __('Search Console performance for the 28 days around the start date: clicks and impressions are flat, then climb sharply from 24 July.') }}"
                         width="1532" height="610" loading="lazy" decoding="async" class="w-full">
                </a>
                <div class="border-t border-slate-200 px-5 py-3 text-xs leading-5 text-slate-500">
                    {{ __('The flat stretch on the left is the three weeks before. The climb starts the day the articles did.') }}
                    <span class="mt-1 block text-slate-400 lg:hidden">{{ __('Tap the chart to open it full size.') }}</span>
                </div>
            </figure>
        @endif

        {{-- What that chart adds up to, per day. --}}
        <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:p-8">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                <h3 class="text-base font-extrabold tracking-tight text-slate-900">{{ __('What that adds up to, per day') }}</h3>
                <p class="text-xs font-medium text-slate-500">{{ __('21 days before → first 7 days') }}</p>
            </div>

            <div class="mt-6 grid gap-x-10 gap-y-6 sm:grid-cols-3">
                @foreach ($caseMetrics as $m)
                    @php $max = max($m['before']['n'], $m['after']['n']) ?: 1; @endphp
                    <div>
                        <p class="text-sm font-bold text-slate-900">{{ $m['label'] }}</p>
                        <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ $m['help'] }}</p>

                        <div class="mt-3 space-y-2">
                            @foreach ([
                                ['label' => __('Before'), 'v' => $m['before'], 'color' => '#cbd5e1'],
                                ['label' => __('After'), 'v' => $m['after'], 'color' => '#ea580c'],
                            ] as $row)
                                <div class="flex items-center gap-2.5">
                                    <span class="w-12 flex-none text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $row['label'] }}</span>
                                    <span class="w-12 flex-none text-end text-sm font-bold tabular-nums text-slate-900">{{ $row['v']['text'] }}</span>
                                    {{-- Only the bar geometry is SVG; labels and numbers stay
                                         real HTML so they survive translation and a 390px
                                         screen. viewBox height 14 === h-3.5 so nothing is
                                         squashed vertically, while x stretches freely because
                                         x IS the data. non-scaling-stroke keeps the round caps
                                         circular under that non-uniform scale; overflow-visible
                                         stops them being clipped at the ends. --}}
                                    <svg class="h-3 min-w-0 flex-1 overflow-visible" viewBox="0 0 100 14"
                                         preserveAspectRatio="none" aria-hidden="true" focusable="false">
                                        <line x1="0" y1="7" x2="100" y2="7" stroke="#f1f5f9" stroke-width="10" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                        <line x1="0" y1="7" x2="{{ round($row['v']['n'] / $max * 100, 1) }}" y2="7" stroke="{{ $row['color'] }}" stroke-width="10" stroke-linecap="round" vector-effect="non-scaling-stroke" />
                                    </svg>
                                </div>
                            @endforeach
                        </div>

                        <p class="mt-3 inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-700">
                            <span aria-hidden="true">↑</span>{{ $m['delta'] }}
                        </p>
                    </div>
                @endforeach
            </div>

            {{-- The one metric that fell, given its own explained panel rather
                 than a red-looking fourth column. It is not a miss: clicks a
                 day went UP 2.9× at the same time, so the falling percentage
                 is the arithmetic of a much bigger denominator. Shown, never
                 buried — a page that prints only the numbers that went up is
                 a page nobody believes. --}}
            <div class="mt-8 rounded-xl border border-sky-200 bg-sky-50/60 p-5">
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <svg class="h-4 w-4 flex-none text-sky-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 16v-4m0-4h.01"/></svg>
                    <p class="text-sm font-bold text-slate-900">{{ __('One number went down — here is why that is a good sign') }}</p>
                    <span class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-bold text-sky-700">{{ __('Expected') }}</span>
                </div>
                <p class="mt-2 text-sm leading-6 text-slate-600">
                    {{ __('Click-through rate went from 10.4% to 4.6%. That is a share, not a count: the site is now shown for far more searches, so the average share that clicks is smaller — while the actual clicks per day went UP 2.9×. More people are clicking, not fewer. A rising CTR on flat impressions would have meant the opposite: no new reach at all.') }}
                </p>
            </div>

            <p class="mt-5 border-t border-slate-100 pt-4 text-xs leading-5 text-slate-400">
                {{ __('The totals those rates come from: :bi impressions and :bc clicks over the 21 days before, then :ai impressions and :ac clicks over the first 7 days.', [
                    'bi' => '383', 'bc' => '40', 'ai' => '828', 'ac' => '38',
                ]) }}
            </p>
        </div>

        {{-- The two windows the numbers were read from, side by side so the
             comparison is immediate. Stacked on a phone; each panel keeps its
             big KPI tiles, which stay legible at that width. --}}
        @if ($caseHas['before'] && $caseHas['after'])
            <div class="mt-6 grid gap-6 md:grid-cols-2">
                @foreach ([
                    ['key' => 'before', 'label' => __('Before'), 'range' => __('4 – 24 July'), 'alt' => __('Search Console for 4 to 24 July: 40 clicks and 383 impressions, average position 14.7.')],
                    ['key' => 'after', 'label' => __('After'), 'range' => __('24 – 30 July'), 'alt' => __('Search Console for 24 to 30 July: 38 clicks and 828 impressions, average position 10.8.')],
                ] as $shot)
                    <figure class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                        <figcaption class="flex items-baseline gap-2 border-b border-slate-200 px-5 py-3">
                            <span class="text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500">{{ $shot['label'] }}</span>
                            <span class="text-xs text-slate-400">{{ $shot['range'] }}</span>
                        </figcaption>
                        <a href="{{ asset($caseShots[$shot['key']]) }}" target="_blank" rel="noopener" class="block bg-slate-50">
                            <img src="{{ asset($caseShots[$shot['key']]) }}" alt="{{ $shot['alt'] }}"
                                 width="1532" height="605" loading="lazy" decoding="async" class="w-full">
                        </a>
                    </figure>
                @endforeach
            </div>
        @endif

        {{-- The calendar that produced it. --}}
        @if ($caseHas['calendar'])
            <div class="mt-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:p-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h3 class="text-base font-extrabold tracking-tight text-slate-900">{{ __('What we published to get there') }}</h3>
                    <p class="mt-2 text-sm leading-6 text-slate-600">{{ __('Eleven articles in the first ten days, each researched, written, scored and illustrated before it went live — no writer, no brief, no scheduling calls.') }}</p>
                </div>
                <a href="{{ asset($caseShots['calendar']) }}" target="_blank" rel="noopener"
                   class="mt-6 block overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
                    <img src="{{ asset($caseShots['calendar']) }}"
                         alt="{{ __('Their content calendar: empty until the 22nd, then a published article every day from the 23rd, each showing its SEO score, word count and images.') }}"
                         width="1502" height="650" loading="lazy" decoding="async" class="w-full">
                </a>
                <p class="mt-3 text-center text-xs leading-5 text-slate-500">{{ __('Their real calendar: nothing until the 22nd, then an article a day.') }}</p>
            </div>
        @endif

        <p class="mx-auto mt-8 max-w-3xl text-center text-xs leading-6 text-slate-500">
            {{ __('One website, one week. That is early movement rather than a settled result, and we are not claiming these articles were the only thing that changed on the site. Yours will behave differently.') }}
        </p>
    </div>
</section>
