{{--
    Competitor mention protection — a selling point, shown on the wizard's
    competitors step and again in Content Settings.

    $guard: null while the classification is still running, else
    {assessed, enabled, autoEnabled, reason, terms[]} from ContentCalendar::render().
    The surrounding step already polls (wire:poll), so a null card fills in
    by itself once the assessment lands.
--}}
<div class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
            </span>
            <div>
                <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Competitor mention protection') }}</h3>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Your articles will never name, recommend, or link to the brands below.') }}</p>
            </div>
        </div>

        @if ($guard !== null && $guard['assessed'])
            <button type="button" wire:click="toggleCompetitorGuard"
                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $guard['enabled'] ? 'bg-success' : 'bg-slate-300 dark:bg-slate-700' }}"
                aria-label="{{ __('Competitor mention protection') }}">
                <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition {{ $guard['enabled'] ? 'translate-x-5' : 'translate-x-1' }}"></span>
            </button>
        @endif
    </div>

    @if ($guard === null || ! $guard['assessed'])
        {{-- Poll until the classification lands (the queued job is seconds away). --}}
        <div wire:poll.4s class="mt-4 flex items-center gap-2.5 rounded-xl bg-slate-50 px-4 py-3 text-xs font-medium text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
            <svg class="h-4 w-4 shrink-0 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
            {{ __('Checking which of your competitors could pull readers away…') }}
        </div>
    @else
        @if ($guard['autoEnabled'])
            <div class="mt-4 rounded-xl border border-orange-200 bg-orange-50 px-4 py-3 dark:border-orange-900 dark:bg-orange-950">
                <p class="text-xs font-bold text-orange-800 dark:text-orange-200">
                    {{ trans_choice('We turned this on for you: :n of your competitors sells what you sell.|We turned this on for you: :n of your competitors sell what you sell.', count($guard['terms']), ['n' => count($guard['terms'])]) }}
                </p>
                @if ($guard['reason'] !== '' && ! str_starts_with($guard['reason'], 'auto ('))
                    <p class="mt-1 text-xs text-orange-700 dark:text-orange-300">{{ $guard['reason'] }}</p>
                @endif
                <p class="mt-1 text-xs text-orange-700/80 dark:text-orange-300/80">{{ __('You can switch this off or edit the list at any time — also later, in Content Settings.') }}</p>
            </div>
        @endif

        @if ($guard['enabled'])
            <div class="mt-4">
                @if ($guard['terms'] !== [])
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($guard['terms'] as $term)
                            <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 py-1 pe-1.5 ps-3 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200" wire:key="guard-term-{{ $term }}">
                                {{ $term }}
                                <button type="button" wire:click="removeBlockedTerm('{{ $term }}')" aria-label="{{ __('Allow mentioning :term', ['term' => $term]) }}"
                                    class="flex h-4 w-4 items-center justify-center rounded-full text-slate-400 hover:bg-slate-200 hover:text-slate-600 dark:hover:bg-slate-700">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </span>
                        @endforeach
                    </div>
                @else
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('No blocked brands yet — add any name your articles must never mention.') }}</p>
                @endif

                <div class="mt-3 flex gap-2">
                    <input wire:model="newBlockedTerm" wire:keydown.enter.prevent="addBlockedTerm" type="text" placeholder="{{ __('brand or product name') }}"
                        class="min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                    <button type="button" wire:click="addBlockedTerm" aria-label="{{ __('Add') }}"
                        class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-orange-600 text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    </button>
                </div>
                @error('newBlockedTerm') <p class="mt-1.5 text-xs text-error">{{ $message }}</p> @enderror

                {{-- Allowed references (Phase E): the classifier's other half —
                     directories/platforms/citation sources articles may still
                     link to. One click blocks one anyway. --}}
                @if (! empty($guard['references']))
                    <div class="mt-4 border-t border-slate-100 pt-3 dark:border-slate-800">
                        <p class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __('Allowed as sources') }}</p>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('These share your search results but are citation sources, not rivals — articles may still reference them.') }}</p>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach ($guard['references'] as $ref)
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white py-1 pe-1.5 ps-3 text-xs font-medium text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400" wire:key="guard-ref-{{ $ref }}">
                                    {{ $ref }}
                                    <button type="button" wire:click="blockReference('{{ $ref }}')" title="{{ __('Block this brand too') }}" aria-label="{{ __('Block this brand too') }}"
                                        class="flex h-4 w-4 items-center justify-center rounded-full text-slate-400 hover:bg-slate-200 hover:text-slate-600 dark:hover:bg-slate-700">
                                        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                    </button>
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            {{-- Value counter: protection you can see. --}}
            @if (($guard['stats']['articles_checked'] ?? 0) > 0)
                <p class="mt-3 text-xs font-medium text-slate-500 dark:text-slate-400">
                    <svg class="-mt-0.5 me-1 inline h-3.5 w-3.5 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    {{ trans_choice(':n article checked|:n articles checked', $guard['stats']['articles_checked'], ['n' => $guard['stats']['articles_checked']]) }}@if(($guard['stats']['mentions_removed'] ?? 0) > 0) · {{ trans_choice(':n competitor mention removed|:n competitor mentions removed', $guard['stats']['mentions_removed'], ['n' => $guard['stats']['mentions_removed']]) }}@endif
                </p>
            @endif
        @else
            <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">{{ __('Protection is off — articles may mention any brand, including competitors.') }}</p>
        @endif
    @endif
</div>
