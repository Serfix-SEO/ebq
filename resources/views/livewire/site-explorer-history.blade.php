{{-- Single root element ALWAYS — a Livewire component view must have one root
     HTML tag even when empty, else RootTagMissingFromViewException (500). This
     fired for new accounts with no analyze history. --}}
<div>
    @if (! empty($history))
        <div class="mt-8">
            <h2 class="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{{ __('Recently analyzed') }}</h2>
            <ul class="mt-2 divide-y divide-slate-100 rounded-2xl bg-white shadow-sm ring-1 ring-slate-200 dark:divide-slate-800 dark:bg-slate-900 dark:ring-slate-800">
                @foreach ($history as $item)
                    <li>
                        <a href="{{ route('report.view', ['url' => $item['domain']]) }}" class="flex items-center gap-3 px-4 py-2.5 transition hover:bg-slate-50 dark:hover:bg-slate-800/60">
                            <img src="https://www.google.com/s2/favicons?domain={{ urlencode($item['domain']) }}&sz=32" alt="" width="16" height="16" class="h-4 w-4 flex-none rounded-sm bg-slate-100" loading="lazy" onerror="this.style.visibility='hidden'">
                            <span class="min-w-0 flex-1 truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $item['domain'] }}</span>
                            @if ($item['is_own_website'])
                                <span class="flex-none rounded-full bg-orange-100 px-1.5 py-px text-[9px] font-bold uppercase tracking-wider text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">{{ __('Your site') }}</span>
                            @endif
                            <span class="flex-none text-xs text-slate-400">{{ $item['last_viewed_at']->diffForHumans() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
