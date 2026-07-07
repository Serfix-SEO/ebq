<div>
    @if (! $canAccessWebsite)
        <div class="flex flex-col items-center justify-center rounded-xl border border-slate-200 bg-white px-6 py-16 dark:border-slate-800 dark:bg-slate-900">
            <svg class="h-12 w-12 text-slate-300 dark:text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3" /></svg>
            <p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('Select a website from the header') }}</p>
            <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ __('Add a website under Websites if you have not yet.') }}</p>
        </div>
    @else
        <div class="space-y-5">
            {{-- Add single --}}
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
                <div class="mb-4 border-b border-slate-100 pb-3 dark:border-slate-800">
                    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Add backlink') }}</h2>
                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Saved for the selected website on the date you choose.') }}</p>
                </div>
                <form wire:submit="addBacklink" class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">{{ __('Tracked date') }}</label>
                            <input wire:model="tracked_date" type="date" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                            @error('tracked_date') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">{{ __('Referring page URL') }}</label>
                            <input wire:model="referring_page_url" type="url" placeholder="https://…" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                            @error('referring_page_url') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <label class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">{{ __('Target page URL (your site)') }}</label>
                            <input wire:model="target_page_url" type="url" placeholder="https://…" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                            @error('target_page_url') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">{{ __('Domain authority') }}</label>
                            <input wire:model="domain_authority" type="number" min="0" max="100" placeholder="—" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                            @error('domain_authority') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">{{ __('Spam score') }}</label>
                            <input wire:model="spam_score" type="number" min="0" max="100" placeholder="—" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                            @error('spam_score') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">{{ __('Type') }}</label>
                            <select wire:model="type" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800">
                                @foreach ($types as $t)
                                    <option value="{{ $t->value }}">{{ __($t->label()) }}</option>
                                @endforeach
                            </select>
                            @error('type') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">{{ __('Anchor text') }}</label>
                            <input wire:model="anchor_text" type="text" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800" />
                            @error('anchor_text') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="flex items-center justify-between border-t border-slate-100 pt-3 dark:border-slate-800">
                        <label class="flex cursor-pointer items-center gap-2 text-xs font-medium text-slate-700 dark:text-slate-300">
                            <input wire:model="is_dofollow" type="checkbox" class="h-3.5 w-3.5 rounded border-slate-300 text-orange-600 focus:ring-orange-500 dark:border-slate-600 dark:bg-slate-800" />
                            {{ __('Dofollow') }}
                        </label>
                        <button type="submit" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-500">
                            <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            {{ __('Save backlink') }}
                        </button>
                    </div>
                </form>
            </div>

            {{-- Bulk sheet --}}
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900 sm:p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="min-w-0">
                        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Bulk edit by date') }}</h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Open a sheet for one tracked date to add or update many rows.') }}</p>
                    </div>
                    <div class="shrink-0">
                        <label class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">{{ __('Sheet date') }}</label>
                        <div class="flex items-center gap-2">
                            <input wire:model.live="sheetDate" type="date" class="h-8 min-w-[9rem] rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                            @if ($sheetOpen)
                                <button type="button" wire:click="closeSheet" class="inline-flex h-8 items-center rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                                    {{ __('Close sheet') }}
                                </button>
                            @else
                                <button type="button" wire:click="openSheet" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-500">
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                                    {{ __('Open sheet') }}
                                </button>
                            @endif
                        </div>
                        @error('sheetDate') <p class="mt-0.5 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                @if ($sheetOpen)
                    <div class="mt-4 space-y-3 border-t border-slate-100 pt-4 dark:border-slate-800">
                        <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-slate-700">
                            <table class="w-full min-w-[1100px] table-fixed text-xs">
                                <colgroup>
                                    <col class="w-[28%]" />
                                    <col class="w-[28%]" />
                                    <col class="w-[4rem]" />
                                    <col class="w-[4rem]" />
                                    <col class="w-[16%]" />
                                    <col class="w-[9rem]" />
                                    <col class="w-[5rem]" />
                                    <col class="w-[4.5rem]" />
                                </colgroup>
                                <thead>
                                    <tr class="border-b border-slate-200 bg-slate-50 text-start font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-400">
                                        <th class="px-2 py-2">{{ __('Referring URL') }}</th>
                                        <th class="px-2 py-2">{{ __('Target URL') }}</th>
                                        <th class="px-2 py-2 text-end">{{ __('DA') }}</th>
                                        <th class="px-2 py-2 text-end">{{ __('Spam') }}</th>
                                        <th class="px-2 py-2">{{ __('Anchor') }}</th>
                                        <th class="px-2 py-2">{{ __('Type') }}</th>
                                        <th class="px-2 py-2">{{ __('Follow') }}</th>
                                        <th class="px-2 py-2"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach ($sheetRows as $i => $row)
                                        <tr wire:key="sheet-row-{{ $i }}" class="align-middle">
                                            <td class="p-1"><input wire:model.blur="sheetRows.{{ $i }}.referring_page_url" type="url" placeholder="https://…" class="h-8 w-full rounded border border-slate-200 bg-white px-2 text-[11px] shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500/30 dark:border-slate-600 dark:bg-slate-800" /></td>
                                            <td class="p-1"><input wire:model.blur="sheetRows.{{ $i }}.target_page_url" type="url" placeholder="https://…" class="h-8 w-full rounded border border-slate-200 bg-white px-2 text-[11px] shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500/30 dark:border-slate-600 dark:bg-slate-800" /></td>
                                            <td class="p-1"><input wire:model.blur="sheetRows.{{ $i }}.domain_authority" type="number" min="0" max="100" class="h-8 w-full rounded border border-slate-200 bg-white px-2 text-end text-[11px] shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500/30 dark:border-slate-600 dark:bg-slate-800" /></td>
                                            <td class="p-1"><input wire:model.blur="sheetRows.{{ $i }}.spam_score" type="number" min="0" max="100" class="h-8 w-full rounded border border-slate-200 bg-white px-2 text-end text-[11px] shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500/30 dark:border-slate-600 dark:bg-slate-800" /></td>
                                            <td class="p-1"><input wire:model.blur="sheetRows.{{ $i }}.anchor_text" type="text" class="h-8 w-full rounded border border-slate-200 bg-white px-2 text-[11px] shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500/30 dark:border-slate-600 dark:bg-slate-800" /></td>
                                            <td class="p-1">
                                                <select wire:model.live="sheetRows.{{ $i }}.type" class="h-8 w-full rounded border border-slate-200 bg-white px-2 text-[11px] shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500/30 dark:border-slate-600 dark:bg-slate-800">
                                                    @foreach ($types as $t)
                                                        <option value="{{ $t->value }}">{{ __($t->label()) }}</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td class="p-1">
                                                <select wire:model.live="sheetRows.{{ $i }}.is_dofollow" class="h-8 w-full rounded border border-slate-200 bg-white px-2 text-[11px] shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500/30 dark:border-slate-600 dark:bg-slate-800">
                                                    <option value="1">{{ __('Do') }}</option>
                                                    <option value="0">{{ __('No') }}</option>
                                                </select>
                                            </td>
                                            <td class="p-1 text-center"><button type="button" wire:click="removeSheetRow({{ $i }})" class="rounded px-1.5 py-1 text-[10px] font-semibold text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">{{ __('Remove') }}</button></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @foreach ($sheetRows as $i => $row)
                            @if ($errors->has("sheetRows.$i.referring_page_url") || $errors->has("sheetRows.$i.target_page_url"))
                                <div class="text-[11px] text-red-600 dark:text-red-400">
                                    {{ __('Row :number', ['number' => $i + 1]) }}: {{ $errors->first("sheetRows.$i.referring_page_url") ?: $errors->first("sheetRows.$i.target_page_url") }}
                                </div>
                            @endif
                        @endforeach
                        <div class="flex flex-col-reverse items-stretch gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-h-[1.25rem] text-[11px]">
                                @if ($sheetMessage)
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-md px-2 py-1 font-medium',
                                        'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $sheetMessageKind === 'success',
                                        'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-400' => $sheetMessageKind === 'info',
                                        'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400' => $sheetMessageKind === 'error',
                                    ])>
                                        <svg class="h-3 w-3" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                        {{ $sheetMessage }}
                                    </span>
                                @endif
                                <span wire:loading wire:target="saveSheet" class="text-slate-500 dark:text-slate-400">{{ __('Saving…') }}</span>
                            </div>
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" wire:click="addSheetRow" class="inline-flex h-8 items-center rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                                    {{ __('Add row') }}
                                </button>
                                <button type="button" wire:click="saveSheet" wire:loading.attr="disabled" wire:target="saveSheet" class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-500 disabled:opacity-60">
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                    {{ __('Save sheet') }}
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Filters --}}
            <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-xs font-semibold text-slate-900 dark:text-slate-100">{{ __('Filter backlinks') }}</h3>
                <div class="mt-3 grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                    <div class="relative sm:col-span-2 lg:col-span-2 xl:col-span-1">
                        <svg class="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" /></svg>
                        <input wire:model.live.debounce.300ms="search" type="text" placeholder="{{ __('Search…') }}"
                            class="h-8 w-full rounded-md border border-slate-200 bg-white ps-8 pe-2.5 text-xs placeholder-slate-400 shadow-sm transition focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800 dark:placeholder-slate-500" />
                    </div>
                    <input wire:model.live="from" type="date" title="{{ __('From') }}" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                    <input wire:model.live="to" type="date" title="{{ __('To') }}" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                    <select wire:model.live="typeFilter" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ __('All types') }}</option>
                        @foreach ($types as $t)
                            <option value="{{ $t->value }}">{{ __($t->label()) }}</option>
                        @endforeach
                    </select>
                    <select wire:model.live="followFilter" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ __('All links') }}</option>
                        <option value="dofollow">{{ __('Dofollow') }}</option>
                        <option value="nofollow">{{ __('Nofollow') }}</option>
                    </select>
                    <select wire:model.live="auditFilter" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">{{ __('Any audit status') }}</option>
                        <option value="unaudited">{{ __('Not audited') }}</option>
                        <option value="matched">{{ __('Matched') }}</option>
                        <option value="mismatched">{{ __('Mismatched') }}</option>
                        <option value="missing">{{ __('Link missing') }}</option>
                        <option value="unreachable">{{ __('Unreachable') }}</option>
                    </select>
                    <input wire:model.live="daMin" type="number" min="0" max="100" placeholder="{{ __('DA min') }}" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                    <input wire:model.live="daMax" type="number" min="0" max="100" placeholder="{{ __('DA max') }}" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                    <input wire:model.live="spamMin" type="number" min="0" max="100" placeholder="{{ __('Spam min') }}" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                    <input wire:model.live="spamMax" type="number" min="0" max="100" placeholder="{{ __('Spam max') }}" class="h-8 w-full rounded-md border border-slate-200 bg-white px-2.5 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800" />
                </div>
            </div>

            {{-- Table --}}
            @if ($rows->isNotEmpty())
                @php
                    $auditBadge = function (?string $status) {
                        return match ($status) {
                            'matched' => [__('Matched'), 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
                            'mismatched' => [__('Mismatch'), 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400'],
                            'missing' => [__('Missing'), 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400'],
                            'unreachable' => [__('Unreachable'), 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400'],
                            default => [__('Not audited'), 'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300'],
                        };
                    };
                    $fieldLabel = fn (string $field) => match ($field) {
                        'link_present' => __('Link present'),
                        'anchor_text' => __('Anchor text'),
                        'is_dofollow' => __('Dofollow'),
                        default => $field,
                    };
                    $renderValue = function ($value) {
                        if ($value === true) {
                            return __('Yes');
                        }
                        if ($value === false) {
                            return __('No');
                        }
                        if ($value === null || $value === '') {
                            return '—';
                        }
                        return (string) $value;
                    };
                @endphp
                <div class="flex items-center justify-between gap-3">
                    <p class="text-[11px] text-slate-500 dark:text-slate-400">{{ __('Audit visits the referring page and verifies the link to your target.') }}</p>
                    <button type="button" wire:click="auditAllOnPage" wire:loading.attr="disabled" class="inline-flex h-8 items-center gap-1.5 rounded-md border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 disabled:opacity-60 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span wire:loading.remove wire:target="auditAllOnPage">{{ __('Audit visible') }}</span>
                        <span wire:loading wire:target="auditAllOnPage">{{ __('Auditing…') }}</span>
                    </button>
                </div>
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="border-b border-slate-200 bg-slate-50 text-[11px] font-semibold text-slate-500 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-400">
                                    <x-sort-header column="tracked_date" :sortBy="$sortBy" :sortDir="$sortDir" th-class="whitespace-nowrap px-2 py-2">{{ __('Date') }}</x-sort-header>
                                    <x-sort-header column="referring_page_url" :sortBy="$sortBy" :sortDir="$sortDir" th-class="px-2 py-2">{{ __('Referring') }}</x-sort-header>
                                    <x-sort-header column="target_page_url" :sortBy="$sortBy" :sortDir="$sortDir" th-class="px-2 py-2">{{ __('Target') }}</x-sort-header>
                                    <x-sort-header column="domain_authority" :sortBy="$sortBy" :sortDir="$sortDir" align="right" th-class="px-2 py-2">{{ __('DA') }}</x-sort-header>
                                    <x-sort-header column="spam_score" :sortBy="$sortBy" :sortDir="$sortDir" align="right" th-class="px-2 py-2">{{ __('Spam') }}</x-sort-header>
                                    <x-sort-header column="anchor_text" :sortBy="$sortBy" :sortDir="$sortDir" th-class="px-2 py-2">{{ __('Anchor') }}</x-sort-header>
                                    <x-sort-header column="type" :sortBy="$sortBy" :sortDir="$sortDir" th-class="px-2 py-2">{{ __('Type') }}</x-sort-header>
                                    <x-sort-header column="is_dofollow" :sortBy="$sortBy" :sortDir="$sortDir" th-class="px-2 py-2">{{ __('Follow') }}</x-sort-header>
                                    <th class="px-2 py-2">{{ __('Audit') }}</th>
                                    <th class="px-2 py-2 text-end">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($rows as $b)
                                    @php
                                        [$badgeLabel, $badgeClass] = $auditBadge($b->audit_status);
                                        $isAuditing = in_array($b->id, $auditingIds, true);
                                        $isExpanded = $expandedAuditId === $b->id;
                                        $auditResult = is_array($b->audit_result) ? $b->audit_result : null;
                                        $seenByField = [];
                                        if ($auditResult !== null) {
                                            foreach (($auditResult['matches'] ?? []) as $c) {
                                                $seenByField[$c['field']] = ['check' => $c, 'matched' => true];
                                            }
                                            foreach (($auditResult['mismatches'] ?? []) as $c) {
                                                $seenByField[$c['field']] = ['check' => $c, 'matched' => false];
                                            }
                                        }
                                        $linkPresent = $auditResult !== null ? (bool) ($auditResult['link_present'] ?? false) : null;
                                        $anchorSeen = $seenByField['anchor_text'] ?? null;
                                        $followSeen = $seenByField['is_dofollow'] ?? null;
                                    @endphp
                                    <tr @class([
                                        'transition',
                                        'bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/30 dark:hover:bg-rose-900/40' => in_array($b->audit_status, ['missing', 'unreachable'], true),
                                        'bg-amber-50 hover:bg-amber-100 dark:bg-amber-950/30 dark:hover:bg-amber-900/40' => $b->audit_status === 'mismatched',
                                        'bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-950/30 dark:hover:bg-emerald-900/40' => $b->audit_status === 'matched',
                                        'hover:bg-slate-50 dark:hover:bg-slate-800/50' => ! in_array($b->audit_status, ['missing', 'unreachable', 'mismatched', 'matched'], true),
                                    ])>
                                        <td class="whitespace-nowrap px-2 py-2 text-slate-700 dark:text-slate-200">{{ format_user_date($b->tracked_date->toDateString(), 'M j, Y') }}</td>
                                        <td class="max-w-[14rem] truncate px-2 py-2">
                                            <a href="{{ $b->referring_page_url }}" target="_blank" rel="noopener noreferrer" title="{{ $b->referring_page_url }}" class="font-medium text-orange-600 hover:underline dark:text-orange-400">{{ $b->referring_page_url }}</a>
                                        </td>
                                        <td class="max-w-[12rem] truncate px-2 py-2">
                                            <a href="{{ $b->target_page_url }}" target="_blank" rel="noopener noreferrer" title="{{ $b->target_page_url }}" class="text-orange-600 hover:underline dark:text-orange-400">{{ $b->target_page_url }}</a>
                                        </td>
                                        <td class="whitespace-nowrap px-2 py-2 text-end tabular-nums text-slate-700 dark:text-slate-300">{{ $b->domain_authority ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-2 py-2 text-end tabular-nums text-slate-700 dark:text-slate-300">{{ $b->spam_score ?? '—' }}</td>
                                        <td class="max-w-[10rem] px-2 py-2 text-slate-700 dark:text-slate-300">
                                            <div class="truncate" title="{{ $b->anchor_text }}">{{ $b->anchor_text ?? '—' }}</div>
                                            @if ($anchorSeen && $linkPresent)
                                                @php
                                                    $seenText = (string) ($anchorSeen['check']['actual'] ?? '');
                                                @endphp
                                                <div class="truncate text-[10px] {{ $anchorSeen['matched'] ? 'text-slate-400 dark:text-slate-500' : 'text-amber-700 dark:text-amber-400' }}" title="{{ $seenText }}">
                                                    {{ __('seen:') }} {{ $seenText !== '' ? '"'.$seenText.'"' : '—' }}
                                                </div>
                                            @elseif ($linkPresent === false)
                                                <div class="text-[10px] text-rose-600 dark:text-rose-400">{{ __('seen: link not found') }}</div>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-2 py-2 text-slate-700 dark:text-slate-300">{{ __($b->type->label()) }}</td>
                                        <td class="whitespace-nowrap px-2 py-2">
                                            <span @class([
                                                'inline-flex rounded-full px-1.5 py-px text-[10px] font-semibold',
                                                'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400' => $b->is_dofollow,
                                                'bg-slate-100 text-slate-500 dark:bg-slate-700 dark:text-slate-300' => ! $b->is_dofollow,
                                            ])>{{ $b->is_dofollow ? __('Do') : __('No') }}</span>
                                            @if ($followSeen && $linkPresent)
                                                @php $seenDofollow = (bool) ($followSeen['check']['actual'] ?? false); @endphp
                                                <div class="mt-0.5 text-[10px] {{ $followSeen['matched'] ? 'text-slate-400 dark:text-slate-500' : 'text-amber-700 dark:text-amber-400' }}">
                                                    {{ __('seen:') }} {{ $seenDofollow ? __('Do') : __('No') }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-2 py-2">
                                            <div class="flex flex-col items-start gap-0.5">
                                                <span class="inline-flex rounded-full px-1.5 py-px text-[10px] font-semibold {{ $badgeClass }}">{{ $badgeLabel }}</span>
                                                @if ($b->audit_checked_at)
                                                    <button type="button" wire:click="toggleAuditDetails('{{ $b->id }}')" class="text-[10px] text-slate-500 underline-offset-2 hover:underline dark:text-slate-400">{{ $b->audit_checked_at->diffForHumans() }}</button>
                                                @endif
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-2 py-1.5 text-end">
                                            <button type="button" wire:click="auditBacklink('{{ $b->id }}')" wire:loading.attr="disabled" wire:target="auditBacklink('{{ $b->id }}')" class="rounded px-1.5 py-0.5 text-[10px] font-semibold text-emerald-600 transition hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10">
                                                <span wire:loading.remove wire:target="auditBacklink('{{ $b->id }}')">{{ $b->audit_checked_at ? __('Re-audit') : __('Audit') }}</span>
                                                <span wire:loading wire:target="auditBacklink('{{ $b->id }}')">…</span>
                                            </button>
                                            <button type="button" wire:click="openSheetForDate('{{ $b->tracked_date->format('Y-m-d') }}')" class="rounded px-1.5 py-0.5 text-[10px] font-semibold text-orange-600 transition hover:bg-orange-50 dark:text-orange-400 dark:hover:bg-orange-500/10">{{ __('Sheet') }}</button>
                                            <button type="button" wire:click="deleteBacklink('{{ $b->id }}')" wire:confirm="{{ __('Delete this backlink?') }}" class="rounded px-1.5 py-0.5 text-[10px] font-semibold text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10">{{ __('Delete') }}</button>
                                        </td>
                                    </tr>
                                    @if ($isExpanded && $auditResult !== null)
                                        <tr class="bg-slate-50/70 dark:bg-slate-800/40">
                                            <td colspan="10" class="px-3 py-3">
                                                <div class="space-y-2 text-[11px] text-slate-600 dark:text-slate-300">
                                                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                                        <span><span class="font-semibold">{{ __('Status:') }}</span> {{ $badgeLabel }}</span>
                                                        @if (! empty($auditResult['http_status']))
                                                            <span><span class="font-semibold">{{ __('HTTP:') }}</span> {{ $auditResult['http_status'] }}</span>
                                                        @endif
                                                        @if ($b->audit_checked_at)
                                                            <span><span class="font-semibold">{{ __('Checked:') }}</span> {{ format_user_datetime($b->audit_checked_at, 'M j, Y g:i a') }}</span>
                                                        @endif
                                                    </div>
                                                    @if (! empty($auditResult['message']))
                                                        <p class="text-[11px] text-rose-600 dark:text-rose-400">{{ $auditResult['message'] }}</p>
                                                    @endif

                                                    @if (! empty($auditResult['mismatches']))
                                                        <div>
                                                            <p class="font-semibold text-amber-700 dark:text-amber-400">{{ __('Mismatched fields') }}</p>
                                                            <ul class="mt-1 space-y-0.5">
                                                                @foreach ($auditResult['mismatches'] as $m)
                                                                    <li>
                                                                        <span class="font-medium">{{ $fieldLabel($m['field']) }}:</span>
                                                                        {{ __('expected') }} <code class="rounded bg-white px-1 py-px text-[10px] dark:bg-slate-900">{{ $renderValue($m['expected']) }}</code>,
                                                                        {{ __('found') }} <code class="rounded bg-white px-1 py-px text-[10px] dark:bg-slate-900">{{ $renderValue($m['actual']) }}</code>
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if (! empty($auditResult['matches']))
                                                        <div>
                                                            <p class="font-semibold text-emerald-700 dark:text-emerald-400">{{ __('Matched fields') }}</p>
                                                            <ul class="mt-1 space-y-0.5">
                                                                @foreach ($auditResult['matches'] as $m)
                                                                    <li>
                                                                        <span class="font-medium">{{ $fieldLabel($m['field']) }}:</span>
                                                                        <code class="rounded bg-white px-1 py-px text-[10px] dark:bg-slate-900">{{ $renderValue($m['actual']) }}</code>
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if (! empty($auditResult['found_links']))
                                                        <div>
                                                            <p class="font-semibold">{{ __('Links found on referring page') }}</p>
                                                            <ul class="mt-1 space-y-0.5">
                                                                @foreach ($auditResult['found_links'] as $link)
                                                                    <li class="truncate">
                                                                        <a href="{{ $link['href'] }}" target="_blank" rel="noopener noreferrer" class="text-orange-600 hover:underline dark:text-orange-400">{{ $link['href'] }}</a>
                                                                        — {{ __('anchor') }} "{{ $link['anchor'] !== '' ? $link['anchor'] : '—' }}",
                                                                        {{ __('rel') }} "{{ $link['rel'] !== '' ? $link['rel'] : '—' }}"
                                                                        ({{ $link['is_dofollow'] ? __('dofollow') : __('nofollow') }})
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="mt-3">{{ $rows->links() }}</div>
            @else
                <div class="flex flex-col items-center justify-center rounded-xl border border-slate-200 bg-white px-6 py-16 dark:border-slate-800 dark:bg-slate-900">
                    <svg class="h-12 w-12 text-slate-300 dark:text-slate-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" /></svg>
                    <p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('No backlinks yet') }}</p>
                    <p class="mt-1 text-center text-xs text-slate-400 dark:text-slate-500">{{ __('Use the form above or open a sheet for a date to add entries.') }}</p>
                </div>
            @endif
        </div>
    @endif
</div>
