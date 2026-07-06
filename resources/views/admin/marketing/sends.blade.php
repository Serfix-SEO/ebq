<x-layouts.app>
    @php
        /** @var \Illuminate\Pagination\LengthAwarePaginator $sends */
        $relTime = function ($when): string {
            if (! $when) return '—';
            try { return \Illuminate\Support\Carbon::parse($when)->diffForHumans(); }
            catch (\Throwable) { return '—'; }
        };
    @endphp

    <div class="space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Sent reports</h1>
                <p class="text-sm text-slate-500">Record of every crawl report emailed to a client — expand a row to see the exact numbers and sample issues that were sent.</p>
            </div>
            <a href="{{ route('admin.marketing.index') }}" class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-sm hover:bg-slate-50">← Marketing</a>
        </div>

        <div class="space-y-2">
            @forelse ($sends as $s)
                @php
                    $sum = $s->summary ?? [];
                    $counts = $sum['counts'] ?? [];
                    $traffic = $sum['traffic'] ?? null;
                    // New records carry a per-category 'breakdown'; older ones a flat 'examples' list.
                    $breakdown = $sum['breakdown'] ?? null;
                    $legacy = $sum['examples'] ?? null;
                @endphp
                <details class="group rounded-lg border border-slate-200 bg-white">
                    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm">
                        <div class="flex items-center gap-2">
                            <svg class="h-3.5 w-3.5 text-slate-400 transition group-open:rotate-90" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                            <span class="font-medium text-slate-900">{{ $s->website?->domain ?? '—' }}</span>
                            <span class="text-slate-500">→ {{ $s->to_email }}</span>
                            @if ($s->status === 'sent')
                                <span class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">sent</span>
                            @else
                                <span class="rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-semibold text-red-700">{{ $s->status }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-3 text-xs text-slate-400">
                            <span class="tabular-nums">{{ number_format((int) ($counts['total'] ?? 0)) }} issues</span>
                            <span>{{ $s->sentBy?->name ? 'by '.$s->sentBy->name : '' }}</span>
                            <span>{{ $relTime($s->created_at) }}</span>
                        </div>
                    </summary>

                    <div class="border-t border-slate-100 px-4 py-3 text-sm">
                        {{-- Numbers --}}
                        <div class="mb-3 flex flex-wrap gap-2 text-xs">
                            @if (($sum['health_score'] ?? null) !== null)
                                <span class="rounded bg-slate-100 px-2 py-1"><b>Health</b> {{ $sum['health_score'] }}</span>
                            @endif
                            <span class="rounded bg-red-50 px-2 py-1 text-red-700"><b>{{ (int) ($counts['critical'] ?? 0) }}</b> critical</span>
                            <span class="rounded bg-orange-50 px-2 py-1 text-orange-700"><b>{{ (int) ($counts['high'] ?? 0) }}</b> high</span>
                            <span class="rounded bg-amber-50 px-2 py-1 text-amber-700"><b>{{ (int) ($counts['medium'] ?? 0) }}</b> medium</span>
                            <span class="rounded bg-slate-50 px-2 py-1 text-slate-600"><b>{{ (int) ($counts['low'] ?? 0) }}</b> low</span>
                            @if ($traffic && isset($traffic['gsc']))
                                <span class="rounded bg-orange-50 px-2 py-1 text-orange-700"><b>{{ number_format((int) $traffic['gsc']['clicks']) }}</b> clicks · {{ number_format((int) $traffic['gsc']['impressions']) }} impr</span>
                            @endif
                            @if ($traffic && isset($traffic['ga']))
                                <span class="rounded bg-sky-50 px-2 py-1 text-sky-700"><b>{{ number_format((int) $traffic['ga']['users']) }}</b> users</span>
                            @endif
                        </div>

                        {{-- Sample issues that were emailed --}}
                        @if ($breakdown)
                            <div class="space-y-2.5">
                                @foreach ($breakdown as $cat)
                                    <div>
                                        <div class="mb-1 text-xs font-semibold text-slate-700">{{ $cat['title'] }} <span class="text-slate-400">({{ number_format((int) $cat['count']) }})</span></div>
                                        <ul class="space-y-0.5">
                                            @foreach (($cat['examples'] ?? []) as $ex)
                                                <li class="text-xs">
                                                    <span class="text-slate-600">{{ $ex['description'] ?? '' }}</span>
                                                    <span class="text-slate-400">· {{ $ex['url'] ?? $ex['label'] ?? '' }}</span>
                                                </li>
                                            @endforeach
                                            @if ((int) $cat['count'] > count($cat['examples'] ?? []))
                                                <li class="text-[11px] text-slate-400">+{{ number_format((int) $cat['count'] - count($cat['examples'] ?? [])) }} more not shown in the email</li>
                                            @endif
                                        </ul>
                                    </div>
                                @endforeach
                            </div>
                        @elseif ($legacy)
                            {{-- Legacy records: flat example list --}}
                            <ul class="space-y-0.5">
                                @foreach ($legacy as $ex)
                                    <li class="text-xs"><span class="text-slate-600">{{ $ex['description'] ?? '' }}</span> <span class="text-slate-400">· {{ $ex['url'] ?? $ex['label'] ?? '' }}</span></li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-xs text-slate-400">No sample detail stored for this send.</p>
                        @endif
                    </div>
                </details>
            @empty
                <div class="rounded-lg border border-slate-200 bg-white px-4 py-8 text-center text-sm text-slate-400">No reports sent yet.</div>
            @endforelse
        </div>

        <div>{{ $sends->links() }}</div>
    </div>
</x-layouts.app>
