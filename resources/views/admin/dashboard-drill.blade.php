<x-layouts.app>
    @php
        /**
         * @var string $metric
         * @var string $label
         * @var \Illuminate\Support\Collection $rows
         */
    @endphp
    <div class="space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    <a href="{{ route('admin.dashboard') }}" class="hover:underline">Dashboard</a> / drill-down
                </p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight">{{ $label }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ number_format($rows->count()) }} {{ $rows->count() === 1 ? 'record' : 'records' }}{{ $rows->count() >= 200 ? ' (showing the latest 200)' : '' }}
                    — same counting rules as the tile.
                </p>
            </div>
            <a href="{{ route('admin.dashboard') }}"
               class="inline-flex items-center rounded-md border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                ← Back to dashboard
            </a>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($rows as $row)
                    <li class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                        <div class="min-w-0">
                            <p class="truncate font-medium text-slate-900 dark:text-white">
                                {{ $row['title'] }}
                                @if (! empty($row['badge']))
                                    <span class="ms-1.5 inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ $row['badge'] }}</span>
                                @endif
                            </p>
                            <p class="truncate text-xs text-slate-500">{{ $row['subtitle'] }}</p>
                        </div>
                        <div class="flex shrink-0 items-center gap-4">
                            <span class="text-xs tabular-nums text-slate-400" title="{{ $row['at']?->toDayDateTimeString() }}">
                                {{ $row['at']?->diffForHumans(short: true) ?? '—' }}
                            </span>
                            @if (! empty($row['href']))
                                <a href="{{ $row['href'] }}" @if (str_starts_with($row['href'], 'http')) target="_blank" rel="noopener" @endif
                                   class="text-xs font-semibold text-orange-600 hover:underline dark:text-orange-400">
                                    Open →
                                </a>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="px-4 py-10 text-center text-sm text-slate-400">No records.</li>
                @endforelse
            </ul>
        </div>
    </div>
</x-layouts.app>
