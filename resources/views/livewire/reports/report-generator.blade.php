<div class="space-y-5">
    {{-- Controls --}}
    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end">
            {{-- Report Type --}}
            <div class="min-w-0 flex-1">
                <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">Report Type</label>
                <div class="flex rounded-md border border-slate-200 dark:border-slate-700" role="radiogroup">
                    @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'custom' => 'Custom'] as $value => $label)
                        <button type="button" wire:click="$set('reportType', '{{ $value }}')"
                            @class([
                                'flex-1 h-8 px-2 text-xs font-semibold transition-all first:rounded-l-[0.3125rem] last:rounded-r-[0.3125rem]',
                                'bg-orange-600 text-white shadow-sm' => $reportType === $value,
                                'text-slate-600 hover:bg-slate-50 dark:text-slate-400 dark:hover:bg-slate-800' => $reportType !== $value,
                            ])
                            role="radio"
                            aria-checked="{{ $reportType === $value ? 'true' : 'false' }}">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Date Range + Actions in one row --}}
            <div class="flex flex-1 flex-col gap-4 sm:flex-row sm:items-end">
                <div class="flex min-w-0 flex-1 items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">Start</label>
                        <input type="date" wire:model.live="startDate"
                            class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                            @if ($reportType !== 'custom') readonly @endif>
                    </div>
                    <span class="shrink-0 pb-1.5 text-xs text-slate-400">–</span>
                    <div class="min-w-0 flex-1">
                        <label class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">End</label>
                        <input type="date" wire:model.live="endDate"
                            class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
                            @if ($reportType !== 'custom') readonly @endif>
                    </div>
                </div>

                {{-- Actions: invisible label keeps them aligned with the date inputs --}}
                <div class="shrink-0">
                    <span class="mb-1 block text-[11px] font-medium text-transparent" aria-hidden="true">&nbsp;</span>
                    <div class="flex gap-2">
                        <button type="button" wire:click="generatePreview" wire:loading.attr="disabled"
                            class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-500 disabled:opacity-60">
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>
                            <span wire:loading.remove wire:target="generatePreview">Preview</span>
                            <span wire:loading wire:target="generatePreview">Loading…</span>
                        </button>
                        <button type="button" wire:click="sendReport" wire:loading.attr="disabled"
                            class="inline-flex h-8 items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                            <span wire:loading.remove wire:target="sendReport">Email</span>
                            <span wire:loading wire:target="sendReport">Sending…</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="mt-3 flex items-center gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
            <livewire:dashboard.country-filter />
            @if ($country !== '')
                <button type="button" wire:click="onCountryChanged('')"
                    class="text-[11px] font-medium text-slate-500 underline-offset-2 hover:text-orange-600 hover:underline dark:text-slate-400 dark:hover:text-orange-400">
                    Clear
                </button>
            @endif
        </div>

        @if ($sendSuccess)
            <div class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400" role="status">
                {{ $sendSuccess }}
            </div>
        @endif
        @if ($sendError)
            <div class="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-400" role="alert">
                {{ $sendError }}
            </div>
        @endif
    </div>

    {{-- Report Preview --}}
    @if ($showPreview && !empty($report))
        <livewire:reports.report-preview
            :report="$report"
            :website-id="$websiteId"
            :report-type="$reportType"
            :start-date="$startDate"
            :end-date="$endDate"
            :key="$startDate.'-'.$endDate.'-'.$websiteId.'-'.$country" />
    @endif
</div>
