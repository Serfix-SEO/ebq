<div @if($this->isPolling()) wire:poll.3000ms="poll" @endif>
    <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-700 dark:bg-slate-800">
        @if (! $website)
            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Select a website to discover its competitors.') }}</p>
        @else
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[240px]">
                    <label class="block text-xs font-medium text-slate-600 dark:text-slate-300">
                        @if ($hasGsc)
                            {{ __('We’ll sample your top Search Console queries.') }}
                        @else
                            {{ __('Seed keywords (no Search Console connected)') }}
                        @endif
                    </label>
                    @unless ($hasGsc)
                        <textarea wire:model="seedsInput" rows="3"
                            placeholder="{{ __('One keyword per line — e.g. project management software') }}"
                            class="mt-1 w-full rounded-lg border-slate-300 text-sm dark:border-slate-600 dark:bg-slate-900"></textarea>
                    @endunless
                </div>
                <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                    <input type="checkbox" wire:model="includeGiants" class="rounded border-slate-300">
                    {{ __('Include big platforms') }}
                </label>
            </div>

            <div class="mt-4 flex items-center gap-3">
                <button type="button" wire:click="discover" wire:loading.attr="disabled" @if($this->isPolling()) disabled @endif
                    class="inline-flex items-center gap-2 rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-500 disabled:opacity-50">
                    @if ($this->isPolling())
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        {{ __('Discovering…') }}
                    @else
                        {{ __('Discover competitors') }}
                    @endif
                </button>
                @if ($competitors->isNotEmpty())
                    <button type="button" wire:click="trackTop"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200">
                        {{ __('Track top competitors') }}
                    </button>
                    <button type="button" wire:click="export"
                        class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200">
                        {{ __('Export CSV') }}
                    </button>
                @endif
            </div>

            @if ($errorMessage)
                <p class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $errorMessage }}</p>
            @endif
            @if ($notice)
                <p class="mt-3 text-sm text-amber-600 dark:text-amber-400">{{ $notice }}</p>
            @endif
            @if ($lastRun && $lastRun->status === 'completed' && ! $this->isPolling())
                <p class="mt-3 text-xs text-slate-400">{{ __('Last run scanned') }} {{ $lastRun->keywords_planned }} {{ __('keyword(s) via') }} {{ $lastRun->serp_calls_made }} {{ __('search(es)') }} · {{ $lastRun->completed_at?->diffForHumans() }}</p>
            @endif
        @endif
    </div>

    @if ($competitors->isNotEmpty())
        <div class="mt-5 overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700 text-sm">
                <thead class="bg-slate-50 dark:bg-slate-900/50">
                    <tr class="text-left text-xs font-medium uppercase tracking-wide text-slate-500">
                        <th class="px-4 py-3">{{ __('Competitor') }}</th>
                        <th class="px-4 py-3">{{ __('Score') }}</th>
                        <th class="px-4 py-3">{{ __('Seen in') }}</th>
                        <th class="px-4 py-3">{{ __('Avg position') }}</th>
                        <th class="px-4 py-3">{{ __('DA') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50">
                    @foreach ($competitors as $c)
                        <tr>
                            <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-100">
                                {{ $c->competitor_domain }}
                                @if (is_array($c->sample_keywords) && $c->sample_keywords !== [])
                                    <span class="block text-xs font-normal text-slate-400" title="{{ implode(', ', $c->sample_keywords) }}">{{ \Illuminate\Support\Str::limit(implode(', ', $c->sample_keywords), 60) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center rounded-full bg-orange-50 px-2 py-0.5 text-xs font-semibold text-orange-700 dark:bg-orange-900/40 dark:text-orange-300">{{ (int) $c->score }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $c->appearances }} / {{ $c->keywords_sampled }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $c->avg_position !== null ? number_format($c->avg_position, 1) : '—' }}</td>
                            <td class="px-4 py-3 text-slate-600 dark:text-slate-300">{{ $c->domain_authority ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
