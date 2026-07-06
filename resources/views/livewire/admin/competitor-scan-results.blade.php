<div>
    @if ($scan === null)
        <p class="text-xs text-slate-400">Scan not found.</p>
    @elseif (in_array($scan->status, ['queued', 'running', 'cancelling'], true))
        <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-300">
            Results appear here once the scan finishes. Status: <span class="font-mono">{{ $scan->status }}</span>.
        </div>
    @elseif ($scan->page_count === 0)
        @php
            $stats = $scan->progress['scrapy_stats'] ?? [];
            $robotsBlocked = (int) ($stats['robotstxt/forbidden'] ?? 0);
            $responseCount = (int) ($stats['downloader/response_count'] ?? 0);
            $retries = (int) ($stats['retry/count'] ?? 0);
            $finishReason = (string) ($stats['finish_reason'] ?? $scan->progress['finish_reason'] ?? '—');
            $statusBreakdown = collect($stats)
                ->filter(fn ($v, $k) => str_starts_with($k, 'downloader/response_status_count/'))
                ->mapWithKeys(fn ($v, $k) => [str_replace('downloader/response_status_count/', '', $k) => (int) $v])
                ->sortKeys();
            $hint = match (true) {
                $robotsBlocked > 0 && $responseCount === 0 => 'robots.txt blocked every request — the site disallows our crawler entirely.',
                $robotsBlocked > 0 => 'robots.txt blocked some paths. Consider seeding a different starting URL on this domain.',
                $statusBreakdown->has(403) => 'Site returned 403 Forbidden. Likely WAF / anti-bot. Try the marketing root URL or a different user-agent.',
                $statusBreakdown->has(503) || $statusBreakdown->has(429) => 'Site rate-limited or blocked our requests. Re-run after a delay or lower request rate.',
                $responseCount > 0 && ! $statusBreakdown->has(200) => 'Site responded but never with HTTP 200. Likely all redirects off-domain or all errors.',
                $responseCount === 0 => 'No HTTP response received. DNS / firewall / robots-txt block at network level.',
                default => 'Pages were fetched but extraction yielded no items — likely JS-rendered content (Playwright comes in v2).',
            };
        @endphp
        <div class="space-y-3">
            <div class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                <p><strong>Scan finished without any pages.</strong> {{ $hint }}</p>
                <p class="mt-1 text-[11px]">Finish reason: <span class="font-mono">{{ $finishReason }}</span></p>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                <div class="text-xs font-semibold uppercase text-slate-500">Crawl diagnostics</div>
                <dl class="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
                    <div>
                        <dt class="text-slate-500">Responses received</dt>
                        <dd class="font-semibold tabular-nums">{{ number_format($responseCount) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">robots.txt forbidden</dt>
                        <dd class="font-semibold tabular-nums">{{ number_format($robotsBlocked) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Retries</dt>
                        <dd class="font-semibold tabular-nums">{{ number_format($retries) }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Elapsed</dt>
                        <dd class="font-semibold tabular-nums">{{ number_format((float) ($stats['elapsed_time_seconds'] ?? 0), 1) }}s</dd>
                    </div>
                </dl>

                @if ($statusBreakdown->isNotEmpty())
                    <div class="mt-3">
                        <div class="text-[11px] font-semibold uppercase text-slate-500">HTTP status breakdown</div>
                        <ul class="mt-1 flex flex-wrap gap-1.5 text-xs">
                            @foreach ($statusBreakdown as $code => $count)
                                @php
                                    $tone = match (true) {
                                        $code === 200 || $code === '200' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200',
                                        in_array((int) $code, [301, 302], true) => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                                        (int) $code >= 400 => 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-200',
                                        default => 'bg-slate-100 text-slate-700',
                                    };
                                @endphp
                                <li class="rounded-full px-2.5 py-1 {{ $tone }}">
                                    {{ $code }} <span class="text-[10px] opacity-70">×{{ $count }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="space-y-6">

            {{-- Stats tiles --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @php
                    $tiles = [
                        ['label' => 'Pages', 'value' => $scan->page_count],
                        ['label' => 'External pages', 'value' => $scan->external_page_count],
                        ['label' => 'Topics', 'value' => $topics->count()],
                        ['label' => 'Keywords', 'value' => $topKeywords->count()],
                    ];
                @endphp
                @foreach ($tiles as $tile)
                    <div class="rounded-lg border border-slate-200 bg-white p-3 dark:border-slate-800 dark:bg-slate-950">
                        <div class="text-[10px] uppercase tracking-wider text-slate-500">{{ $tile['label'] }}</div>
                        <div class="mt-1 text-2xl font-bold tabular-nums">{{ number_format($tile['value']) }}</div>
                    </div>
                @endforeach
            </div>

            {{-- Per-seed-keyword competitor rankings --}}
            @if ($seedRankings->isNotEmpty())
                <div class="rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div class="border-b border-slate-200 px-4 py-2 text-xs font-semibold uppercase text-slate-500 dark:border-slate-800">Pages targeting your seed keywords</div>
                    <div class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        @foreach ($seedRankings as $row)
                            <div class="px-4 py-3">
                                <div class="flex items-center justify-between">
                                    <div class="text-sm font-medium">{{ $row['phrase'] }}</div>
                                    <div class="text-[11px] text-slate-500">{{ number_format($row['total_occurrences']) }} occurrence(s) across all pages</div>
                                </div>
                                @if (! empty($row['top_pages']))
                                    <ul class="mt-2 space-y-1 text-xs">
                                        @foreach ($row['top_pages'] as $entry)
                                            @if ($entry['page'])
                                                <li class="flex items-center justify-between gap-3">
                                                    <a href="{{ $entry['page']->url }}" target="_blank" rel="noopener" class="flex-1 truncate text-orange-600 hover:underline dark:text-orange-400">{{ $entry['page']->title ?: $entry['page']->url }}</a>
                                                    <span class="tabular-nums text-slate-500">{{ $entry['occurrences'] }}× · {{ number_format($entry['density'] * 100, 3) }}%</span>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Top topics --}}
            @if ($topics->isNotEmpty())
                <div class="rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div class="border-b border-slate-200 px-4 py-2 text-xs font-semibold uppercase text-slate-500 dark:border-slate-800">Topics discovered</div>
                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-900">
                            <tr>
                                <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase text-slate-500">Topic</th>
                                <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase text-slate-500">Top phrases</th>
                                <th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-slate-500">Pages</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                            @foreach ($topics as $topic)
                                @php
                                    $phrases = collect((array) $topic->top_keyword_ids)
                                        ->map(fn ($kid) => $keywordPhrases[$kid] ?? null)
                                        ->filter()
                                        ->take(5);
                                @endphp
                                <tr>
                                    <td class="px-3 py-2 font-medium">{{ $topic->name }}</td>
                                    <td class="px-3 py-2 text-xs text-slate-500">{{ $phrases->implode(' · ') ?: '—' }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ $topic->page_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Top extracted keywords --}}
            @if ($topKeywords->isNotEmpty())
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div class="text-xs font-semibold uppercase text-slate-500">Top extracted keywords</div>
                    <ul class="mt-3 flex flex-wrap gap-1.5">
                        @foreach ($topKeywords as $row)
                            <li class="rounded-full bg-slate-100 px-2.5 py-1 text-xs dark:bg-slate-800">
                                {{ $row['phrase'] }}
                                <span class="ml-1 text-[10px] text-slate-500">×{{ $row['weight'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Backlinks: cross-scan inverted view --}}
            @if ($backlinksSummary && $backlinksSummary['total_links'] > 0)
                <div class="rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-2 dark:border-slate-800">
                        <span class="text-xs font-semibold uppercase text-slate-500">Backlinks (from our other scans)</span>
                        <span class="text-[11px] text-slate-500">
                            {{ number_format($backlinksSummary['total_links']) }} link(s) ·
                            {{ number_format($backlinksSummary['referring_domains']) }} referring domain(s)
                        </span>
                    </div>

                    @if ($backlinksSummary['anchors']->isNotEmpty())
                        <div class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                            <div class="text-[11px] font-semibold uppercase text-slate-500">Anchor text distribution</div>
                            <ul class="mt-2 flex flex-wrap gap-1.5 text-xs">
                                @foreach ($backlinksSummary['anchors'] as $row)
                                    <li class="rounded-full bg-slate-100 px-2.5 py-1 dark:bg-slate-800">
                                        {{ $row->anchor_text }}
                                        <span class="ml-1 text-[10px] text-slate-500">×{{ $row->link_count }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                        <thead class="bg-slate-50 dark:bg-slate-900">
                            <tr>
                                <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase text-slate-500">Linking page</th>
                                <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase text-slate-500">Anchor</th>
                                <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase text-slate-500">From scan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                            @foreach ($backlinksSummary['linking_pages'] as $row)
                                <tr>
                                    <td class="px-3 py-2">
                                        <a href="{{ $row->from_url }}" target="_blank" rel="noopener" class="block truncate text-orange-600 hover:underline dark:text-orange-400">{{ $row->from_title ?: $row->from_url }}</a>
                                        <p class="text-[11px] text-slate-500">{{ $row->from_domain }}</p>
                                    </td>
                                    <td class="px-3 py-2 text-xs text-slate-600">{{ $row->anchor_text ?: '—' }}</td>
                                    <td class="px-3 py-2 text-xs text-slate-500">
                                        {{ $row->scan_seed }}
                                        @if ($row->scanned_at)
                                            · {{ \Illuminate\Support\Carbon::parse($row->scanned_at)->diffForHumans() }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Top external domains --}}
            @if ($topExternalDomains->isNotEmpty())
                <div class="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-950">
                    <div class="text-xs font-semibold uppercase text-slate-500">Most-linked external domains</div>
                    <ul class="mt-3 grid grid-cols-1 gap-1 text-xs sm:grid-cols-2">
                        @foreach ($topExternalDomains as $row)
                            <li class="flex items-center justify-between rounded-md bg-slate-50 px-2.5 py-1 dark:bg-slate-800/60">
                                <span class="truncate font-mono">{{ $row->to_domain }}</span>
                                <span class="tabular-nums text-slate-500">{{ $row->link_count }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Page list (top 50 by word count) --}}
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
                <div class="border-b border-slate-200 px-4 py-2 text-xs font-semibold uppercase text-slate-500 dark:border-slate-800">Top pages by word count</div>
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-900">
                        <tr>
                            <th class="px-3 py-2 text-left text-[11px] font-semibold uppercase text-slate-500">Page</th>
                            <th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-slate-500">Words</th>
                            <th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-slate-500">Depth</th>
                            <th class="px-3 py-2 text-right text-[11px] font-semibold uppercase text-slate-500">Source</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                        @foreach ($pages as $page)
                            <tr>
                                <td class="px-3 py-2">
                                    <a href="{{ $page->url }}" target="_blank" rel="noopener" class="block truncate text-orange-600 hover:underline dark:text-orange-400">{{ $page->title ?: $page->url }}</a>
                                    @if ($page->meta_description)
                                        <p class="mt-0.5 truncate text-[11px] text-slate-500">{{ \Illuminate\Support\Str::limit($page->meta_description, 140) }}</p>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($page->word_count) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $page->depth }}</td>
                                <td class="px-3 py-2 text-right text-xs">
                                    @if ($page->is_external)
                                        <span class="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-700 dark:bg-amber-900/30 dark:text-amber-200">External</span>
                                    @else
                                        <span class="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-200">Seed</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        </div>
    @endif
</div>
