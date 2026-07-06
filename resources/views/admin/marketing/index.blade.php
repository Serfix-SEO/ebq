<x-layouts.app>
    @php
        /**
         * @var \Illuminate\Pagination\LengthAwarePaginator $websites
         * @var \Illuminate\Support\Collection $rows
         * @var \Illuminate\Support\Collection $recentSends
         * @var string $q
         */
        $relTime = function ($when): string {
            if (! $when) return '—';
            try { return \Illuminate\Support\Carbon::parse($when)->diffForHumans(); }
            catch (\Throwable) { return '—'; }
        };
        $sevBadge = [
            'critical' => 'bg-red-100 text-red-700',
            'high' => 'bg-orange-100 text-orange-700',
            'medium' => 'bg-amber-100 text-amber-700',
            'low' => 'bg-slate-100 text-slate-600',
        ];
    @endphp

    <div class="space-y-5">
        {{-- Page header --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Marketing</h1>
                <p class="text-sm text-slate-500">Websites with a finished crawl and open issues — email a client their numbers + top 3 errors.</p>
            </div>
            <a href="{{ route('admin.marketing.sends') }}"
               class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">
                Sent history
            </a>
        </div>

        {{-- Flash --}}
        @if (session('status'))
            <div class="flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-800">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                {{ session('status') }}
            </div>
        @endif

        {{-- Search --}}
        <form method="GET" class="flex items-center gap-2">
            <input type="text" name="q" value="{{ $q }}" placeholder="Search by domain…"
                   class="h-8 w-64 rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20" />
            <button type="submit" class="h-8 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white hover:bg-orange-700">Search</button>
            @if ($q !== '')<a href="{{ route('admin.marketing.index') }}" class="text-xs text-slate-500 hover:underline">Clear</a>@endif
        </form>

        {{-- Websites --}}
        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2 text-left font-semibold">Website / owner</th>
                        <th class="px-3 py-2 text-center font-semibold">Health</th>
                        <th class="px-3 py-2 text-center font-semibold">Issues (C / H / M / L)</th>
                        <th class="px-3 py-2 text-left font-semibold">Last crawl</th>
                        <th class="px-4 py-2 text-right font-semibold">Send report</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php $w = $row['website']; $c = $row['counts']; @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-900">{{ $w->domain }}</div>
                                <div class="text-xs text-slate-500">{{ $w->user?->name ?: '—' }} · {{ $w->user?->email ?: 'no owner email' }}</div>
                            </td>
                            <td class="px-3 py-3 text-center">
                                <span class="font-semibold tabular-nums {{ ($row['health'] ?? 100) < 60 ? 'text-red-600' : 'text-slate-800' }}">{{ $row['health'] ?? '—' }}</span>
                            </td>
                            <td class="px-3 py-3 text-center text-xs tabular-nums">
                                <span class="font-semibold text-red-600">{{ (int) ($c['critical'] ?? 0) }}</span> /
                                <span class="font-semibold text-orange-600">{{ (int) ($c['high'] ?? 0) }}</span> /
                                <span class="text-amber-600">{{ (int) ($c['medium'] ?? 0) }}</span> /
                                <span class="text-slate-500">{{ (int) ($c['low'] ?? 0) }}</span>
                                <div class="text-[10px] text-slate-400">{{ (int) ($c['total'] ?? 0) }} total</div>
                            </td>
                            <td class="px-3 py-3 text-xs text-slate-500">{{ $relTime($row['last_crawled_at']) }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('admin.marketing.send', $w) }}" class="flex items-center justify-end gap-1.5">
                                    @csrf
                                    <input type="email" name="to_email" value="{{ $w->user?->email }}" placeholder="recipient@email.com"
                                           class="h-7 w-48 rounded border border-slate-200 px-2 text-xs focus:border-orange-500 focus:outline-none" />
                                    <button type="submit"
                                            onclick="return confirm('Send the crawl report for {{ $w->domain }} to this address?')"
                                            class="h-7 rounded bg-orange-600 px-2.5 text-xs font-semibold text-white hover:bg-orange-700">Send</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-8 text-center text-sm text-slate-400">No websites with a finished crawl and open issues.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $websites->links() }}</div>

        {{-- Recent sends --}}
        @if ($recentSends->isNotEmpty())
            <div class="rounded-lg border border-slate-200 bg-white">
                <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2">
                    <h2 class="text-sm font-semibold">Recently sent</h2>
                    <a href="{{ route('admin.marketing.sends') }}" class="text-xs text-orange-600 hover:underline">View all →</a>
                </div>
                <ul class="divide-y divide-slate-100 text-sm">
                    @foreach ($recentSends as $s)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                            <div>
                                <span class="font-medium">{{ $s->website?->domain ?? '—' }}</span>
                                <span class="text-slate-500">→ {{ $s->to_email }}</span>
                                @if ($s->status !== 'sent')<span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700">{{ $s->status }}</span>@endif
                            </div>
                            <div class="text-xs text-slate-400">{{ $s->sentBy?->name ? 'by '.$s->sentBy->name.' · ' : '' }}{{ $relTime($s->created_at) }}</div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-layouts.app>
