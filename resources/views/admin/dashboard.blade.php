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
             response costs a customer, unlike the signup/subscription feeds
             which are just information. --}}
        <div class="grid gap-3 lg:grid-cols-2">
            {{-- Unreplied support tickets. "open" IS the unreplied state — the
                 whose-turn tracker flips to "answered" the moment we reply. --}}
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">
                        Awaiting our reply
                        @if ($openTicketTotal > 0)
                            <span class="ms-1.5 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">{{ $openTicketTotal }}</span>
                        @endif
                    </p>
                    <a href="{{ route('admin.support.index', ['status' => 'open']) }}" class="text-xs font-semibold text-orange-600 hover:underline">All tickets &rarr;</a>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($openTickets as $t)
                        <li class="px-4 py-2.5 text-sm">
                            <a href="{{ route('admin.support.show', $t) }}" class="flex items-start justify-between gap-3 group">
                                <div class="min-w-0">
                                    <p class="truncate font-medium text-slate-900 group-hover:text-orange-600 dark:text-white">{{ $t->subject }}</p>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">
                                        {{ $t->user?->name ?: $t->user?->email ?: '—' }}@if ($t->website) · {{ $t->website->domain }}@endif
                                    </p>
                                </div>
                                <span class="flex-none whitespace-nowrap text-xs text-slate-400" title="{{ $t->last_reply_at }}">
                                    {{ $t->last_reply_at?->diffForHumans(null, true) }} waiting
                                </span>
                            </a>
                        </li>
                    @empty
                        <li class="px-4 py-6 text-center text-sm text-slate-400">Nothing waiting — every ticket has been answered.</li>
                    @endforelse
                </ul>
            </div>

            {{-- Client article verdicts nobody has looked at. "Mark as seen"
                 only clears it from here; the full list keeps every row. --}}
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">
                        New article feedback
                        @if ($feedbackTotal > 0)
                            <span class="ms-1.5 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-700 dark:bg-orange-950 dark:text-orange-300">{{ $feedbackTotal }}</span>
                        @endif
                    </p>
                    <a href="{{ route('admin.content-feedback.index') }}" class="text-xs font-semibold text-orange-600 hover:underline">All feedback &rarr;</a>
                </div>
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($feedback as $f)
                        @php
                            $tone = match ($f->rating) {
                                \App\Models\ContentArticleFeedback::RATING_WRONG => 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
                                \App\Models\ContentArticleFeedback::RATING_REWRITES => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                                default => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
                            };
                        @endphp
                        <li class="px-4 py-2.5 text-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="flex items-center gap-2">
                                        <span class="flex-none rounded-full px-2 py-0.5 text-[11px] font-bold {{ $tone }}">{{ \App\Models\ContentArticleFeedback::label($f->rating) }}</span>
                                        <span class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $f->website?->domain ?? '—' }}</span>
                                    </p>
                                    <p class="mt-1 truncate font-medium text-slate-900 dark:text-white">{{ $f->topic?->title ?? '(article removed)' }}</p>
                                    @if (trim((string) $f->comment) !== '')
                                        <p class="mt-0.5 line-clamp-2 text-xs text-slate-600 dark:text-slate-300">“{{ $f->comment }}”</p>
                                    @endif
                                    <p class="mt-0.5 text-xs text-slate-400">{{ $f->user?->email ?? '—' }} · {{ $f->created_at?->diffForHumans() }}</p>
                                </div>
                                <form method="POST" action="{{ route('admin.content-feedback.seen', $f) }}" class="flex-none">
                                    @csrf
                                    <input type="hidden" name="back" value="{{ route('admin.dashboard') }}">
                                    <button type="submit"
                                            class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-600 transition hover:border-orange-400 hover:text-orange-600 dark:border-slate-700 dark:text-slate-300">
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
