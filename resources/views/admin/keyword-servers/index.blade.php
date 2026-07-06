<x-layouts.app>
    @php
        /**
         * @var \Illuminate\Support\Collection<\App\Models\KeywordApiServer> $servers
         * @var string $editId
         * @var bool $showCreate
         */
        $relTime = function ($when): string {
            if (! $when) return 'never';
            try { return \Illuminate\Support\Carbon::parse($when)->diffForHumans(); }
            catch (\Throwable) { return '—'; }
        };
    @endphp

    <div class="space-y-5">
        {{-- Page header --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Keyword Servers</h1>
                <p class="text-sm text-slate-500">Self-hosted Keyword Planner API fleet. The load balancer routes to the least-busy healthy server and fails over on errors.</p>
            </div>
            <a href="{{ route('admin.keyword-servers.index', ['new' => 1]) }}#new-server"
               class="inline-flex items-center gap-1.5 rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-orange-700">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                New server
            </a>
        </div>

        {{-- Flash --}}
        @if (session('status'))
            <div class="flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">
                <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ session('status') }}
            </div>
        @endif
        @if (session('error'))
            <div class="flex items-center gap-2 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-medium text-rose-800">
                <svg class="h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zM12 15.75h.007v.008H12v-.008z"/></svg>
                {{ session('error') }}
            </div>
        @endif

        {{-- Test result detail (request + response) --}}
        @if (session('keyword_test'))
            @php $t = session('keyword_test'); $jp = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE; @endphp
            <div class="rounded-md border border-orange-200 bg-orange-50/50 p-4 dark:border-orange-900/50 dark:bg-orange-950/20">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Test: {{ $t['title'] }} <span class="text-slate-400">· {{ $t['server'] }}</span></h2>
                    @if (! empty($t['request_id']))
                        <span class="text-[11px] text-slate-500">
                            request <code class="rounded bg-white px-1 dark:bg-slate-800">{{ $t['request_id'] }}</code>
                            · <span @class(['font-semibold', 'text-rose-600' => ($t['request_status'] ?? '') === 'failed', 'text-emerald-600' => ($t['request_status'] ?? '') !== 'failed'])>{{ $t['request_status'] ?? '' }}</span>
                            @if (! empty($t['request_error'])) · <span class="text-rose-600">{{ $t['request_error'] }}</span> @endif
                        </span>
                    @endif
                </div>

                <div class="mt-3 space-y-3">
                    @foreach ($t['sections'] as $sec)
                        <div class="rounded border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900">
                            <p class="border-b border-slate-100 px-3 py-1.5 font-mono text-[11px] font-semibold text-slate-700 dark:border-slate-800 dark:text-slate-200">{{ $sec['label'] }}</p>
                            <div class="grid gap-px bg-slate-100 md:grid-cols-2 dark:bg-slate-800">
                                <div class="bg-white p-2 dark:bg-slate-900">
                                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Request</p>
                                    <pre class="overflow-x-auto whitespace-pre-wrap break-words font-mono text-[10px] leading-relaxed text-slate-700 dark:text-slate-300">{{ json_encode($sec['request'], $jp) }}</pre>
                                </div>
                                <div class="bg-white p-2 dark:bg-slate-900">
                                    <p class="mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Response</p>
                                    <pre class="overflow-x-auto whitespace-pre-wrap break-words font-mono text-[10px] leading-relaxed text-slate-700 dark:text-slate-300">{{ $sec['response'] === null ? '(no response — request not sent)' : json_encode($sec['response'], $jp) }}</pre>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                @if (! empty($t['note']))
                    <p class="mt-2 text-[11px] text-slate-500 dark:text-slate-400">{{ $t['note'] }}</p>
                @endif
            </div>
        @endif

        {{-- Active volume provider hint --}}
        @php $provider = \App\Support\KeywordProviderConfig::currentProvider(); @endphp
        <div class="rounded-md border border-slate-200 bg-white px-4 py-2.5 text-xs text-slate-600">
            Active volume provider:
            <span class="font-semibold {{ $provider === \App\Support\KeywordProviderConfig::PROVIDER_KEYWORD_FINDER ? 'text-emerald-700' : 'text-slate-800' }}">
                {{ \App\Support\KeywordProviderConfig::options()[$provider] ?? $provider }}
            </span>
            · change it on <a href="{{ route('admin.settings') }}" class="text-orange-600 hover:underline">Settings</a>.
            The webhook endpoint is <code class="rounded bg-slate-100 px-1">{{ url(config('services.keyword_finder.webhook_path')) }}</code>.
        </div>

        {{-- Live queue: every request still queued/running, across all servers --}}
        <div class="rounded-md border border-slate-200 bg-white">
            <div class="flex items-center justify-between px-4 py-3">
                <h2 class="text-sm font-semibold text-slate-800">Live queue</h2>
                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600">{{ $queued->count() }} in flight</span>
            </div>
            @if ($queued->isEmpty())
                <p class="border-t border-slate-100 px-4 py-3 text-xs text-slate-500">Nothing queued right now.</p>
            @else
                <div class="overflow-x-auto border-t border-slate-100">
                    <table class="w-full text-xs">
                        <thead class="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-left">Server</th>
                                <th class="px-3 py-2 text-left">Type</th>
                                <th class="px-3 py-2 text-left">Keyword / URL</th>
                                <th class="px-3 py-2 text-left">User</th>
                                <th class="px-3 py-2 text-left">Queued at</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($queued as $req)
                                <tr>
                                    <td class="px-3 py-2">
                                        <span class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold {{ $req->status === 'running' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600' }}">
                                            {{ ucfirst($req->status) }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-slate-700">{{ $req->server?->name ?? '—' }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ $req->type }}@if($req->mode) <span class="text-slate-400">/ {{ $req->mode }}</span>@endif</td>
                                    <td class="px-3 py-2 text-slate-700">{{ $req->keywordSummary() }}</td>
                                    <td class="px-3 py-2 text-slate-700">{{ $req->user?->name ?? $req->user?->email ?? '— (admin test)' }}</td>
                                    <td class="px-3 py-2 text-slate-500">{{ $req->created_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Create panel --}}
        <details id="new-server" class="rounded-md border border-slate-200 bg-white" @if($showCreate) open @endif>
            <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-slate-800">New server</summary>
            <form method="POST" action="{{ route('admin.keyword-servers.store') }}" class="border-t border-slate-100 p-4">
                @csrf
                @include('admin.keyword-servers._fields', ['server' => null])
                <div class="mt-3 flex justify-end">
                    <button type="submit" class="rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-700">Add server</button>
                </div>
            </form>
        </details>

        {{-- Server list --}}
        @forelse ($servers as $server)
            <div class="rounded-md border border-slate-200 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="font-semibold text-slate-800">{{ $server->name }}</span>
                            @if (! $server->is_active)
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-slate-500">Disabled</span>
                            @elseif ($server->is_healthy === true)
                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-emerald-700">Healthy</span>
                            @elseif ($server->is_healthy === false)
                                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-rose-700">Unhealthy</span>
                            @else
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-700">Unchecked</span>
                            @endif
                            @if ($server->logged_in === false)
                                <span class="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-rose-700">Logged out</span>
                            @endif
                        </div>
                        <p class="mt-0.5 truncate text-xs text-slate-500">{{ $server->base_url }}</p>
                        <p class="mt-0.5 text-[11px] text-slate-400">
                            weight {{ $server->weight }} ·
                            queue {{ $server->last_queue_waiting ?? '—' }} waiting / {{ $server->last_queue_running ?? '—' }} running ·
                            checked {{ $relTime($server->last_health_at) }}
                            @if ($server->last_error) · <span class="text-rose-500">{{ $server->last_error }}</span> @endif
                        </p>
                        @php $last = $lastRequests[$server->id] ?? null; @endphp
                        @if ($last && $last->status === \App\Models\KeywordApiRequest::STATUS_FAILED)
                            {{-- Most recent request failed --}}
                            <div class="mt-2 rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-[11px] text-rose-700 dark:border-rose-900/50 dark:bg-rose-950/30 dark:text-rose-300">
                                <div>
                                    <span class="font-semibold uppercase tracking-wider">Last failure</span>
                                    <span class="text-rose-400">· {{ $relTime($last->completed_at ?? $last->created_at) }}</span>
                                    @if ($last->type) <span class="text-rose-400">· {{ $last->type }}{{ $last->mode ? '/'.$last->mode : '' }}</span> @endif
                                </div>
                                <p class="mt-0.5 break-words font-medium">{{ $last->error }}</p>

                                <dl class="mt-1.5 grid grid-cols-[auto,1fr] gap-x-2 gap-y-0.5 text-[10px] text-rose-500/90 dark:text-rose-400/80">
                                    <dt class="font-semibold uppercase tracking-wider">Request ID</dt>
                                    <dd class="break-all font-mono">{{ $last->request_id }}</dd>
                                    <dt class="font-semibold uppercase tracking-wider">Dispatched</dt>
                                    <dd>{{ $last->dispatched_at ?? '—' }}</dd>
                                    <dt class="font-semibold uppercase tracking-wider">Completed</dt>
                                    <dd>{{ $last->completed_at ?? '—' }}</dd>
                                </dl>

                                @if (! empty($last->payload))
                                    <details class="mt-1.5">
                                        <summary class="cursor-pointer select-none font-semibold uppercase tracking-wider">Request payload</summary>
                                        <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-words rounded bg-rose-100/70 p-2 font-mono text-[10px] leading-relaxed text-rose-800 dark:bg-rose-950/50 dark:text-rose-200">{{ json_encode($last->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                                @if (! empty($last->result))
                                    <details class="mt-1.5">
                                        <summary class="cursor-pointer select-none font-semibold uppercase tracking-wider">Full response (raw)</summary>
                                        <pre class="mt-1 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded bg-rose-100/70 p-2 font-mono text-[10px] leading-relaxed text-rose-800 dark:bg-rose-950/50 dark:text-rose-200">{{ json_encode($last->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                            </div>
                        @elseif ($last && $last->status === \App\Models\KeywordApiRequest::STATUS_COMPLETED)
                            {{-- Most recent request succeeded --}}
                            @php
                                $resultRows = is_array($last->result['results'] ?? null)
                                    ? array_values(array_filter($last->result['results'], 'is_array'))
                                    : [];
                            @endphp
                            <div class="mt-2 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-[11px] text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
                                <div>
                                    <span class="font-semibold uppercase tracking-wider">Last result</span>
                                    <span class="text-emerald-500">· {{ $relTime($last->completed_at ?? $last->created_at) }}</span>
                                    @if ($last->type) <span class="text-emerald-500">· {{ $last->type }}{{ $last->mode ? '/'.$last->mode : '' }}</span> @endif
                                    <span class="text-emerald-500">· {{ count($resultRows) }} result(s)</span>
                                    @if ($last->type === \App\Models\KeywordApiRequest::TYPE_VOLUME && isset($last->result['_cached_rows']))
                                        <span class="text-emerald-500">· {{ $last->result['_cached_rows'] }} cached</span>
                                    @endif
                                </div>

                                @if ($resultRows !== [])
                                    {{-- Every returned keyword (scrollable) — tests show the full set, unfiltered. --}}
                                    <div class="mt-1.5 max-h-96 overflow-auto rounded border border-emerald-100 dark:border-emerald-900/40">
                                        <table class="w-full text-[10px]">
                                            <thead class="sticky top-0 bg-emerald-50 text-emerald-500/80 dark:bg-emerald-950/60">
                                                <tr>
                                                    <th class="px-1.5 py-0.5 text-left font-semibold uppercase tracking-wider">Keyword</th>
                                                    <th class="px-1.5 py-0.5 text-right font-semibold uppercase tracking-wider">Avg searches</th>
                                                    <th class="px-1.5 py-0.5 text-left font-semibold uppercase tracking-wider">Competition</th>
                                                    <th class="px-1.5 py-0.5 text-right font-semibold uppercase tracking-wider">Bid range</th>
                                                </tr>
                                            </thead>
                                            <tbody class="font-medium">
                                                @foreach ($resultRows as $row)
                                                    <tr class="border-t border-emerald-100 dark:border-emerald-900/40">
                                                        <td class="px-1.5 py-0.5">{{ $row['keyword'] ?? '—' }}</td>
                                                        <td class="px-1.5 py-0.5 text-right tabular-nums">{{ isset($row['avgMonthlySearches']) ? number_format((int) $row['avgMonthlySearches']) : '—' }}</td>
                                                        <td class="px-1.5 py-0.5">{{ $row['competition'] ?? '—' }}</td>
                                                        <td class="px-1.5 py-0.5 text-right tabular-nums">
                                                            @if (isset($row['lowTopOfPageBid']) || isset($row['highTopOfPageBid']))
                                                                ${{ number_format((float) ($row['lowTopOfPageBid'] ?? 0), 2) }}–${{ number_format((float) ($row['highTopOfPageBid'] ?? 0), 2) }}
                                                            @else — @endif
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif

                                @if (! empty($last->payload))
                                    <details class="mt-1.5">
                                        <summary class="cursor-pointer select-none font-semibold uppercase tracking-wider">Request payload</summary>
                                        <pre class="mt-1 overflow-x-auto whitespace-pre-wrap break-words rounded bg-emerald-100/70 p-2 font-mono text-[10px] leading-relaxed text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">{{ json_encode($last->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                                @if (! empty($last->result))
                                    <details class="mt-1.5">
                                        <summary class="cursor-pointer select-none font-semibold uppercase tracking-wider">Full response (raw · {{ count($resultRows) }} result(s))</summary>
                                        <pre class="mt-1 max-h-80 overflow-auto whitespace-pre-wrap break-words rounded bg-emerald-100/70 p-2 font-mono text-[10px] leading-relaxed text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200">{{ json_encode($last->result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                    </details>
                                @endif
                            </div>
                        @elseif ($last)
                            {{-- In flight --}}
                            <div class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] font-medium text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300">
                                Last request {{ $last->status }} · {{ $relTime($last->dispatched_at ?? $last->created_at) }} · {{ $last->type }}{{ $last->mode ? '/'.$last->mode : '' }}
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5">
                        <form method="POST" action="{{ route('admin.keyword-servers.test', $server) }}">
                            @csrf
                            <button class="rounded-md border border-slate-300 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Health</button>
                        </form>
                        <form method="POST" action="{{ route('admin.keyword-servers.test-keyword', $server) }}" class="flex items-center gap-1">
                            @csrf
                            <input type="text" name="keyword" value="seo audit" class="w-28 rounded border border-slate-300 px-1.5 py-1 text-[11px]" />
                            <button class="rounded-md border border-slate-300 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Test keyword</button>
                        </form>
                        <form method="POST" action="{{ route('admin.keyword-servers.test-website', $server) }}" class="flex items-center gap-1">
                            @csrf
                            <input type="text" name="url" value="https://example.com" class="w-36 rounded border border-slate-300 px-1.5 py-1 text-[11px]" />
                            <button class="rounded-md border border-slate-300 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Test site</button>
                        </form>
                        <a href="{{ route('admin.keyword-servers.index', ['edit' => $server->id]) }}#edit-{{ $server->id }}"
                           class="rounded-md border border-slate-300 px-2.5 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50">Edit</a>
                        <form method="POST" action="{{ route('admin.keyword-servers.destroy', $server) }}" onsubmit="return confirm('Remove this server?');">
                            @csrf @method('DELETE')
                            <button class="rounded-md border border-rose-300 px-2.5 py-1 text-[11px] font-semibold text-rose-700 hover:bg-rose-50">Delete</button>
                        </form>
                    </div>
                </div>

                {{-- Inline edit form --}}
                <details id="edit-{{ $server->id }}" class="border-t border-slate-100" @if($editId === $server->id) open @endif>
                    <summary class="cursor-pointer select-none px-4 py-2 text-xs font-medium text-slate-500">Edit settings</summary>
                    <form method="POST" action="{{ route('admin.keyword-servers.update', $server) }}" class="p-4">
                        @csrf @method('PUT')
                        @include('admin.keyword-servers._fields', ['server' => $server])
                        <div class="mt-3 flex justify-end">
                            <button type="submit" class="rounded-md bg-orange-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-700">Save changes</button>
                        </div>
                    </form>
                </details>
            </div>
        @empty
            <div class="rounded-md border border-dashed border-slate-300 bg-white px-4 py-10 text-center text-sm text-slate-500">
                No servers yet. Add one to start serving keyword data from your own fleet.
            </div>
        @endforelse
    </div>
</x-layouts.app>
