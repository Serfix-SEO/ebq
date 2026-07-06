<div class="inline-flex items-center gap-2" wire:key="country-filter-{{ $websiteId }}">
    @if (empty($options))
        <span class="text-[11px] text-slate-400 dark:text-slate-500">
            {{ __('Country data appears once GSC sync has run with the country dimension enabled.') }}
        </span>
    @else
        <label class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            {{ __('Country') }}
        </label>
        <select wire:model.live="country"
            class="h-8 rounded-md border border-slate-200 bg-white px-2 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
            <option value="">{{ __('All countries') }}</option>
            @foreach ($options as $opt)
                <option value="{{ $opt['code'] }}">
                    {{ $opt['flag'] ? $opt['flag'].' ' : '' }}{{ $opt['name'] }} ({{ $opt['code'] }})
                </option>
            @endforeach
        </select>
    @endif
</div>
