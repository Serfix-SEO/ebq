<div class="space-y-6" x-data
    @if(($hasInFlight ?? false) || ($hasImagesPending ?? false)) wire:poll.5s @endif
    @content-settings-saved.window="window.scrollTo({ top: 0, behavior: 'smooth' })">
    @if (session('content-status'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)"
            x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="relative flex items-start gap-3 overflow-hidden rounded-2xl border border-success/25 bg-white p-4 ps-5 shadow-sm ring-1 ring-success/5 dark:border-success/25 dark:bg-slate-900">
            <span class="absolute inset-y-0 start-0 w-1 bg-gradient-to-b from-success to-emerald-600"></span>
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-success/10 text-success">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
            </span>
            <div class="min-w-0 flex-1 pt-0.5">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ session('content-status') }}</p>
            </div>
            <button type="button" @click="show = false" class="shrink-0 rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" aria-label="{{ __('Dismiss') }}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    @endif

    @if (session('content-error'))
        <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 8000)"
            class="relative flex items-start gap-3 overflow-hidden rounded-2xl border border-error/25 bg-white p-4 ps-5 shadow-sm dark:border-error/25 dark:bg-slate-900">
            <span class="absolute inset-y-0 start-0 w-1 bg-error"></span>
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-error/10 text-error">
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
            </span>
            <div class="min-w-0 flex-1 pt-0.5">
                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ session('content-error') }}</p>
                <a href="{{ route('content.integrations') }}" wire:navigate class="mt-0.5 inline-block text-xs font-medium text-orange-600 hover:text-orange-700">{{ __('Open Integrations →') }}</a>
            </div>
            <button type="button" @click="show = false" class="shrink-0 rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" aria-label="{{ __('Dismiss') }}">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    @endif

    @if (! $hasWebsite)
        <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
            {{ __('Add a website first to start planning content.') }}
        </div>
    @elseif ($needsSetup)
        {{-- ── No plan yet on the Calendar page: point to Settings ──── --}}
        <div class="mx-auto max-w-lg rounded-3xl border border-slate-200 bg-white p-10 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <span class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
            </span>
            <h2 class="text-xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('No content plan yet') }}</h2>
            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ __('Set up your business profile and offerings in Settings, and articles will start appearing here automatically.') }}</p>
            <a href="{{ route('content.settings') }}" class="mt-6 inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                {{ __('Go to Settings') }}
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
        </div>
    @elseif ($settingsView)
        {{-- ══ Post-onboarding SETTINGS layout (no stepper) ════════════ --}}
        <div class="mx-auto w-full max-w-4xl space-y-5">
            <div class="flex justify-end">
                <a href="{{ route('content.index') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                    {{ __('View calendar') }}
                </a>
            </div>

            {{-- Business profile --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Business profile') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('What your business does — used to research and write every article.') }}</p>
                <textarea wire:model="businessDescription" rows="4"
                    class="mt-3 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"></textarea>
                @error('businessDescription') <p class="mt-1.5 text-xs text-error">{{ $message }}</p> @enderror
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Language') }}</label>
                        <select wire:model="language" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            @foreach (\App\Support\KeywordFinderLocations::languageOptions() as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">{{ __('Articles are written in this language.') }}</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Target country') }}</label>
                        <select wire:model="country" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            @foreach (\App\Support\KeywordFinderLocations::countryOptions() as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-400">{{ __('Keyword research targets searches from this country.') }}</p>
                    </div>
                </div>
            </div>

            {{-- Offerings --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('What you sell') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Guides article topics toward what you actually offer.') }}</p>
                <div class="mt-3 grid gap-4 sm:grid-cols-2">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wide text-success">{{ __('We sell') }}</div>
                        <div class="mt-2 space-y-2">
                            @foreach ($sellItems as $i => $item)
                                <div class="flex items-center gap-2" wire:key="set-sell-{{ $i }}">
                                    <input wire:model="sellItems.{{ $i }}" type="text" class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                    <button type="button" wire:click="removeSell({{ $i }})" class="text-slate-300 hover:text-error" aria-label="{{ __('Remove') }}">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex gap-2">
                            <input wire:model="newSell" wire:keydown.enter.prevent="addSell" type="text" placeholder="{{ __('Add an offering') }}" class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                            <button type="button" wire:click="addSell" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-orange-600 text-white hover:brightness-110" aria-label="{{ __('Add') }}">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wide text-slate-400">{{ __("We don't sell") }}</div>
                        <div class="mt-2 space-y-2">
                            @foreach ($dontSellItems as $i => $item)
                                <div class="flex items-center gap-2" wire:key="set-dont-{{ $i }}">
                                    <input wire:model="dontSellItems.{{ $i }}" type="text" class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                    <button type="button" wire:click="removeDont({{ $i }})" class="text-slate-300 hover:text-error" aria-label="{{ __('Remove') }}">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex gap-2">
                            <input wire:model="newDont" wire:keydown.enter.prevent="addDont" type="text" placeholder="{{ __('Add an exclusion') }}" class="min-w-0 flex-1 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                            <button type="button" wire:click="addDont" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-slate-600 text-white hover:brightness-110" aria-label="{{ __('Add') }}">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Article structure --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('What goes into every article') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Turn these sections on or off. Applies to future articles.') }}</p>
                <div class="mt-4 space-y-2.5">
                    @php
                        $structOpts = [
                            ['key' => 'featured_image', 'title' => __('Featured image in article'), 'desc' => __('Show the featured image at the top of the article body. Turn off if your WordPress theme already displays it (avoids duplicates).')],
                            ['key' => 'key_takeaways', 'title' => __('Key takeaways'), 'desc' => __('A quick bullet summary near the top.')],
                            ['key' => 'toc', 'title' => __('“In this article” list'), 'desc' => __('A clickable table of contents after the intro.')],
                            ['key' => 'faq', 'title' => __('FAQ section'), 'desc' => __('Common questions answered at the end.')],
                        ];
                    @endphp
                    @foreach ($structOpts as $opt)
                        @php $on = (bool) ($structureToggles[$opt['key']] ?? true); @endphp
                        <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-100 p-3 dark:border-slate-800" wire:key="set-struct-{{ $opt['key'] }}">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ $opt['title'] }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ $opt['desc'] }}</div>
                            </div>
                            <button type="button" wire:click="toggleStructure('{{ $opt['key'] }}')" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}"
                                class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $on ? 'bg-orange-600' : 'bg-slate-300 dark:bg-slate-700' }}">
                                <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition {{ $on ? 'translate-x-6' : 'translate-x-1' }}"></span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Images --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Images') }}</h2>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Auto-generate a featured image and in-article visuals.') }}</p>
                    </div>
                    <button type="button" wire:click="toggleImages" role="switch" aria-checked="{{ $imagesEnabled ? 'true' : 'false' }}"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $imagesEnabled ? 'bg-orange-600' : 'bg-slate-300 dark:bg-slate-700' }}">
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition {{ $imagesEnabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>
                @if ($imagesEnabled)
                    <div class="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                        @foreach (\App\Support\ContentImageStyles::all() as $key => $st)
                            @php $sel = $imageStyle === $key; @endphp
                            <button type="button" wire:click="selectImageStyle('{{ $key }}')" wire:key="set-imgstyle-{{ $key }}"
                                class="rounded-xl border-2 p-3 text-start transition {{ $sel ? 'border-orange-500 bg-orange-50 dark:border-orange-500 dark:bg-orange-950' : 'border-slate-200 bg-white hover:border-orange-300 dark:border-slate-800 dark:bg-slate-900' }}">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ __($st['label']) }}</span>
                                    @if ($sel)
                                        <svg class="h-4 w-4 text-orange-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    @endif
                                </div>
                                <div class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __($st['desc']) }}</div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Publishing cadence --}}
            <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Publishing') }}</h2>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('How often we publish and how long each article runs.') }}</p>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Articles per week') }}</label>
                        <select wire:model="articlesPerWeek" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            @foreach ([1,2,3,4,5,6,7] as $n)
                                <option value="{{ $n }}">{{ trans_choice('{1} :count article/week|[2,*] :count articles/week', $n, ['count' => $n]) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Article length') }}</label>
                        <select wire:model="articleLength" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                            <option value="1500">{{ __('Concise (~1,500 words)') }}</option>
                            <option value="2000">{{ __('Standard (~2,000 words)') }}</option>
                            <option value="2500">{{ __('In-depth (~2,500 words)') }}</option>
                        </select>
                    </div>
                </div>
                <div class="mt-4 flex items-center justify-between gap-4 rounded-xl border border-slate-100 p-3 dark:border-slate-800">
                    <div class="min-w-0">
                        <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ __('Auto-publish') }}</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">{{ __('Publish approved articles automatically instead of waiting for manual approval.') }}</div>
                    </div>
                    <button type="button" wire:click="$toggle('autoPublish')" role="switch" aria-checked="{{ $autoPublish ? 'true' : 'false' }}"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition {{ $autoPublish ? 'bg-orange-600' : 'bg-slate-300 dark:bg-slate-700' }}">
                        <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition {{ $autoPublish ? 'translate-x-6' : 'translate-x-1' }}"></span>
                    </button>
                </div>

                {{-- Publish window: articles auto-publish only between these hours, in this timezone. --}}
                <div class="mt-4 rounded-xl border border-slate-100 p-3 dark:border-slate-800">
                    <div class="text-sm font-semibold text-slate-800 dark:text-slate-200">{{ __('Publish window') }}</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ __('Articles publish automatically between these hours. Anything already due publishes as soon as the window opens; you can also “Publish now” from the calendar anytime.') }}</div>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('From') }}</label>
                            <select wire:model="publishHourStart" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                @for ($h = 0; $h < 24; $h++)
                                    <option value="{{ $h }}">{{ sprintf('%d:00 %s', ($h % 12) ?: 12, $h < 12 ? 'AM' : 'PM') }}</option>
                                @endfor
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('To') }}</label>
                            <select wire:model="publishHourEnd" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                @for ($h = 0; $h < 24; $h++)
                                    <option value="{{ $h }}">{{ sprintf('%d:00 %s', ($h % 12) ?: 12, $h < 12 ? 'AM' : 'PM') }}</option>
                                @endfor
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400">{{ __('Timezone') }}</label>
                            <select wire:model="publishTimezone" class="mt-1 w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                @foreach (timezone_identifiers_list() as $tz)
                                    <option value="{{ $tz }}">{{ str_replace('_', ' ', $tz) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="button" wire:click="saveSettings" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('Save settings') }}
                </button>
            </div>
        </div>

    @elseif ($inWizard)
    @include('livewire.content.partials.wizard')
    @else
        {{-- ── Calendar ─────────────────────────────────────────────── --}}
        <x-content.connect-wordpress />

        {{-- Auto-publish off → nudge with a one-click toggle; hides once on. --}}
        @unless ($autoPublish)
            <div class="flex flex-col gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 sm:flex-row sm:items-center dark:border-amber-900/50 dark:bg-amber-950/40">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Auto-publish is off') }}</p>
                    <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ __('Articles wait as drafts for your review. Turn on auto-publish to publish them automatically as they\'re written.') }}</p>
                </div>
                <button type="button" wire:click="enableAutoPublish" wire:loading.attr="disabled" wire:target="enableAutoPublish"
                    class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:opacity-70">
                    <svg wire:loading wire:target="enableAutoPublish" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                    {{ __('Turn on auto-publish') }}
                </button>
            </div>
        @endunless

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-2">
                <button wire:click="previousMonth" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" aria-label="{{ __('Previous month') }}">&larr;</button>
                <h2 style="min-width:10rem" class="text-center text-base font-bold text-slate-900 dark:text-slate-100">{{ $monthStart->translatedFormat('F Y') }}</h2>
                <button wire:click="nextMonth" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800" aria-label="{{ __('Next month') }}">&rarr;</button>
            </div>
            <div class="flex items-center gap-2">
                <button wire:click="$set('view', '{{ $view === 'grid' ? 'list' : 'grid' }}')"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    {{ $view === 'grid' ? __('List view') : __('Calendar view') }}
                </button>
            </div>
        </div>

        {{-- Publish-window hint so clients aren't confused about when things go live. --}}
        <div class="flex flex-wrap items-center gap-2 rounded-xl border border-slate-100 bg-slate-50/60 px-4 py-2.5 text-xs text-slate-500 dark:border-slate-800 dark:bg-slate-900/40 dark:text-slate-400">
            <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span>{{ __('Auto-publishes daily between :window', ['window' => \App\Livewire\Content\ContentCalendar::publishWindowLabel($plan)]) }}.</span>
            <a href="{{ route('content.settings') }}" wire:navigate class="font-medium text-orange-600 hover:text-orange-700">{{ __('Change window →') }}</a>
            <span class="text-slate-400 dark:text-slate-500">{{ __('· To move an article to another month, switch to List view and pick a date.') }}</span>
        </div>

        {{-- ── Overview KPIs ────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @foreach ([
                ['label' => __('Planned'), 'value' => $stats['planned'], 'color' => 'sky'],
                ['label' => __('In progress'), 'value' => $stats['in_progress'], 'color' => 'amber'],
                ['label' => __('Ready for review'), 'value' => $stats['ready'], 'color' => 'emerald'],
                ['label' => __('Published'), 'value' => $stats['published'], 'color' => 'orange'],
            ] as $kpi)
                <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                    <div class="text-2xl font-bold text-{{ $kpi['color'] }}-600">{{ number_format($kpi['value']) }}</div>
                    <div class="mt-0.5 text-xs font-medium text-slate-500 dark:text-slate-400">{{ $kpi['label'] }}</div>
                </div>
            @endforeach
        </div>

        @if ($stats['from_search'] > 0 || $stats['monthly_searches'] > 0)
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ __(':n topics come straight from what your audience already searches for', ['n' => $stats['from_search']]) }}@if ($stats['monthly_searches'] > 0), {{ __('targeting about :v searches every month', ['v' => number_format($stats['monthly_searches'])]) }}@endif.
            </p>
        @endif

        @if ($view === 'grid')
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="grid grid-cols-7 border-b border-slate-200 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400" style="min-width:56rem">
                    @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $dow)
                        <div class="px-2 py-2">{{ $dow }}</div>
                    @endforeach
                </div>
                {{-- drag.id holds the topic being dragged. It MUST be a shared
                     object (not a bare primitive): each day cell has its own
                     x-data scope, and writing a primitive `dragId` from a cell
                     would shadow the parent instead of updating it, so a drop on
                     a DIFFERENT cell would read null. Mutating `drag.id` on the
                     shared object propagates across every cell. --}}
                <div class="grid grid-cols-7" style="min-width:56rem" x-data="{ drag: { id: null } }">
                    @foreach ($days as $day)
                        @php $dayTopics = $topicsByDate->get($day->toDateString(), collect()); @endphp
                        <div x-data="{ over: false }"
                             x-on:dragover.prevent="over = true"
                             x-on:dragleave="over = false"
                             x-on:drop="over = false; if (drag.id) { $wire.reschedule(drag.id, '{{ $day->toDateString() }}') }; drag.id = null"
                             :class="over && drag.id ? 'ring-2 ring-inset ring-orange-400' : ''"
                             class="border-b border-e border-slate-100 p-1.5 align-top dark:border-slate-800 {{ $day->format('Y-m') !== $month ? 'bg-slate-50 dark:bg-slate-950' : '' }}" style="min-height:6.5rem">
                            <div class="mb-1 text-end text-xs {{ $day->isToday() ? 'font-bold text-orange-600' : 'text-slate-400' }}">{{ $day->day }}</div>
                            @foreach ($dayTopics as $topic)
                                @php
                                    $p = \App\Livewire\Content\ContentCalendar::statusPresentation($topic->status);
                                    $imgPending = \App\Livewire\Content\ContentCalendar::imagesPending($topic);
                                    $cellInFlight = in_array($topic->status, \App\Models\ContentTopic::IN_FLIGHT, true) || $imgPending;
                                    $canWrite = ! $cellInFlight && in_array($topic->status, ['suggested', 'approved', 'failed'], true);
                                    $canDrag = in_array($topic->status, ['suggested', 'approved', 'ready', 'scheduled'], true);
                                @endphp
                                <div wire:key="cell-{{ $topic->id }}"
                                     @if($canDrag) draggable="true"
                                        x-on:dragstart="drag.id = '{{ $topic->id }}'; $event.dataTransfer.setData('text/plain', '{{ $topic->id }}'); $event.dataTransfer.effectAllowed = 'move'"
                                        x-on:dragend="drag.id = null" @endif
                                     class="mb-1 rounded-lg border p-1.5 {{ $cellInFlight ? 'border-orange-200 bg-orange-50 dark:border-orange-900 dark:bg-orange-950' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800' }} {{ $canDrag ? 'cursor-grab active:cursor-grabbing' : '' }}">
                                    @if ($topic->currentArticle || $cellInFlight)
                                        <a href="{{ route('content.review', $topic->id) }}" wire:navigate draggable="false" class="block hover:opacity-80">
                                            <span class="line-clamp-2 text-xs font-medium text-slate-800 dark:text-slate-100">{{ $topic->title }}</span>
                                        </a>
                                    @else
                                        <span class="line-clamp-2 text-xs font-medium text-slate-800 dark:text-slate-100">{{ $topic->title }}</span>
                                    @endif
                                    <div class="mt-1 flex items-center justify-between gap-1">
                                        @php $chipColor = $imgPending ? 'amber' : $p['color']; @endphp
                                        <span class="inline-flex items-center gap-1 rounded-full bg-{{ $chipColor }}-100 px-1.5 py-px text-[10px] font-semibold text-{{ $chipColor }}-700">
                                            @if ($cellInFlight)
                                                <svg class="h-2.5 w-2.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                            @endif
                                            {{ $imgPending ? __('Finalizing images…') : $p['label'] }}
                                        </span>
                                        @if ($canWrite)
                                            <button wire:click="writeNow('{{ $topic->id }}')" wire:loading.attr="disabled" wire:target="writeNow('{{ $topic->id }}')"
                                                    class="inline-flex shrink-0 items-center gap-0.5 rounded-md bg-orange-600 px-1.5 py-0.5 text-[10px] font-bold text-white hover:bg-orange-700" title="{{ __('Write now') }}">
                                                <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                                {{ __('Write') }}
                                            </button>
                                        @endif
                                    </div>
                                    @if ($publishConnected && ! $imgPending && \App\Livewire\Content\ContentCalendar::publishableNow($topic))
                                        <button wire:click="publishNow('{{ $topic->id }}')" wire:confirm="{{ __('Publish this article to your site now?') }}" draggable="false"
                                                class="mt-1 inline-flex w-full items-center justify-center gap-0.5 rounded-md bg-success px-1.5 py-0.5 text-[10px] font-bold text-white hover:brightness-110">
                                            <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                            {{ __('Publish now') }}
                                        </button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($topics as $topic)
                        @php
                            $p = \App\Livewire\Content\ContentCalendar::statusPresentation($topic->status);
                            $imgPending = \App\Livewire\Content\ContentCalendar::imagesPending($topic);
                        @endphp
                        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                            @php $canMove = in_array($topic->status, ['suggested', 'approved', 'ready', 'scheduled'], true); @endphp
                            <div class="w-28 shrink-0">
                                @if ($canMove)
                                    {{-- Reschedule to ANY date (drag on the grid is same-month only). --}}
                                    <input type="date" value="{{ $topic->scheduled_for?->toDateString() }}" min="{{ now()->toDateString() }}"
                                        wire:change="reschedule('{{ $topic->id }}', $event.target.value)" wire:key="resched-{{ $topic->id }}"
                                        title="{{ __('Move to another day or month') }}"
                                        class="w-full rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs text-slate-600 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" />
                                @else
                                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $topic->scheduled_for?->translatedFormat('M j') }}</span>
                                @endif
                            </div>
                            @php
                                $traffic = \App\Livewire\Content\ContentCalendar::fairMonthlyVisits($topic);
                                $inFlight = in_array($topic->status, \App\Models\ContentTopic::IN_FLIGHT, true);
                            @endphp
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $topic->title }}</div>
                                <div class="flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                                    <span>{{ $topic->target_keyword }}@if($topic->keyword_volume) · {{ number_format($topic->keyword_volume) }} {{ __('searches/mo') }}@endif</span>
                                    @if ($traffic)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-px font-semibold text-success" title="{{ __('A fair, conservative estimate once this ranks — not a best case.') }}">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.256-1.011M21.75 6.75v5.25M21.75 6.75h-5.25"/></svg>
                                            ~{{ number_format($traffic['low']) }}–{{ number_format($traffic['high']) }} {{ __('visits/mo') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                            @php $chipColor = $imgPending ? 'amber' : $p['color']; @endphp
                            <span class="rounded-full bg-{{ $chipColor }}-100 px-2 py-0.5 text-xs font-semibold text-{{ $chipColor }}-700">{{ $imgPending ? __('Finalizing images…') : $p['label'] }}</span>
                            @if ($inFlight)
                                <a href="{{ route('content.review', $topic->id) }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-orange-600 hover:text-orange-700">
                                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                    {{ __('Writing… view progress') }}
                                </a>
                            @elseif ($imgPending)
                                <span class="inline-flex items-center gap-1.5 text-sm font-medium text-amber-600">
                                    <svg class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                    {{ __('Finalizing images…') }}
                                </span>
                            @elseif ($topic->currentArticle)
                                <a href="{{ route('content.review', $topic->id) }}" class="text-sm font-medium text-orange-600 hover:text-orange-700">{{ __('Review') }}</a>
                            @endif
                            @if (! $inFlight && in_array($topic->status, ['suggested', 'approved', 'failed'], true))
                                <button wire:click="writeNow('{{ $topic->id }}')" class="inline-flex items-center gap-1 rounded-lg bg-orange-600 px-2.5 py-1 text-xs font-bold text-white hover:bg-orange-700">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                    {{ __('Write now') }}
                                </button>
                            @endif
                            @if ($topic->status === \App\Models\ContentTopic::STATUS_SUGGESTED)
                                <button wire:click="approve('{{ $topic->id }}')" class="text-sm font-medium text-success hover:brightness-90">{{ __('Approve') }}</button>
                            @endif
                            @if (! $imgPending && \App\Livewire\Content\ContentCalendar::publishableNow($topic))
                                @if ($publishConnected)
                                    <button wire:click="publishNow('{{ $topic->id }}')" wire:confirm="{{ __('Publish this article to your site now?') }}"
                                            class="inline-flex items-center gap-1 rounded-lg bg-success px-2.5 py-1 text-xs font-bold text-white hover:brightness-110">
                                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                        {{ __('Publish now') }}
                                    </button>
                                    @if ($topic->status === 'scheduled')
                                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ __('auto-publishes :date', ['date' => $topic->scheduled_for?->translatedFormat('M j') ?? __('soon')]) }}</span>
                                    @endif
                                @else
                                    <a href="{{ route('content.integrations') }}" wire:navigate class="text-xs font-medium text-orange-600 hover:text-orange-700">{{ __('Connect a site to publish →') }}</a>
                                @endif
                            @endif
                            @if (in_array($topic->status, ['suggested', 'approved', 'ready'], true))
                                <button wire:click="skip('{{ $topic->id }}')" class="text-sm text-slate-400 hover:text-slate-600">{{ __('Skip') }}</button>
                            @endif
                        </div>
                    @empty
                        <div class="px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400">
                            {{ __('No articles planned this month yet.') }}
                        </div>
                    @endforelse
                </div>
            </div>
        @endif

        {{-- ── Insight: what your audience searches ─────────────────── --}}
        @if (! empty($audience))
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('What your audience is searching for') }}</h3>
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Real search demand behind your planned articles.') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($audience as $row)
                        <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                            {{ $row['keyword'] }}
                            @if ($row['volume'])<span class="text-xs font-semibold text-orange-600">{{ number_format($row['volume']) }}/mo</span>@endif
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ── Content strategy map (topic clusters) ────────────────── --}}
        @if (! empty($clusters))
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Content strategy map') }}</h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('How your articles group into content pillars around your site.') }}</p>

                @php
                    $cx = 470; $cy = 330; $rings = 185;
                    $n = count($clusters);
                    $palette = ['#F26419', '#0EA5E9', '#10B981', '#8B5CF6', '#EF4444', '#F59E0B', '#64748B'];
                    $statusColor = ['ready' => '#10B981', 'scheduled' => '#10B981', 'published' => '#059669',
                        'writing' => '#F59E0B', 'researching' => '#F59E0B', 'scoring' => '#F59E0B', 'revising' => '#F59E0B',
                        'failed' => '#EF4444', 'approved' => '#0EA5E9', 'suggested' => '#94A3B8'];
                    $siteLabel = \Illuminate\Support\Str::of((string) ($plan->website?->domain ?? 'Site'))
                        ->before('.')->limit(10, '…')->value();
                @endphp
                <div class="mt-3 overflow-x-auto">
                    <svg viewBox="0 0 940 660" class="w-full" style="min-width:660px" role="img" aria-label="{{ __('Content strategy map') }}">
                        @foreach ($clusters as $ci => $cluster)
                            @php
                                $ang = $n <= 1 ? -M_PI/2 : (2*M_PI*$ci/$n) - M_PI/2;
                                $px = $cx + $rings * cos($ang); $py = $cy + $rings * sin($ang);
                                $color = $palette[$ci % count($palette)];
                                // Point labels outward (away from center) on the correct side.
                                $rightSide = $px >= $cx;
                                $shown = array_slice($cluster['topics'], 0, 5);
                                $m = count($shown);
                                $extra = count($cluster['topics']) - $m;
                            @endphp
                            <line x1="{{ round($cx,1) }}" y1="{{ round($cy,1) }}" x2="{{ round($px,1) }}" y2="{{ round($py,1) }}" stroke="{{ $color }}" stroke-width="2" opacity="0.45"/>
                            @foreach ($shown as $li => $topic)
                                @php
                                    // Fan leaves along a vertical-ish comb on the outward side.
                                    $step = 26; $ly = $py + ($li - ($m-1)/2) * $step;
                                    $lx = $px + ($rightSide ? 46 : -46);
                                    $sc = $statusColor[$topic['status']] ?? '#94A3B8';
                                    $anchor = $rightSide ? 'start' : 'end';
                                    $tx = $lx + ($rightSide ? 9 : -9);
                                @endphp
                                <path d="M {{ round($px,1) }} {{ round($py,1) }} C {{ round(($px+$lx)/2,1) }} {{ round($py,1) }}, {{ round(($px+$lx)/2,1) }} {{ round($ly,1) }}, {{ round($lx,1) }} {{ round($ly,1) }}" fill="none" stroke="{{ $color }}" stroke-width="1.2" opacity="0.35"/>
                                <circle cx="{{ round($lx,1) }}" cy="{{ round($ly,1) }}" r="5" fill="{{ $sc }}"/>
                                <text x="{{ round($tx,1) }}" y="{{ round($ly+3.5,1) }}" font-size="11" fill="#64748b" text-anchor="{{ $anchor }}">{{ \Illuminate\Support\Str::limit($topic['title'], 30) }}</text>
                            @endforeach
                            @if ($extra > 0)
                                <text x="{{ round($px + ($rightSide ? 55 : -55),1) }}" y="{{ round($py + ($m/2)*26 + 12,1) }}" font-size="10" fill="#94a3b8" text-anchor="{{ $rightSide ? 'start' : 'end' }}">+{{ $extra }} {{ __('more') }}</text>
                            @endif
                            <circle cx="{{ round($px,1) }}" cy="{{ round($py,1) }}" r="9" fill="{{ $color }}"/>
                            <text x="{{ round($px,1) }}" y="{{ round($py - 15,1) }}" font-size="13" font-weight="700" fill="{{ $color }}" text-anchor="middle">{{ $cluster['theme'] }}</text>
                        @endforeach
                        <circle cx="{{ $cx }}" cy="{{ $cy }}" r="42" fill="#F26419"/>
                        <text x="{{ $cx }}" y="{{ $cy - 1 }}" font-size="11" font-weight="700" fill="#fff" text-anchor="middle">{{ $siteLabel }}</text>
                        <text x="{{ $cx }}" y="{{ $cy + 14 }}" font-size="9" fill="#fff" text-anchor="middle" opacity="0.85">{{ __('your content') }}</text>
                    </svg>
                </div>
            </div>
        @endif

    @endif
</div>
