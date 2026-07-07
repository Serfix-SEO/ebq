<div class="rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
    <div class="border-b border-slate-200 px-5 py-3.5 dark:border-slate-800">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Search Console window for page audits') }}</h2>
        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            {{ __('Page audits and the page detail keyword table use Search Console queries from the last') }}
            <span class="font-medium text-slate-700 dark:text-slate-300">N</span> {{ __('days for') }}
            <span class="font-medium text-slate-700 dark:text-slate-300">{{ $website?->domain ?? __('the selected website') }}</span>.
        </p>
    </div>

    <div class="px-5 py-4">
        @if (! $website)
            <p class="text-xs text-slate-400 dark:text-slate-500">{{ __('Select a website from the top bar first.') }}</p>
        @elseif (! $isOwner)
            <p class="text-xs text-slate-400 dark:text-slate-500">{{ __('Only the website owner can change this setting.') }}</p>
        @else
            <form wire:submit="save" class="space-y-3">
                <div>
                    <label for="gsc-lookback-days" class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Number of days') }}</label>
                    <input id="gsc-lookback-days" type="number" wire:model="lookbackDays" min="{{ (int) config('audit.gsc_keyword_lookback_days_min', 7) }}" max="{{ (int) config('audit.gsc_keyword_lookback_days_max', 480) }}"
                        class="block w-full max-w-xs rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100" />
                    @error('lookbackDays')
                        <p class="mt-1 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                        class="inline-flex h-8 items-center rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">
                        {{ __('Save') }}
                    </button>
                    @if ($saved)
                        <span class="text-xs font-medium text-emerald-600 dark:text-emerald-400" wire:transition>{{ __('Saved') }}</span>
                    @endif
                </div>

                <p class="text-[11px] text-slate-400 dark:text-slate-500">
                    {{ __('Allowed range:') }} {{ (int) config('audit.gsc_keyword_lookback_days_min', 7) }}–{{ (int) config('audit.gsc_keyword_lookback_days_max', 480) }} {{ __('days.') }}
                    {{ __('Default for new values is') }} {{ (int) config('audit.gsc_keyword_lookback_days_default', 28) }} {{ __('days when unset in the database.') }}
                    {{ __('Effective window for this site right now:') }} <span class="font-medium text-slate-600 dark:text-slate-300">{{ $effectiveDays ?? '—' }} {{ __('days') }}</span>.
                </p>
            </form>
        @endif
    </div>
</div>
