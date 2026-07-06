<div class="space-y-5">
    <div>
        <h1 class="text-lg font-bold tracking-tight">Link Structure</h1>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">Type a page URL to see how it's linked within your site</p>
    </div>

    {{-- URL input --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
        <form wire:submit="analyze" class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <div class="flex-1">
                <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">Page URL</label>
                <input wire:model="pageUrl" type="url" list="lsp-examples" placeholder="https://example.com/page"
                    class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                <datalist id="lsp-examples">
                    @foreach ($examples as $ex)
                        <option value="{{ $ex }}"></option>
                    @endforeach
                </datalist>
            </div>
            <button type="submit" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">
                <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>
                Show links
            </button>
        </form>
    </div>

    @if ($notFound)
        <div class="rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">{{ $notFound }}</div>
    @endif

    @if ($structure)
        @php
            $pg = $structure['page'];
            $issueTones = [
                'critical' => ['bg' => 'bg-rose-50 dark:bg-rose-500/5', 'border' => 'border-rose-200 dark:border-rose-500/20', 'badge' => 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300'],
                'high' => ['bg' => 'bg-amber-50 dark:bg-amber-500/5', 'border' => 'border-amber-200 dark:border-amber-500/20', 'badge' => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300'],
                'medium' => ['bg' => 'bg-blue-50 dark:bg-blue-500/5', 'border' => 'border-blue-200 dark:border-blue-500/20', 'badge' => 'bg-blue-100 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300'],
                'low' => ['bg' => 'bg-slate-50 dark:bg-slate-800/40', 'border' => 'border-slate-200 dark:border-slate-700', 'badge' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'],
            ];
        @endphp

        {{-- Page Health: every open issue for this URL, with concrete fix steps.
             This is the universal "Fix" destination for every finding type in the
             crawl report — not just link-structure ones — so it carries its own
             context per issue instead of assuming the visitor already knows why
             they're here. --}}
        <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
            <div class="border-b border-slate-100 px-4 py-2.5 dark:border-slate-800">
                <div class="text-[13px] font-semibold">Page Health</div>
                <p class="text-[11px] text-slate-400">Every open issue found on this page, with what to do about it.</p>
            </div>
            @if ($issues === [])
                <div class="px-4 py-8 text-center text-[12px] text-emerald-700 dark:text-emerald-400">✓ No open issues for this page.</div>
            @else
                <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($issues as $issue)
                        @php
                            $tone = $issueTones[$issue['severity']] ?? $issueTones['low'];
                            $isHighlighted = $highlightType !== '' && $issue['type'] === $highlightType;
                            $d = $issue['detail'];
                        @endphp
                        <li class="px-4 py-3 {{ $isHighlighted ? $tone['bg'].' border-l-4 '.$tone['border'] : '' }}">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($isHighlighted)
                                    <span class="rounded bg-orange-600 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">You're here</span>
                                @endif
                                <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $tone['badge'] }}">{{ $issue['severity'] }}</span>
                                <span class="text-[13px] font-semibold text-slate-900 dark:text-slate-100">{{ $issue['label'] }}</span>
                                @if ($issue['gsc_sourced'])
                                    <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300" title="From Search Console history, which can lag the live site by a few days">From Search Console</span>
                                @endif
                            </div>
                            <p class="mt-1 text-[12px] text-slate-600 dark:text-slate-300">
                                @if ($issue['is_outbound'])
                                    This page links to <span class="font-medium">{{ $issue['affected_url'] }}</span> — {{ $issue['description'] }}.
                                @else
                                    {{ $issue['description'] }}.
                                @endif
                            </p>
                            <p class="mt-1.5 flex gap-1.5 text-[12px] text-slate-700 dark:text-slate-200">
                                <span aria-hidden="true">→</span><span>{{ $issue['guidance'] }}</span>
                            </p>

                            {{-- Type-specific supporting context, straight from the finding's detail. --}}
                            @if (! empty($d['other_urls']))
                                <div class="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                                    Also affected:
                                    @foreach (array_slice($d['other_urls'], 0, 5) as $u)
                                        <a href="{{ $u }}" target="_blank" rel="noopener" class="text-orange-600 hover:underline dark:text-orange-400">{{ $u }}</a>@if (! $loop->last), @endif
                                    @endforeach
                                    @if (count($d['other_urls']) > 5) (+{{ count($d['other_urls']) - 5 }} more) @endif
                                </div>
                            @endif
                            @if (! empty($d['hreflangs']))
                                <div class="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                                    Declared hreflang alternates:
                                    @foreach ($d['hreflangs'] as $h)
                                        <span class="ml-1 rounded bg-slate-100 px-1 py-0.5 font-mono dark:bg-slate-800">{{ $h['hreflang'] ?? '?' }}</span>
                                    @endforeach
                                </div>
                            @endif
                            @if (! empty($d['urls']))
                                <div class="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                                    @foreach (array_slice($d['urls'], 0, 5) as $u)
                                        <div class="truncate font-mono">{{ $u }}</div>
                                    @endforeach
                                    @if (count($d['urls']) > 5)<div>(+{{ count($d['urls']) - 5 }} more)</div>@endif
                                </div>
                            @endif
                            @if (! empty($d['redirect_target']))
                                <div class="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">Redirects to: <span class="font-mono">{{ $d['redirect_target'] }}</span></div>
                            @endif
                            @if (! empty($d['canonical']))
                                <div class="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">Canonical points to: <span class="font-mono">{{ $d['canonical'] }}</span></div>
                            @endif
                            @if (! empty($d['path']))
                                <div class="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">Blocked path: <span class="font-mono">{{ $d['path'] }}</span></div>
                            @endif
                            @if (! empty($d['referrers']))
                                <div class="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                                    Linked from:
                                    @foreach (array_slice($d['referrers'], 0, 5) as $r)
                                        <a href="{{ $r['url'] }}" target="_blank" rel="noopener" class="text-orange-600 hover:underline dark:text-orange-400">{{ $r['url'] }}</a>@if (! $loop->last), @endif
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        {{-- Focus page summary --}}
        <div class="rounded-xl border border-orange-200 bg-orange-50/50 p-4 dark:border-orange-500/20 dark:bg-orange-500/5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="min-w-0">
                    <a href="{{ $pg['url'] }}" target="_blank" rel="noopener" class="block truncate text-sm font-semibold text-slate-900 hover:underline dark:text-slate-100" title="{{ $pg['url'] }}">{{ $pg['title'] ?: $pg['url'] }}</a>
                    <div class="truncate text-[11px] text-slate-500">{{ $pg['url'] }}</div>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-[11px]">
                    <span><span class="font-semibold text-slate-900 dark:text-slate-100">{{ $pg['inbound_count'] }}</span> in</span>
                    <span><span class="font-semibold text-slate-900 dark:text-slate-100">{{ $pg['outbound_count'] }}</span> out</span>
                    <span>depth <span class="font-semibold text-slate-900 dark:text-slate-100">{{ $pg['click_depth'] ?? '—' }}</span></span>
                    <span>status <span class="font-semibold {{ ($pg['http_status'] ?? 0) >= 400 ? 'text-red-600' : 'text-slate-900 dark:text-slate-100' }}">{{ $pg['http_status'] ?? '—' }}</span></span>
                    @if (! $pg['is_indexable'])<span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">noindex</span>@endif
                </div>
            </div>
            @php
                $via = [];
                if ($pg['inbound_count'] > 0) { $via[] = $pg['inbound_count'].' internal link'.($pg['inbound_count'] === 1 ? '' : 's'); }
                if (! empty($pg['source_sitemap'])) { $via[] = 'sitemap'; }
                if (! empty($pg['source_gsc'])) { $via[] = 'Search Console'; }
            @endphp
            @if ($via !== [])
                <div class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">Discovered via: {{ implode(' · ', $via) }}</div>
            @endif
            @if ($pg['crawl_running'])
                <div class="mt-2 rounded bg-slate-100 px-2 py-1 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">⏳ A crawl is still running — inbound-link counts finish calculating once it completes.</div>
            @elseif (($pg['http_status'] ?? 0) >= 400)
                <div class="mt-2 rounded bg-red-100/70 px-2 py-1 text-[11px] text-red-800 dark:bg-red-900/30 dark:text-red-200">
                    ⚠ This page returns {{ $pg['http_status'] }}.
                    @if ($pg['inbound_count'] > 0)
                        It's linked from {{ $pg['inbound_count'] }} internal page{{ $pg['inbound_count'] === 1 ? '' : 's' }} (listed below) — fix or remove those links.
                    @elseif (! empty($pg['source_sitemap']))
                        It's listed in your sitemap but nothing on the site links to it — remove it from the sitemap, or restore/redirect the page.
                    @elseif (! empty($pg['source_gsc']))
                        Google still has it indexed (Search Console history) but nothing on the site links to it — add a redirect or return 410.
                    @else
                        Nothing on the site links to it — restore the page, add a redirect, or return 410.
                    @endif
                </div>
            @elseif ($pg['is_homepage'])
                <div class="mt-2 rounded bg-slate-100 px-2 py-1 text-[11px] text-slate-600 dark:bg-slate-800 dark:text-slate-300">This is the homepage (the root of your site) — it doesn't need inbound internal links.</div>
            @elseif ($pg['inbound_count'] === 0)
                <div class="mt-2 rounded bg-amber-100/70 px-2 py-1 text-[11px] text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">⚠ This page is an orphan — no internal links point to it.</div>
            @endif
        </div>

        {{-- Click-path tree: homepage → … → this page (its depth) --}}
        @php
            $segLabel = function (?string $u) {
                $p = (string) parse_url((string) $u, PHP_URL_PATH);
                return $p !== '' && $p !== '/' ? $p : '/';
            };
        @endphp
        <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
            <div class="text-[13px] font-semibold">Path from homepage</div>
            <p class="mb-2 text-[11px] text-slate-400">The shortest internal-link click path to this page — each step down is one click deeper.</p>
            @if (count($structure['path']) > 0)
                <ul class="space-y-1">
                    @foreach ($structure['path'] as $i => $step)
                        <li class="flex items-center text-[12px]" style="padding-left: {{ $i * 20 }}px">
                            @if ($i > 0)
                                <span class="mr-1.5 select-none text-slate-300 dark:text-slate-600">└─</span>
                            @endif
                            <span class="mr-1.5 flex h-5 min-w-[20px] items-center justify-center rounded {{ $step['is_current'] ? 'bg-orange-600 text-white' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300' }} px-1 text-[10px] font-bold">{{ $i }}</span>
                            <a href="{{ $step['url'] }}" target="_blank" rel="noopener"
                                class="truncate {{ $step['is_current'] ? 'font-semibold text-orange-700 dark:text-orange-300' : 'text-slate-700 hover:underline dark:text-slate-200' }}"
                                title="{{ $step['url'] }}">{{ $i === 0 ? '🏠 '.$segLabel($step['url']) : $segLabel($step['url']) }}</a>
                            @if ($step['is_current'])<span class="ml-1.5 whitespace-nowrap text-[10px] text-slate-400">this page · depth {{ $pg['click_depth'] ?? count($structure['path']) - 1 }}</span>@endif
                        </li>
                    @endforeach
                </ul>
            @elseif ($pg['crawl_running'])
                <p class="text-[12px] text-slate-500 dark:text-slate-400">The crawl is still running — the path will appear once it completes.</p>
            @elseif ($pg['is_homepage'])
                <p class="text-[12px] text-slate-500 dark:text-slate-400">🏠 This is the homepage — depth 0, the root of the tree.</p>
            @else
                <p class="text-[12px] text-amber-700 dark:text-amber-300">No path from the homepage reaches this page by internal links — it's disconnected (orphaned), so Google can only find it via the sitemap/external links.</p>
            @endif
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            {{-- Inbound --}}
            <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-100 px-4 py-2 text-[13px] font-semibold dark:border-slate-800">Links to this page <span class="text-[11px] font-normal text-slate-400">({{ count($structure['inbound']) }})</span></div>
                <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                    @forelse ($structure['inbound'] as $l)
                        <div class="px-4 py-1.5">
                            <a href="{{ $l['url'] }}" target="_blank" rel="noopener" class="block truncate text-[12px] text-orange-600 hover:underline dark:text-orange-400" title="{{ $l['url'] }}">{{ $l['url'] }}</a>
                            @if ($l['anchor'])<div class="truncate text-[11px] text-slate-400">anchor: "{{ $l['anchor'] }}"</div>@endif
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-[11px] text-slate-400">No internal links point to this page.</div>
                    @endforelse
                </div>
            </div>

            {{-- Outbound --}}
            <div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="border-b border-slate-100 px-4 py-2 text-[13px] font-semibold dark:border-slate-800">Links from this page <span class="text-[11px] font-normal text-slate-400">({{ count($structure['outbound']) }})</span></div>
                <div class="max-h-80 divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                    @forelse ($structure['outbound'] as $l)
                        <div class="flex items-start justify-between gap-2 px-4 py-1.5">
                            <div class="min-w-0">
                                <a href="{{ $l['url'] }}" target="_blank" rel="noopener" class="block truncate text-[12px] {{ $l['broken'] ? 'text-red-600' : 'text-orange-600 dark:text-orange-400' }} hover:underline" title="{{ $l['url'] }}">{{ $l['url'] }}</a>
                                @if ($l['anchor'])<div class="truncate text-[11px] text-slate-400">anchor: "{{ $l['anchor'] }}"</div>@endif
                            </div>
                            @if ($l['broken'])<span class="whitespace-nowrap rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700 dark:bg-red-900/40 dark:text-red-300">{{ $l['status'] }}</span>@endif
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-[11px] text-slate-400">This page has no internal links.</div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Suggested links --}}
        @if ($structure['suggested_inbound'] !== [])
            <div class="rounded-xl border border-emerald-200 bg-emerald-50/40 dark:border-emerald-500/20 dark:bg-emerald-500/5">
                <div class="border-b border-emerald-100 px-4 py-2 text-[13px] font-semibold text-emerald-800 dark:border-emerald-500/20 dark:text-emerald-300">Suggested internal links to add (from related pages)</div>
                <div class="divide-y divide-emerald-100/60 dark:divide-emerald-500/10">
                    @foreach ($structure['suggested_inbound'] as $l)
                        <div class="px-4 py-1.5">
                            <span class="text-[11px] text-slate-500">Add a link from</span>
                            <a href="{{ $l['url'] }}" target="_blank" rel="noopener" class="text-[12px] text-orange-600 hover:underline dark:text-orange-400">{{ $l['url'] }}</a>
                            @if ($l['anchor'])<span class="text-[11px] text-slate-500">with anchor "{{ $l['anchor'] }}"</span>@endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
</div>
