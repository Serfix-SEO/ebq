<x-layouts.app>
    @php
        /**
         * @var \Illuminate\Support\Collection $failedGroups
         * @var int $failedTotal
         * @var \Illuminate\Support\Collection $stuckSites
         * @var array<string, array{pending:?int,delayed:?int,reserved:?int}> $queues
         * @var int $alertBufferSize
         */
    @endphp

    <div class="mx-auto max-w-6xl space-y-6 p-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-semibold text-slate-900 dark:text-slate-100">Ops — queue &amp; worker health</h1>
                <p class="mt-1 text-sm text-slate-500">
                    Failed jobs (7 days), never-crawled sites, live queue depths. Admins also get a mailed digest
                    every 15 minutes when new failures land (<code class="text-xs">ebq:failed-jobs-alert</code>).
                </p>
            </div>
        </div>

        @if (session('status'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">{{ session('error') }}</div>
        @endif

        {{-- Queue depths --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Queues (live)</h2>
                <span class="text-xs text-slate-400">
                    Alert buffer: <span class="font-semibold {{ $alertBufferSize > 0 ? 'text-amber-600' : 'text-slate-500' }}">{{ $alertBufferSize }}</span> undelivered
                </span>
            </div>
            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($queues as $name => $q)
                    <div class="rounded-lg border border-slate-100 p-3 dark:border-slate-800">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ $name }}</p>
                        <p class="mt-1 text-lg font-bold tabular-nums {{ ($q['pending'] ?? 0) > 50 ? 'text-amber-600' : 'text-slate-900 dark:text-slate-100' }}">
                            {{ $q['pending'] ?? '—' }}
                        </p>
                        <p class="text-[11px] text-slate-400">+{{ $q['delayed'] ?? '—' }} delayed · {{ $q['reserved'] ?? '—' }} running</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Stuck crawl sites --}}
        <div class="rounded-xl border {{ $stuckSites->isNotEmpty() ? 'border-amber-300 bg-amber-50/50 dark:border-amber-700 dark:bg-amber-500/5' : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900' }} p-5">
            <h2 class="text-sm font-semibold uppercase tracking-wide {{ $stuckSites->isNotEmpty() ? 'text-amber-700 dark:text-amber-400' : 'text-slate-500' }}">
                Never-crawled sites with subscribers (pending &gt;24h) — {{ $stuckSites->count() }}
            </h2>
            @if ($stuckSites->isEmpty())
                <p class="mt-2 text-sm text-slate-500">None — every subscribed site has at least one crawl run.</p>
            @else
                <p class="mt-1 text-xs text-slate-500">
                    These never created a CrawlRun, so the crawl supervisor cannot see them. Usually means the crawl
                    job is dying before it starts — check the failed jobs below for the reason before re-kicking.
                </p>
                <table class="mt-3 w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-400 dark:border-slate-700">
                            <th class="py-2 pr-4">Domain</th>
                            <th class="py-2 pr-4">Pending since</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($stuckSites as $site)
                            <tr class="border-b border-slate-100 dark:border-slate-800">
                                <td class="py-2 pr-4 font-medium text-slate-900 dark:text-slate-100">{{ $site['domain'] }}</td>
                                <td class="py-2 pr-4 text-slate-500">{{ $site['since']->diffForHumans() }} ({{ $site['since']->toDateString() }})</td>
                                <td class="py-2 pr-4">
                                    @if ($site['frozen'])
                                        <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">frozen (plan limit)</span>
                                    @else
                                        <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-500/15 dark:text-amber-400">stuck</span>
                                    @endif
                                </td>
                                <td class="py-2 text-right">
                                    @unless ($site['frozen'])
                                        <form method="POST" action="{{ route('admin.ops.start-crawl', $site['id']) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="rounded-md bg-orange-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-orange-700">Start crawl</button>
                                        </form>
                                    @endunless
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Failed jobs --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">
                Failed jobs — last 7 days ({{ $failedTotal }} total, {{ $failedGroups->count() }} distinct)
            </h2>
            @if ($failedGroups->isEmpty())
                <p class="mt-2 text-sm text-slate-500">No failures in the window. 🎉</p>
            @else
                <div class="mt-3 space-y-3">
                    @foreach ($failedGroups as $group)
                        <div class="rounded-lg border border-slate-100 p-4 dark:border-slate-800">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm font-semibold text-slate-900 dark:text-slate-100">
                                        {{ $group['job'] }}
                                        <span class="ml-2 rounded-full bg-rose-100 px-2 py-0.5 text-xs font-bold text-rose-700 dark:bg-rose-500/15 dark:text-rose-400">×{{ $group['count'] }}</span>
                                    </p>
                                    <p class="mt-1 break-all font-mono text-xs text-slate-500">{{ $group['exception_head'] }}</p>
                                    <p class="mt-1 text-xs text-slate-400">
                                        queue <span class="font-semibold">{{ $group['queue'] }}</span>
                                        · latest {{ $group['latest_at']->diffForHumans() }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 gap-2">
                                    <form method="POST" action="{{ route('admin.ops.retry') }}">
                                        @csrf
                                        @foreach ($group['uuids'] as $uuid)
                                            <input type="hidden" name="uuids[]" value="{{ $uuid }}">
                                        @endforeach
                                        <button type="submit" class="rounded-md border border-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Retry all</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.ops.forget') }}" onsubmit="return confirm('Delete {{ $group['count'] }} failed job record(s)? They will NOT be retried.');">
                                        @csrf
                                        @foreach ($group['uuids'] as $uuid)
                                            <input type="hidden" name="uuids[]" value="{{ $uuid }}">
                                        @endforeach
                                        <button type="submit" class="rounded-md border border-rose-200 px-2.5 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-50 dark:border-rose-800 dark:hover:bg-rose-500/10">Forget</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-slate-400">Full stack traces: <a href="/horizon/failed" class="underline">Horizon → Failed</a> or the <code>failed_jobs</code> table.</p>
            @endif
        </div>
    </div>
</x-layouts.app>
