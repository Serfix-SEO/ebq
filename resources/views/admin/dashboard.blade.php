<x-layouts.app>
    @php
        /**
         * @var array $daily
         * @var array $segments
         * @var array $series
         * @var \Illuminate\Support\Collection $recentSignups
         * @var \Illuminate\Support\Collection $recentSubscriptions
         * @var array $stripe
         */
        $fmtN = fn ($n) => number_format((int) $n);
        $money = fn (float $v, string $cur = 'USD') => ($cur === 'USD' ? '$' : $cur.' ').number_format($v, 2);
        // Yesterday comparison chip: neutral at zero-change, green up, red down.
        $delta = function (int $today, int $yesterday): array {
            $diff = $today - $yesterday;
            return [
                'text' => ($diff > 0 ? '+' : '').$diff.' vs yesterday',
                'class' => $diff > 0 ? 'text-emerald-600 dark:text-emerald-400'
                    : ($diff < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-400'),
            ];
        };
        // Inline bar chart: pure SVG, one bar per day, scaled to the series max.
        $bars = function (array $values) {
            $max = max(1, max($values));
            $n = count($values);
            $w = 100 / $n;
            $svg = '';
            foreach ($values as $i => $v) {
                $h = $v > 0 ? max(4, round(92 * $v / $max)) : 2;
                $x = round($i * $w + $w * 0.15, 2);
                $svg .= sprintf(
                    '<rect x="%s%%" y="%s" width="%s%%" height="%s" rx="1.5" class="%s"><title>%s</title></rect>',
                    $x, 100 - $h, round($w * 0.7, 2), $h,
                    $v > 0 ? 'fill-orange-500' : 'fill-slate-200 dark:fill-slate-700',
                    $v,
                );
            }
            return $svg;
        };
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Dashboard</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ now()->toFormattedDayDateString() }} — signups, trials, publishing and revenue at a glance.
                    Internal (admin/system) accounts are excluded from customer counts.
                </p>
            </div>
            <a href="{{ route('admin.clients.index') }}"
               class="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                All clients →
            </a>
        </div>

        {{-- ── Today ─────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-2 lg:grid-cols-5">
            @foreach ([
                ['label' => 'Signups today', 'data' => $daily['signups'], 'metric' => 'signups-today'],
                ['label' => 'Trials started today', 'data' => $daily['trials'], 'metric' => 'trials-today'],
                ['label' => 'Articles published today', 'data' => $daily['articles'], 'metric' => 'articles-today'],
                ['label' => 'Leads today', 'data' => $daily['leads'], 'metric' => 'leads-today'],
            ] as $card)
                @php $d = $delta($card['data']['today'], $card['data']['yesterday']); @endphp
                <a href="{{ route('admin.dashboard.drill', $card['metric']) }}"
                   class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-orange-300 hover:shadow dark:border-slate-800 dark:bg-slate-900 dark:hover:border-orange-500/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $card['label'] }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ $fmtN($card['data']['today']) }}</p>
                    <p class="mt-0.5 text-[11px] font-medium {{ $d['class'] }}">{{ $d['text'] }}</p>
                </a>
            @endforeach
            <a href="{{ route('admin.dashboard.drill', 'payments-today') }}"
               class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-orange-300 hover:shadow dark:border-slate-800 dark:bg-slate-900 dark:hover:border-orange-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Payments today</p>
                @if ($stripe['available'])
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ $money($stripe['today_amount'], $stripe['currency']) }}</p>
                    <p class="mt-0.5 text-[11px] font-medium text-slate-400">{{ $fmtN($stripe['today_count']) }} {{ $stripe['today_count'] === 1 ? 'payment' : 'payments' }}</p>
                @else
                    <p class="mt-1 text-2xl font-bold text-slate-400">&mdash;</p>
                    <p class="mt-0.5 text-[11px] font-medium text-slate-400">Stripe unavailable</p>
                @endif
            </a>
        </div>

        {{-- ── Customer segments + revenue ───────────────────────── --}}
        <div class="grid gap-2 md:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                ['label' => 'Total customers', 'value' => $segments['total'], 'tone' => 'text-slate-900 dark:text-white', 'metric' => 'customers'],
                ['label' => 'Paid', 'value' => $segments['paid'], 'tone' => 'text-emerald-600 dark:text-emerald-400', 'metric' => 'paid'],
                ['label' => 'On trial', 'value' => $segments['on_trial'], 'tone' => 'text-orange-600 dark:text-orange-400', 'metric' => 'on-trial'],
                ['label' => 'Free', 'value' => $segments['free'], 'tone' => 'text-slate-900 dark:text-white', 'metric' => 'free'],
                ['label' => 'Card added, not paid', 'value' => $segments['with_card'], 'tone' => 'text-amber-600 dark:text-amber-400', 'metric' => 'with-card'],
                ['label' => 'Disabled', 'value' => $segments['disabled'], 'tone' => 'text-rose-600 dark:text-rose-400', 'metric' => 'disabled'],
            ] as $tile)
                <a href="{{ route('admin.dashboard.drill', $tile['metric']) }}"
                   class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-orange-300 hover:shadow dark:border-slate-800 dark:bg-slate-900 dark:hover:border-orange-500/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $tile['label'] }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums {{ $tile['tone'] }}">{{ $fmtN($tile['value']) }}</p>
                </a>
            @endforeach
        </div>

        <div class="grid gap-2 md:grid-cols-3 xl:grid-cols-6">
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">MRR (Stripe)</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">
                    {{ $stripe['available'] && $stripe['mrr'] !== null ? $money($stripe['mrr'], $stripe['currency']) : '—' }}
                </p>
            </div>
            <a href="{{ route('admin.dashboard.drill', 'payments-month') }}"
               class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-orange-300 hover:shadow dark:border-slate-800 dark:bg-slate-900 dark:hover:border-orange-500/40">
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Collected this month</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-white">
                    {{ $stripe['available'] ? $money($stripe['month_amount'], $stripe['currency']) : '—' }}
                </p>
            </a>
            @foreach ([
                ['label' => 'Websites', 'value' => $segments['websites'], 'metric' => 'websites'],
                ['label' => 'Articles published (all time)', 'value' => $segments['articles_total'], 'metric' => 'articles-all'],
                ['label' => 'Internal accounts', 'value' => $segments['internal'], 'metric' => 'internal'],
            ] as $tile)
                <a href="{{ route('admin.dashboard.drill', $tile['metric']) }}"
                   class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm transition hover:border-orange-300 hover:shadow dark:border-slate-800 dark:bg-slate-900 dark:hover:border-orange-500/40">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">{{ $tile['label'] }}</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{{ $fmtN($tile['value']) }}</p>
                </a>
            @endforeach
        </div>

        {{-- ── Content Autopilot payments ────────────────────────── --}}
        {{-- Two series on one timeline: money COLLECTED (paid Stripe invoices,
             history) and money SCHEDULED (future renewals, projected forward by
             each subscription's billing interval). The window may reach into
             the future on purpose — "what will we charge" is the question this
             answers — so the presets are asymmetric and the custom pair accepts
             any two dates. Both series come from Stripe, never from our local
             price settings. --}}
        @php
            $pay = $payments;
            $payDays = $pay['days'];
            $payMax = max(1, max(array_merge([0], array_map(
                fn ($d) => max($d['collected'], $d['scheduled']), $payDays
            ))));
            $todayKey = now()->toDateString();
            // One bar per day is unreadable past ~120 days, so label sparsely.
            $labelEvery = (int) max(1, ceil(count($payDays) / 12));
        @endphp
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Content Autopilot payments</p>
                    <p class="text-xs text-slate-400">
                        {{ $payRange['from']->toFormattedDateString() }} — {{ $payRange['to']->toFormattedDateString() }}
                        · {{ $fmtN(count($payDays)) }} days
                    </p>
                </div>

                <form method="GET" class="flex flex-wrap items-end gap-2">
                    <div class="flex flex-wrap gap-1">
                        @foreach ([['7','7d'], ['30','30d'], ['90','90d'], ['next30','Next 30d'], ['next90','Next 90d']] as [$val, $label])
                            <a href="{{ route('admin.dashboard', ['pay_range' => $val]) }}"
                               @class([
                                   'rounded border px-2.5 py-1 text-[11px] font-semibold',
                                   'border-orange-500 bg-orange-50 text-orange-700 dark:bg-orange-500/10' => $payRange['preset'] === $val,
                                   'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800' => $payRange['preset'] !== $val,
                               ])>{{ $label }}</a>
                        @endforeach
                    </div>
                    <div class="flex items-end gap-2 border-l border-slate-200 pl-2 dark:border-slate-700">
                        <label class="text-[10px] uppercase tracking-wider text-slate-500">From
                            <input type="date" name="pay_from" value="{{ $payRange['from']->toDateString() }}"
                                   class="block rounded border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800" />
                        </label>
                        <label class="text-[10px] uppercase tracking-wider text-slate-500">To
                            <input type="date" name="pay_to" value="{{ $payRange['to']->toDateString() }}"
                                   class="block rounded border border-slate-300 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800" />
                        </label>
                        <input type="hidden" name="pay_range" value="custom" />
                        <button class="rounded bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white dark:bg-slate-700">Apply</button>
                    </div>
                </form>
            </div>

            @if (! $pay['available'])
                <p class="mt-6 rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-400 dark:border-slate-700">
                    Stripe is unavailable right now — payment figures cannot be shown.
                </p>
            @else
                <div class="mt-4 grid gap-2 sm:grid-cols-4">
                    @foreach ([
                        ['label' => 'Collected in range', 'value' => $money($pay['collected_total'], $pay['currency']), 'tone' => 'text-emerald-600 dark:text-emerald-400'],
                        ['label' => 'Scheduled in range', 'value' => $money($pay['scheduled_total'], $pay['currency']), 'tone' => 'text-orange-600 dark:text-orange-400'],
                        ['label' => 'Payments received', 'value' => $fmtN($pay['collected_count']), 'tone' => 'text-slate-900 dark:text-white'],
                        ['label' => 'Active subscribers', 'value' => $fmtN($pay['subscribers']), 'tone' => 'text-slate-900 dark:text-white'],
                    ] as $tile)
                        <div class="rounded-lg border border-slate-200 p-3 dark:border-slate-800">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{{ $tile['label'] }}</p>
                            <p class="mt-0.5 text-xl font-bold tabular-nums {{ $tile['tone'] }}">{{ $tile['value'] }}</p>
                        </div>
                    @endforeach
                </div>

                {{-- Bars are percentage-width so the chart reflows with the card;
                     only the geometry is SVG, the axis labels stay real HTML so
                     they survive a narrow screen. --}}
                <div class="mt-5">
                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="h-40 w-full"
                         role="img" aria-label="Content Autopilot payments collected and scheduled per day">
                        @php $n = max(1, count($payDays)); $w = 100 / $n; @endphp
                        @foreach ($payDays as $i => $d)
                            @php
                                $x = $i * $w;
                                $hC = $d['collected'] > 0 ? max(2, 96 * $d['collected'] / $payMax) : 0;
                                $hS = $d['scheduled'] > 0 ? max(2, 96 * $d['scheduled'] / $payMax) : 0;
                                $bw = $w * 0.72;
                                $half = $hC > 0 && $hS > 0;
                            @endphp
                            @if ($hC > 0)
                                <rect x="{{ round($x + $w * 0.14, 3) }}%" y="{{ round(100 - $hC, 2) }}"
                                      width="{{ round($half ? $bw / 2 : $bw, 3) }}%" height="{{ round($hC, 2) }}"
                                      class="fill-emerald-500"><title>{{ $d['label'] }} · collected {{ $money($d['collected'], $pay['currency']) }}</title></rect>
                            @endif
                            @if ($hS > 0)
                                <rect x="{{ round($x + $w * 0.14 + ($half ? $bw / 2 : 0), 3) }}%" y="{{ round(100 - $hS, 2) }}"
                                      width="{{ round($half ? $bw / 2 : $bw, 3) }}%" height="{{ round($hS, 2) }}"
                                      class="fill-orange-400"><title>{{ $d['label'] }} · scheduled {{ $money($d['scheduled'], $pay['currency']) }}</title></rect>
                            @endif
                            @if ($d['date'] === $todayKey)
                                <rect x="{{ round($x + $w * 0.14, 3) }}%" y="0" width="0.15%" height="100" class="fill-slate-300 dark:fill-slate-600"><title>Today</title></rect>
                            @endif
                        @endforeach
                    </svg>
                    <div class="mt-1 flex justify-between text-[10px] text-slate-400">
                        @foreach ($payDays as $i => $d)
                            @if ($i % $labelEvery === 0)
                                <span>{{ $d['label'] }}</span>
                            @endif
                        @endforeach
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-4 text-[11px] text-slate-500">
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-500"></span>Collected (paid invoices)</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-orange-400"></span>Scheduled (upcoming renewals)</span>
                        <span class="text-slate-400">Scheduled is list price × quantity — coupons, proration and tax are applied by Stripe at invoice time, and a future cancellation cannot be predicted.</span>
                    </div>
                </div>

                @if ($pay['upcoming'] !== [])
                    <div class="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">Next charges</p>
                        <div class="mt-2 overflow-auto">
                            <table class="w-full text-left text-xs">
                                <thead class="text-[10px] uppercase tracking-wider text-slate-400">
                                    <tr><th class="py-1 pr-3">Date</th><th class="py-1 pr-3">Client</th><th class="py-1 pr-3">Billing</th><th class="py-1 text-right">Amount</th></tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach (array_slice($pay['upcoming'], 0, 12) as $row)
                                        <tr>
                                            <td class="py-1.5 pr-3 tabular-nums text-slate-600 dark:text-slate-300">{{ $row['at']->toFormattedDateString() }}</td>
                                            <td class="py-1.5 pr-3 text-slate-600 dark:text-slate-300">{{ $row['email'] ?? '—' }}</td>
                                            <td class="py-1.5 pr-3 text-slate-400">{{ $row['interval'] }}ly</td>
                                            <td class="py-1.5 text-right font-semibold tabular-nums text-slate-900 dark:text-white">{{ $money($row['amount'], $pay['currency']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if (count($pay['upcoming']) > 12)
                            <p class="mt-2 text-[11px] text-slate-400">{{ $fmtN(count($pay['upcoming']) - 12) }} more scheduled in this range.</p>
                        @endif
                    </div>
                @endif
            @endif
        </div>

        {{-- ── 14-day trends ─────────────────────────────────────── --}}
        <div class="grid gap-3 lg:grid-cols-3">
            @foreach ([
                ['label' => 'Signups', 'values' => $series['signups']],
                ['label' => 'Content trials started', 'values' => $series['trials']],
                ['label' => 'Articles published', 'values' => $series['articles']],
            ] as $chart)
                <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-baseline justify-between">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $chart['label'] }}</p>
                        <p class="text-xs tabular-nums text-slate-400">{{ $fmtN(array_sum($chart['values'])) }} / 14 days</p>
                    </div>
                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="mt-3 h-24 w-full" role="img"
                         aria-label="{{ $chart['label'] }}, last 14 days">
                        {!! $bars($chart['values']) !!}
                    </svg>
                    <div class="mt-1 flex justify-between text-[10px] text-slate-400">
                        <span>{{ $series['labels'][0] }}</span>
                        <span>{{ end($series['labels']) }}</span>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ── Waiting on us ─────────────────────────────────────
             Above the feeds on purpose: these are the two queues where a slow
             response costs a customer.

             Mobile-first layout: every row STACKS. The desktop pattern of
             "text left, meta hard right" squeezes a long ticket subject into a
             few characters on a 390px screen, so the meta drops to its own line
             and only widens back out at sm:. --}}
        <div class="grid gap-3 lg:grid-cols-2">
            {{-- Unreplied support tickets. "open" IS the unreplied state — the
                 whose-turn tracker flips to "answered" the moment we reply. --}}
            <div class="min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                    <p class="min-w-0 truncate text-sm font-semibold text-slate-900 dark:text-white">
                        Awaiting our reply
                        @if ($openTicketTotal > 0)
                            <span class="ms-1.5 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ $openTicketTotal }}</span>
                        @endif
                    </p>
                    <a href="{{ route('admin.support.index', ['status' => 'open']) }}" class="flex-none text-xs font-semibold text-orange-600 hover:underline">All &rarr;</a>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($openTickets as $t)
                        <li>
                            {{-- Whole row is the tap target: on a phone a small
                                 title-only link is easy to miss. --}}
                            <a href="{{ route('admin.support.show', $t) }}"
                               class="group block px-4 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800">
                                <p class="text-sm font-medium text-slate-900 group-hover:text-orange-600 dark:text-white">{{ $t->subject }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                    <span class="truncate">{{ $t->user?->name ?: $t->user?->email ?: '—' }}</span>
                                    @if ($t->website)
                                        <span class="text-slate-300 dark:text-slate-600">·</span>
                                        <span class="truncate">{{ $t->website->domain }}</span>
                                    @endif
                                    <span class="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300">
                                        {{ $t->last_reply_at?->diffForHumans(null, true) }} waiting
                                    </span>
                                </div>
                            </a>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-slate-400">Nothing waiting — every ticket has been answered.</li>
                    @endforelse
                </ul>
            </div>

            {{-- Client article verdicts nobody has looked at. "Mark as seen"
                 only clears it from here; the full list keeps every row. --}}
            <div class="min-w-0 rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                    <p class="min-w-0 truncate text-sm font-semibold text-slate-900 dark:text-white">
                        New article feedback
                        @if ($feedbackTotal > 0)
                            <span class="ms-1.5 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-700 dark:bg-orange-950 dark:text-orange-300">{{ $feedbackTotal }}</span>
                        @endif
                    </p>
                    <a href="{{ route('admin.content-feedback.index') }}" class="flex-none text-xs font-semibold text-orange-600 hover:underline">All &rarr;</a>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($feedback as $f)
                        @php
                            $tone = match ($f->rating) {
                                \App\Models\ContentArticleFeedback::RATING_WRONG => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
                                \App\Models\ContentArticleFeedback::RATING_REWRITES => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                                default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
                            };
                            // Straight to THE article in the admin's own
                            // read-only view. The client's route is scoped to
                            // accessible websites and admins are not
                            // special-cased there, hence a dedicated view.
                            $target = $f->topic_id
                                ? route('admin.content.article', $f->topic_id)
                                : route('admin.content-feedback.index');
                        @endphp
                        <li>
                            {{-- The link and the form are SIBLINGS: a <form>
                                 nested inside an <a> is invalid and the button
                                 would stop submitting. --}}
                            <a href="{{ $target }}" class="group block px-4 pt-3 transition hover:bg-slate-50 dark:hover:bg-slate-800">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="flex-none rounded-full px-2 py-0.5 text-[11px] font-bold {{ $tone }}">{{ \App\Models\ContentArticleFeedback::label($f->rating) }}</span>
                                    <span class="min-w-0 truncate text-xs text-slate-500 dark:text-slate-400">{{ $f->website?->domain ?? '—' }}</span>
                                </div>
                                <p class="mt-1.5 text-sm font-medium text-slate-900 group-hover:text-orange-600 dark:text-white">{{ $f->topic?->title ?? '(article removed)' }}</p>
                                @if (trim((string) $f->comment) !== '')
                                    <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-slate-600 dark:text-slate-300">“{{ $f->comment }}”</p>
                                @endif
                                {{-- The instruction that actually ran. Differs
                                     from the note above whenever the client
                                     accepted the sharpened version, so showing
                                     only the note hid what we were really
                                     asked to do. --}}
                                @php $req = ($feedbackPrompts ?? collect())[$f->topic_id.':'.$f->user_id] ?? null; @endphp
                                @if ($req && trim((string) $req->prompt) !== '')
                                    <p class="mt-1.5 line-clamp-2 rounded border border-slate-200 bg-slate-50 px-2 py-1 text-xs leading-relaxed text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
                                        <span class="font-bold uppercase tracking-wider text-slate-400">Rewrite asked:</span>
                                        {{ $req->prompt }}
                                    </p>
                                @endif
                                <p class="mt-1 truncate text-xs text-slate-400">{{ $f->user?->email ?? '—' }} · {{ $f->created_at?->diffForHumans() }}</p>
                            </a>
                            <div class="px-4 pb-3 pt-2">
                                <form method="POST" action="{{ route('admin.content-feedback.seen', $f) }}">
                                    @csrf
                                    <input type="hidden" name="back" value="{{ route('admin.dashboard') }}">
                                    {{-- Full-width on a phone so it is a real
                                         thumb target, shrink-to-fit from sm:. --}}
                                    <button type="submit"
                                            class="w-full rounded-lg border border-slate-300 px-3 py-3 text-xs font-semibold text-slate-600 transition hover:border-orange-400 hover:text-orange-600 sm:w-auto dark:border-slate-700 dark:text-slate-300">
                                        Mark seen
                                    </button>
                                </form>
                            </div>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-slate-400">No new feedback — all caught up.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        {{-- ── Feeds ─────────────────────────────────────────────── --}}
        <div class="grid gap-3 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Latest signups</p>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($recentSignups as $u)
                        <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900 dark:text-white">{{ $u->name ?: $u->email }}</p>
                                <p class="truncate text-xs text-slate-500">{{ $u->email }}</p>
                            </div>
                            <div class="shrink-0 text-end">
                                @php $tone = ['paid' => 'bg-emerald-100 text-emerald-800', 'trial' => 'bg-orange-100 text-orange-800', 'free' => 'bg-slate-100 text-slate-600']; @endphp
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase {{ $tone[$u->segment_label] }}">{{ $u->segment_label }}</span>
                                <p class="mt-0.5 text-[11px] text-slate-400">{{ $u->created_at->diffForHumans(short: true) }}</p>
                            </div>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-slate-400">No signups yet.</li>
                    @endforelse
                </ul>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Active subscriptions</p>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($recentSubscriptions as $s)
                        <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900 dark:text-white">
                                    {{ $s->name ?: $s->email }}
                                    @if ($s->is_admin)
                                        <span class="ms-1 rounded bg-slate-100 px-1 py-px text-[9px] font-bold uppercase text-slate-500 dark:bg-slate-800">internal</span>
                                    @endif
                                </p>
                                <p class="truncate text-xs text-slate-500">{{ $s->sub_type === 'content' ? 'Content Autopilot' : 'SEO platform' }} · {{ $s->sub_status }}</p>
                            </div>
                            <p class="shrink-0 text-[11px] text-slate-400">{{ \Illuminate\Support\Carbon::parse($s->subscribed_at)->diffForHumans(short: true) }}</p>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-slate-400">No active subscriptions.</li>
                    @endforelse
                </ul>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">Recent payments</p>
                    <span class="text-[10px] text-slate-400">via Stripe, ~10 min delay</span>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($stripe['recent'] as $p)
                        <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-slate-900 dark:text-white">{{ $p['email'] ?? 'unknown' }}</p>
                                <p class="text-[11px] text-slate-400">{{ $p['at']?->diffForHumans(short: true) ?? '—' }}</p>
                            </div>
                            <div class="shrink-0 text-end">
                                <p class="font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">{{ $money($p['amount'], $p['currency']) }}</p>
                                @if ($p['url'])
                                    <a href="{{ $p['url'] }}" target="_blank" rel="noopener" class="text-[11px] font-semibold text-orange-600 hover:underline">Invoice</a>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-slate-400">
                            {{ $stripe['available'] ? 'No payments yet this month.' : 'Stripe unavailable.' }}
                        </li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-layouts.app>
