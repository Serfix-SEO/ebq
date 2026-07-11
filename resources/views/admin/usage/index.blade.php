<x-layouts.app>
    @php
        /**
         * @var \Illuminate\Support\Carbon $startDate
         * @var \Illuminate\Support\Carbon $endDate
         * @var array $providers
         * @var array $rates
         * @var array $summary
         * @var array $byClient
         * @var \Illuminate\Support\Collection $users
         * @var array $byWebsite
         * @var \Illuminate\Support\Collection $websites
         * @var array $dailySeries
         * @var \Illuminate\Support\Collection $recent
         * @var string $preset
         */

        $fmtMoney = fn (float $usd) => '$' . number_format($usd, $usd >= 100 ? 0 : ($usd >= 1 ? 2 : 4));
        $fmtN = fn ($n) => number_format((int) $n);
        $providerColor = [
            'keywords_everywhere' => 'bg-amber-50 text-amber-700 border-amber-200',
            'serp_api' => 'bg-orange-50 text-orange-700 border-orange-200',
            'mistral'  => 'bg-purple-50 text-purple-700 border-purple-200',
            'deepseek' => 'bg-sky-50 text-sky-700 border-sky-200',
        ];

        // Build sparkline path for a series.
        $sparkPath = function (array $values, int $w = 240, int $h = 36): string {
            if (count($values) < 2) return '';
            $max = max($values) ?: 1;
            $step = $w / max(1, count($values) - 1);
            $points = [];
            foreach ($values as $i => $v) {
                $x = round($i * $step, 2);
                $y = round($h - ($v / $max) * $h, 2);
                $points[] = ($i === 0 ? 'M' : 'L') . $x . ',' . $y;
            }
            return implode(' ', $points);
        };
    @endphp

    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">API usage</h1>
                <p class="text-sm text-slate-500">
                    Third-party credit consumption (Keywords Everywhere + Serper) by client and website.
                </p>
            </div>
            <div class="text-xs text-slate-500">
                {{ $startDate->format('M j, Y') }} → {{ $endDate->format('M j, Y') }}
            </div>
        </div>

        {{-- Date range + filter form --}}
        <form method="GET" class="flex flex-wrap items-end gap-2 rounded border border-slate-200 bg-white p-3">
            <div class="flex flex-wrap gap-1">
                @foreach ([['7','Last 7d'], ['30','Last 30d'], ['90','Last 90d']] as [$val, $label])
                    <a href="{{ route('admin.usage.index', ['range' => $val, 'provider' => $filters['provider'] ?: null, 'user_id' => $filters['user_id'] ?: null]) }}"
                       @class([
                           'rounded border px-3 py-1.5 text-xs font-semibold',
                           'border-orange-500 bg-orange-50 text-orange-700' => $preset === $val,
                           'border-slate-200 text-slate-600 hover:bg-slate-50' => $preset !== $val,
                       ])>{{ $label }}</a>
                @endforeach
            </div>

            <div class="flex items-end gap-2 border-l border-slate-200 pl-3">
                <label class="text-[10px] uppercase tracking-wider text-slate-500">From
                    <input type="date" name="from" value="{{ $filters['from'] }}"
                           class="block rounded border border-slate-300 px-2 py-1 text-xs" />
                </label>
                <label class="text-[10px] uppercase tracking-wider text-slate-500">To
                    <input type="date" name="to" value="{{ $filters['to'] }}"
                           class="block rounded border border-slate-300 px-2 py-1 text-xs" />
                </label>
                <input type="hidden" name="range" value="custom" />
            </div>

            <div class="flex items-end gap-2 border-l border-slate-200 pl-3">
                <label class="text-[10px] uppercase tracking-wider text-slate-500">Provider
                    <select name="provider" class="block rounded border border-slate-300 px-2 py-1 text-xs">
                        <option value="">All</option>
                        @foreach ($providers as $key => $meta)
                            <option value="{{ $key }}" @selected($filters['provider'] === $key)>{{ $meta['label'] }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-[10px] uppercase tracking-wider text-slate-500">Client
                    <select name="user_id" class="block rounded border border-slate-300 px-2 py-1 text-xs">
                        <option value="0">All</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}" @selected((string) $filters['user_id'] === $u->id)>{{ $u->email }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <button class="ml-auto rounded bg-slate-800 px-3 py-1.5 text-xs font-semibold text-white">Apply</button>
        </form>

        {{-- Summary cards --}}
        <div class="grid gap-3 md:grid-cols-3">
            @foreach ([
                ['label' => 'Selected period', 'data' => $summary['period']],
                ['label' => 'This month', 'data' => $summary['this_month']],
                ['label' => 'Lifetime', 'data' => $summary['lifetime']],
            ] as $card)
                <div class="rounded-lg border border-slate-200 bg-white p-4">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">{{ $card['label'] }}</p>
                    <div class="mt-1 flex items-baseline gap-2">
                        <span class="text-2xl font-bold tabular-nums">{{ $fmtMoney($card['data']['cost']) }}</span>
                        <span class="text-xs text-slate-500">{{ $fmtN($card['data']['units']) }} credits</span>
                    </div>
                    <div class="mt-3 space-y-1.5 text-xs">
                        @foreach ($providers as $key => $meta)
                            @php $p = $card['data']['providers'][$key] ?? ['units' => 0, 'cost' => 0]; @endphp
                            <div class="flex items-center justify-between">
                                <span class="inline-flex items-center gap-1.5">
                                    <span @class(['h-2 w-2 rounded-full', 'bg-amber-500' => $key === 'keywords_everywhere', 'bg-orange-500' => $key === 'serp_api'])></span>
                                    <span class="text-slate-600">{{ $meta['label'] }}</span>
                                </span>
                                <span class="tabular-nums text-slate-500">
                                    {{ $fmtN($p['units']) }} <span class="text-slate-400">·</span> {{ $fmtMoney($p['cost']) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Daily sparklines --}}
        @if (! empty($dailySeries['labels']))
            <div class="rounded-lg border border-slate-200 bg-white p-4">
                <p class="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Daily credits</p>
                <div class="mt-3 grid gap-3 md:grid-cols-2">
                    @foreach ($providers as $key => $meta)
                        @php
                            $vals = $dailySeries['series'][$key] ?? [];
                            $sum = array_sum($vals);
                        @endphp
                        <div class="rounded border border-slate-100 p-3">
                            <div class="flex items-baseline justify-between">
                                <span class="text-xs font-semibold text-slate-700">{{ $meta['label'] }}</span>
                                <span class="text-xs tabular-nums text-slate-500">{{ $fmtN($sum) }}</span>
                            </div>
                            <svg viewBox="0 0 240 36" class="mt-2 block h-9 w-full" preserveAspectRatio="none" aria-hidden="true">
                                <path d="{{ $sparkPath($vals) }}"
                                      fill="none"
                                      stroke="{{ $key === 'keywords_everywhere' ? '#d97706' : '#F26419' }}"
                                      stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Top clients --}}
        <div class="rounded-lg border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold">Top clients by spend</h2>
                <span class="text-[10px] uppercase tracking-wider text-slate-400">In selected period</span>
            </div>
            <div class="overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs">
                        <tr>
                            <th class="px-3 py-2 font-medium text-slate-500">Client</th>
                            @foreach ($providers as $key => $meta)
                                <th class="px-3 py-2 font-medium text-slate-500">{{ $meta['label'] }}</th>
                            @endforeach
                            <th class="px-3 py-2 text-right font-medium text-slate-500">Total cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($byClient as $row)
                            @php $u = $users[$row['user_id']] ?? null; @endphp
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2">
                                    @if ($u)
                                        <div class="font-medium text-slate-800">{{ $u->name }}</div>
                                        <div class="text-xs text-slate-500">{{ $u->email }}</div>
                                    @else
                                        <span class="text-slate-400">User #{{ $row['user_id'] }}</span>
                                    @endif
                                </td>
                                @foreach ($providers as $key => $meta)
                                    @php $p = $row['providers'][$key] ?? null; @endphp
                                    <td class="px-3 py-2">
                                        @if ($p && $p['units'] > 0)
                                            <span class="font-mono text-xs tabular-nums">{{ $fmtN($p['units']) }} {{ $meta['unit'] }}{{ $p['units'] === 1 ? '' : 's' }}</span>
                                            <div class="text-xs text-slate-500">{{ $fmtMoney($p['cost']) }} · {{ $fmtN($p['calls']) }} calls</div>
                                        @else
                                            <span class="text-slate-300">—</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-2 text-right">
                                    <span class="font-bold tabular-nums">{{ $fmtMoney($row['cost']) }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($providers) + 2 }}" class="px-3 py-8 text-center text-sm text-slate-400">No usage in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Top websites --}}
        <div class="rounded-lg border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold">Top websites by spend</h2>
                <span class="text-[10px] uppercase tracking-wider text-slate-400">In selected period</span>
            </div>
            <div class="overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs">
                        <tr>
                            <th class="px-3 py-2 font-medium text-slate-500">Website</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Owner</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-500">Credits</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-500">Total cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($byWebsite as $row)
                            @php
                                $w = $websites[$row['website_id']] ?? null;
                                $owner = $w ? ($users[$w->user_id] ?? null) : null;
                            @endphp
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2">
                                    <div class="font-medium text-slate-800">{{ $w?->domain ?? 'Website #' . $row['website_id'] }}</div>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach ($providers as $key => $meta)
                                            @php $p = $row['providers'][$key] ?? null; @endphp
                                            @if ($p)
                                                <span @class(['inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[10px] font-semibold', $providerColor[$key] ?? ''])>
                                                    {{ $meta['label'] }}: {{ $fmtN($p['units']) }}
                                                </span>
                                            @endif
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-500">{{ $owner?->email ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono text-xs tabular-nums">{{ $fmtN($row['units']) }}</td>
                                <td class="px-3 py-2 text-right">
                                    <span class="font-bold tabular-nums">{{ $fmtMoney($row['cost']) }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-8 text-center text-sm text-slate-400">No website-attributed usage in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Per-client plan-window utilisation (independent of the date
             filter — uses each user's subscription-anchored monthly
             window). Highlights clients near or over their plan cap. --}}
        @if (! empty($utilisation))
            <div class="rounded-lg border border-slate-200 bg-white">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h2 class="text-sm font-semibold">Plan utilisation this cycle</h2>
                    <span class="text-[10px] uppercase tracking-wider text-slate-400">
                        Per-user monthly window
                    </span>
                </div>
                <div class="overflow-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs">
                            <tr>
                                <th class="px-4 py-2">Client</th>
                                <th class="px-4 py-2">Window started</th>
                                <th class="px-4 py-2">KE credits</th>
                                <th class="px-4 py-2">Serper calls</th>
                                <th class="px-4 py-2">AI tokens (Mistral + DeepSeek)</th>
                                <th class="px-4 py-2">Tracked keywords</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($utilisation as $u)
                                @php
                                    $renderCell = function (array $cell) use ($fmtN) {
                                        if ($cell['limit'] === null) {
                                            return $fmtN($cell['used']) . ' / <span class="text-slate-400">∞</span>';
                                        }
                                        $pct = $cell['pct'] ?? 0;
                                        $color = $pct >= 100 ? 'text-red-700' : ($pct >= 80 ? 'text-amber-700' : 'text-slate-700');
                                        return '<span class="' . $color . '">' . $fmtN($cell['used']) . ' / ' . $fmtN($cell['limit']) . '</span> <span class="text-[10px] text-slate-400">(' . number_format($pct, 0) . '%)</span>';
                                    };
                                    $user = $users[$u['user_id']] ?? null;
                                @endphp
                                <tr>
                                    <td class="px-4 py-2">
                                        <div class="font-medium">{{ $user?->name ?? ('User #' . $u['user_id']) }}</div>
                                        <div class="text-xs text-slate-500">{{ $user?->email }}</div>
                                    </td>
                                    <td class="px-4 py-2 text-xs text-slate-500">{{ $u['window_start'] }}</td>
                                    <td class="px-4 py-2 text-xs">{!! $renderCell($u['providers']['keywords_everywhere']) !!}</td>
                                    <td class="px-4 py-2 text-xs">{!! $renderCell($u['providers']['serp_api']) !!}</td>
                                    <td class="px-4 py-2 text-xs">{!! $renderCell($u['providers']['mistral']) !!}</td>
                                    <td class="px-4 py-2 text-xs">{!! $renderCell($u['tracker']) !!}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Recent calls feed --}}
        <div class="rounded-lg border border-slate-200 bg-white">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                <h2 class="text-sm font-semibold">Recent calls</h2>
                <span class="text-[10px] uppercase tracking-wider text-slate-400">Last 50 in period</span>
            </div>
            <div class="overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs">
                        <tr>
                            <th class="px-3 py-2 font-medium text-slate-500">Time</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Provider</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Client</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Website</th>
                            <th class="px-3 py-2 text-right font-medium text-slate-500">Units</th>
                            <th class="px-3 py-2 font-medium text-slate-500">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($recent as $r)
                            @php
                                $detail = '';
                                $opTag = '';
                                if (is_array($r->meta)) {
                                    if (! empty($r->meta['operation'])) {
                                        // Friendlier labels in the feed.
                                        $opLabels = [
                                            'search_volume_lookup' => 'Search volume',
                                            'backlinks_for_domain' => 'Backlinks',
                                        ];
                                        $opTag = $opLabels[$r->meta['operation']] ?? $r->meta['operation'];
                                    }
                                    if (! empty($r->meta['query'])) $detail = '"' . $r->meta['query'] . '"';
                                    elseif (! empty($r->meta['domain'])) $detail = $r->meta['domain'];
                                    elseif (! empty($r->meta['keyword_count'])) $detail = $r->meta['keyword_count'] . ' kw';
                                }
                            @endphp
                            <tr class="border-t border-slate-100">
                                <td class="px-3 py-2 text-xs text-slate-500">{{ $r->created_at?->format('M j H:i') }}</td>
                                <td class="px-3 py-2">
                                    <span @class(['inline-flex rounded border px-1.5 py-0.5 text-[10px] font-semibold', $providerColor[$r->provider] ?? 'border-slate-200 text-slate-600'])>
                                        {{ $providers[$r->provider]['label'] ?? $r->provider }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-xs">{{ $r->user?->email ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs">{{ $r->website?->domain ?? '—' }}</td>
                                <td class="px-3 py-2 text-right font-mono text-xs tabular-nums">{{ $r->units_consumed ?? '—' }}</td>
                                <td class="px-3 py-2 text-xs text-slate-500">
                                    @if ($opTag)
                                        <span class="mr-1 inline-flex rounded border border-slate-200 bg-slate-50 px-1.5 py-px text-[10px] font-semibold text-slate-600">{{ $opTag }}</span>
                                    @endif
                                    {{ $detail }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-8 text-center text-sm text-slate-400">No calls in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Cost rates note --}}
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] text-slate-500">
            Cost estimates use:
            <span class="ml-2 font-mono">KE = ${{ number_format($rates['keywords_everywhere'], 6) }} / keyword</span>
            <span class="ml-3 font-mono">Serper = ${{ number_format($rates['serp_api'], 6) }} / call</span>
            — adjust via <code>SERPER_COST_PER_CALL_USD</code> and <code>KEYWORDS_EVERYWHERE_COST_PER_KEYWORD_USD</code>.
        </div>
    </div>
</x-layouts.app>
