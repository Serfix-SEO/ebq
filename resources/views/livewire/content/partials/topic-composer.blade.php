{{-- "Write about this instead" — the client's own topic, in their words.

     They type an idea; we come back with properly-formed titles carrying a
     real target keyword, intent, secondary keywords and whatever volume we
     know, ranked by what this site can realistically rank for. Picking one
     puts it on the calendar with the same SEO scaffolding an auto-planned
     topic gets (App\Services\Content\TopicComposer).

     Replacing inherits the replaced article's date, so a swap costs nothing
     against the monthly allowance; adding takes one of the month's remaining
     publish days, which is why the date list comes from the planner. --}}
@if ($composerOpen)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
        <div class="absolute inset-0 bg-slate-900/50" wire:click="closeComposer"></div>
        <div class="relative max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-3xl border border-slate-200 bg-white p-6 shadow-2xl dark:border-slate-700 dark:bg-slate-900">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-lg font-extrabold tracking-tight text-slate-900 dark:text-slate-100">
                        {{ $composerReplacing ? __('Write something else instead') : __('Add your own topic') }}
                    </h3>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        {{ $composerReplacing
                            ? __('Tell us what this article should be about. The replacement keeps the same publish date.')
                            : __('Tell us what you\'d like an article about. We\'ll find the keywords and write it like the rest.') }}
                    </p>
                </div>
                <button type="button" wire:click="closeComposer" class="shrink-0 rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" aria-label="{{ __('Cancel') }}">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="mt-5">
                <textarea wire:model="composerIdea" rows="3" maxlength="300"
                          placeholder="{{ __('e.g. how to choose a perfume for summer') }}"
                          class="w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 placeholder-slate-400 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
                @error('composerIdea')
                    <p class="mt-1 text-xs font-medium text-error">{{ $message }}</p>
                @enderror

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="suggestTopics" wire:loading.attr="disabled" wire:target="suggestTopics"
                            class="inline-flex items-center gap-2 rounded-xl bg-orange-600 px-4 py-2 text-sm font-bold text-white transition hover:bg-orange-700 disabled:opacity-50">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/></svg>
                        {{ count($composerSuggestions) ? __('Try other ideas') : __('Find topics') }}
                    </button>
                    <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-orange-600 dark:text-orange-400" wire:loading wire:target="suggestTopics">
                        <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg>
                        {{ __('Looking at what people search for…') }}
                    </span>
                </div>
            </div>

            @if ($composerNotice !== '')
                <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-200">{{ $composerNotice }}</p>
            @endif

            @if (count($composerSuggestions))
                {{-- Add mode only: the date must be one the planner would
                     schedule, which is what keeps one article per day and the
                     monthly cap intact. --}}
                @if (! $composerReplacing)
                    <div class="mt-5">
                        <label class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Publish day') }}</label>
                        @if (count($composerDates))
                            <select wire:model="composerDate" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                                @foreach ($composerDates as $date)
                                    <option value="{{ $date }}">{{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('D, M j') }}</option>
                                @endforeach
                            </select>
                        @else
                            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Every publish day this month is taken. Swap out a planned article instead, or pick a day next month from List view.') }}</p>
                        @endif
                    </div>
                @endif

                <div class="mt-5 space-y-3">
                    @foreach ($composerSuggestions as $i => $suggestion)
                        <div wire:key="cs-{{ $i }}" class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                            <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ $suggestion['title'] }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                <span class="font-semibold text-slate-600 dark:text-slate-300">{{ $suggestion['target_keyword'] }}</span>
                                @if (! is_null($suggestion['volume']))
                                    <span class="font-semibold text-orange-600 dark:text-orange-400">{{ number_format($suggestion['volume']) }} {{ __('searches/mo') }}</span>
                                @else
                                    <span>{{ __('checking search volume') }}</span>
                                @endif
                                @if (! empty($suggestion['products']))
                                    <span>{{ __('features :names', ['names' => implode(', ', array_slice($suggestion['products'], 0, 2))]) }}</span>
                                @endif
                            </div>
                            @if (! empty($suggestion['secondary_keywords']))
                                <p class="mt-1.5 text-xs text-slate-400 dark:text-slate-500">{{ __('Also covers') }}: {{ implode(' · ', array_slice($suggestion['secondary_keywords'], 0, 4)) }}</p>
                            @endif
                            <button type="button" wire:click="useSuggestion({{ $i }})" wire:loading.attr="disabled" wire:target="useSuggestion"
                                    @disabled(! $composerReplacing && ! count($composerDates))
                                    class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-bold text-orange-700 transition hover:bg-orange-600 hover:text-white disabled:opacity-50 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-300 dark:hover:bg-orange-600 dark:hover:text-white">
                                {{ $composerReplacing ? __('Write this instead') : __('Add to calendar') }}
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endif
