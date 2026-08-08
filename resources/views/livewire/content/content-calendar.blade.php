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
        {{-- ══ Post-onboarding SETTINGS: left tab nav (Alpine `tab`, panels
             x-show — all panels stay in the DOM so wire:model bindings and
             validation work regardless of the visible tab) ════════════ --}}
        {{-- Full page width, NOT mx-auto/max-w-*: the route view's "Content
             Settings" h1 spans the page, so a centered narrower column left the
             tabs visibly inset from the heading. --}}
        <div class="w-full" x-data="{ tab: 'profile' }">
            {{-- No heading here: the page view (content/settings.blade.php) already
                 renders the "Content Settings" h1 + subtitle. --}}
            <div class="mb-5 flex justify-end">
                <a href="{{ route('content.index') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                    {{ __('View calendar') }}
                </a>
            </div>

            <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
                {{-- Tab nav: horizontal scroll pills on mobile, sticky left rail on lg+. --}}
                @php
                    $settingsTabs = [
                        'profile' => ['label' => __('Business profile'), 'icon' => 'M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0'],
                        'offerings' => ['label' => __('Offerings'), 'icon' => 'M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3zM6 6h.008v.008H6V6z'],
                        'structure' => ['label' => __('Article structure'), 'icon' => 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zM3.75 12h.007v.008H3.75V12zm0 5.25h.007v.008H3.75v-.008z'],
                        'images' => ['label' => __('Images'), 'icon' => 'M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.375 19.5h17.25c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125z'],
                        'publishing' => ['label' => __('Publishing'), 'icon' => 'M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5'],
                        'protection' => ['label' => __('Brand protection'), 'icon' => 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z'],
                    ];
                @endphp
                <nav class="flex gap-1 overflow-x-auto rounded-2xl border border-slate-200 bg-white p-1.5 shadow-sm lg:sticky lg:top-24 lg:w-56 lg:shrink-0 lg:flex-col lg:p-2 dark:border-slate-800 dark:bg-slate-900" role="tablist">
                    @foreach ($settingsTabs as $tabKey => $tabDef)
                        <button type="button" role="tab" x-on:click="tab = '{{ $tabKey }}'"
                            :class="tab === '{{ $tabKey }}' ? 'bg-orange-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white'"
                            :aria-selected="tab === '{{ $tabKey }}' ? 'true' : 'false'"
                            class="flex shrink-0 items-center gap-2.5 whitespace-nowrap rounded-xl px-3.5 py-2.5 text-sm font-semibold transition lg:w-full">
                            <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $tabDef['icon'] }}"/></svg>
                            <span class="min-w-0 truncate">{{ $tabDef['label'] }}</span>
                            @if ($tabKey === 'profile' && $errors->any())
                                <span class="ms-auto h-1.5 w-1.5 shrink-0 rounded-full bg-error" title="{{ __('Needs attention') }}"></span>
                            @endif
                        </button>
                    @endforeach
                </nav>

                <div class="min-w-0 flex-1 space-y-5">
            <div x-show="tab === 'profile'">
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

            </div>

            {{-- Competitor mention protection --}}
            <div x-show="tab === 'protection'" x-cloak>
                @include('livewire.content.partials.competitor-guard', ['guard' => $guard ?? null])
            </div>

            {{-- Offerings --}}
            <div x-show="tab === 'offerings'" x-cloak>
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

            </div>

            {{-- Article structure --}}
            <div x-show="tab === 'structure'" x-cloak>
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

            </div>

            {{-- Images --}}
            <div x-show="tab === 'images'" x-cloak>
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

            </div>

            {{-- Publishing cadence --}}
            <div x-show="tab === 'publishing'" x-cloak>
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

                {{-- Auto-publish is ON but no destination is connected → articles can't
                     go live. Surface this prominently right under the toggle so the
                     setting doesn't silently do nothing. --}}
                @if ($autoPublish && ! $publishConnected)
                    <div class="mt-3 flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3.5 dark:border-amber-800/60 dark:bg-amber-950/40">
                        <svg class="mt-0.5 h-5 w-5 flex-none text-amber-600 dark:text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-amber-900 dark:text-amber-200">{{ __('Auto-publish is on, but no destination is connected') }}</p>
                            <p class="mt-0.5 text-xs text-amber-800 dark:text-amber-300">{{ __('Approved articles will be held and won\'t go live until you connect a publishing destination — WordPress, Laravel, or a custom webhook.') }}</p>
                            @if (\Illuminate\Support\Facades\Route::has('content.integrations'))
                                <a href="{{ route('content.integrations') }}" class="mt-2 inline-flex items-center gap-1 text-xs font-bold text-amber-900 underline underline-offset-2 dark:text-amber-200">
                                    {{ __('Connect a destination') }}
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                                </a>
                            @endif
                        </div>
                    </div>
                @endif

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

            </div>

            <div class="flex justify-end">
                <button type="button" wire:click="saveSettings" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('Save settings') }}
                </button>
            </div>
                </div>
            </div>
        </div>

    @elseif ($inWizard)
    @include('livewire.content.partials.wizard')
    @else
        {{-- ── Calendar ─────────────────────────────────────────────── --}}
        @php
            /* Literal status-chip classes, light AND dark. Never build these by
               interpolation ("bg-$color-100"): the Tailwind scanner can't see
               interpolated names, so such classes only survive if some other
               file happens to use the same literal — and their dark: variants
               never existed at all (chips rendered light-on-light in dark mode). */
            $chipClasses = [
                'slate' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
                'sky' => 'bg-sky-100 text-sky-700 dark:bg-sky-950/60 dark:text-sky-300',
                'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/60 dark:text-amber-300',
                'emerald' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300',
                'rose' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300',
                'orange' => 'bg-orange-100 text-orange-700 dark:bg-orange-950/60 dark:text-orange-300',
            ];
        @endphp
        <x-content.connect-integration />

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

        {{-- Trial generation limit reached → prompt to subscribe. --}}
        @if (($trialActive ?? false) && ($trialUsed ?? 0) >= ($trialCap ?? 0) && ($trialCap ?? 0) > 0)
            <div class="flex flex-col gap-3 rounded-2xl border border-orange-200 bg-orange-50 p-4 sm:flex-row sm:items-center dark:border-orange-900 dark:bg-orange-950">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-500 text-white">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('You\'ve used all :n free trial articles', ['n' => $trialCap]) }}</p>
                    <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{{ __('Subscribe to keep generating new articles — your planned topics stay ready and resume the moment you upgrade.') }}</p>
                </div>
                <a href="{{ route('content.get-started') }}" wire:navigate
                   class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-5 py-2.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('Subscribe') }}
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </a>
            </div>
        @endif

        {{-- Monthly article cap reached for this month. --}}
        @if (! empty($overCapIds))
            <div class="flex items-start gap-3 rounded-2xl border border-error/30 bg-error/5 p-4 text-sm dark:border-error/40">
                <svg class="mt-0.5 h-5 w-5 flex-none text-error" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 8v4m0 4h.01"/></svg>
                <p class="text-slate-700 dark:text-slate-200">{{ __('You can publish up to :cap articles a month on your plan. Articles past that (marked in red) won\'t be generated until next month or a higher plan.', ['cap' => $monthlyCap ?? 30]) }}</p>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <button wire:click="previousMonth" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-100" aria-label="{{ __('Previous month') }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                </button>
                <h2 class="min-w-[9rem] px-1 text-center text-sm font-bold text-slate-900 dark:text-slate-100">{{ $monthStart->translatedFormat('F Y') }}</h2>
                <button wire:click="nextMonth" class="flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-slate-100" aria-label="{{ __('Next month') }}">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </button>
            </div>
            <div class="inline-flex rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                @foreach (['grid' => __('Calendar'), 'list' => __('List')] as $mode => $modeLabel)
                    <button wire:click="$set('view', '{{ $mode }}')"
                        class="rounded-lg px-4 py-1.5 text-sm font-semibold transition {{ $view === $mode ? 'bg-orange-600 text-white shadow-sm' : 'text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white' }}">
                        {{ $modeLabel }}
                    </button>
                @endforeach
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
                ['label' => __('Planned'), 'value' => $stats['planned'], 'chip' => 'bg-sky-100 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400', 'icon' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5'],
                ['label' => __('In progress'), 'value' => $stats['in_progress'], 'chip' => 'bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400', 'icon' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['label' => __('Ready for review'), 'value' => $stats['ready'], 'chip' => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400', 'icon' => 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
                ['label' => __('Published'), 'value' => $stats['published'], 'chip' => 'bg-orange-100 text-orange-600 dark:bg-orange-950/60 dark:text-orange-400', 'icon' => 'M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5'],
            ] as $kpi)
                <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($kpi['value']) }}</span>
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl {{ $kpi['chip'] }}">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $kpi['icon'] }}"/></svg>
                        </span>
                    </div>
                    <div class="mt-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $kpi['label'] }}</div>
                </div>
            @endforeach
        </div>

        @if ($stats['from_search'] > 0 || $stats['monthly_searches'] > 0)
            <p class="text-sm text-slate-500 dark:text-slate-400">
                {{ __(':n topics come straight from what your audience already searches for', ['n' => $stats['from_search']]) }}@if ($stats['monthly_searches'] > 0), {{ __('targeting about :v searches every month', ['v' => number_format($stats['monthly_searches'])]) }}@endif.
            </p>
        @endif

        @if ($view === 'grid')
            @if ($topics->isEmpty())
                <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-500 shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-orange-50 to-slate-100 text-slate-400 dark:from-orange-950/40 dark:to-slate-800 dark:text-slate-500">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.375 19.5h17.25c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125z"/></svg>
                    </span>
                    <span>{{ __('No articles planned this month yet.') }} {{ __('Use the month arrows to browse your planned articles.') }}</span>
                </div>
            @endif
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="grid grid-cols-7 border-b border-slate-200 bg-slate-50/80 text-center text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-400" style="min-width:56rem">
                    @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $dow)
                        <div class="px-2 py-2.5">{{ $dow }}</div>
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
                             class="border-b border-e border-slate-100 p-1.5 align-top transition-colors dark:border-slate-800 {{ $day->format('Y-m') !== $month ? 'bg-slate-50/60 dark:bg-slate-950/40' : '' }}" style="min-height:9.5rem">
                            <div class="mb-1.5 flex justify-end">
                                @if ($day->isToday())
                                    <span class="flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-orange-600 px-1 text-xs font-bold text-white">{{ $day->day }}</span>
                                @else
                                    <span class="text-xs font-medium text-slate-400 dark:text-slate-500">{{ $day->day }}</span>
                                @endif
                            </div>
                            @foreach ($dayTopics as $topic)
                                @php
                                    $p = \App\Livewire\Content\ContentCalendar::statusPresentation($topic->status);
                                    $imgPending = \App\Livewire\Content\ContentCalendar::imagesPending($topic);
                                    $cellInFlight = in_array($topic->status, \App\Models\ContentTopic::IN_FLIGHT, true) || $imgPending;
                                    $canWrite = ! $cellInFlight && in_array($topic->status, ['suggested', 'approved', 'failed'], true);
                                    $canDrag = in_array($topic->status, ['suggested', 'approved', 'ready', 'scheduled'], true);
                                    $overCap = in_array($topic->id, $overCapIds ?? [], true);
                                    $hero = \App\Livewire\Content\ContentCalendar::heroImage($topic);
                                    $cellScore = $topic->currentArticle?->seo_score;
                                    $cellScoreColor = $cellScore >= 80 ? 'emerald' : ($cellScore >= 60 ? 'amber' : 'rose');
                                    $cellImages = $topic->currentArticle?->images?->count() ?? 0;
                                    $cellWords = $topic->currentArticle?->word_count ?? 0;
                                @endphp
                                {{-- The WHOLE card opens the topic page — people click the
                                     image and the card body, not just the 2-line title (rage
                                     clicks, 2026-08-08). The guard skips the card's own
                                     controls and text selection; drag is untouched because
                                     the handler fires on plain clicks only. --}}
                                <div wire:key="cell-{{ $topic->id }}" x-data="{ pick: false, opening: false, newDate: '{{ $topic->scheduled_for?->toDateString() }}' }"
                                     @if($canDrag) draggable="true"
                                        x-on:dragstart="drag.id = '{{ $topic->id }}'; $event.dataTransfer.setData('text/plain', '{{ $topic->id }}'); $event.dataTransfer.effectAllowed = 'move'"
                                        x-on:dragend="drag.id = null" @endif
                                     x-on:click="if (! $event.target.closest('button, a, input, [role=switch]') && ! window.getSelection()?.toString()) { opening = true; window.location = '{{ route('content.review', $topic->id) }}' }"
                                     x-on:pageshow.window="opening = false"
                                     role="link" tabindex="0" aria-label="{{ $topic->title }}"
                                     x-on:keydown.enter="opening = true; window.location = '{{ route('content.review', $topic->id) }}'"
                                     class="relative mb-1.5 cursor-pointer overflow-hidden rounded-lg border shadow-sm transition-shadow hover:shadow-md hover:border-orange-300 dark:hover:border-orange-500/50 {{ $overCap ? 'border-error/60 bg-error/5 dark:border-error/50' : ($cellInFlight ? 'border-orange-200 bg-orange-50 dark:border-orange-900 dark:bg-orange-950' : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800') }} {{ $canDrag ? 'active:cursor-grabbing' : '' }}">
                                    {{-- Opening feedback: navigation to the article page is not
                                         instant, and a silent click reads as broken (the rage-
                                         click report). pageshow resets it when the page is
                                         restored from the back/forward cache. --}}
                                    <div x-show="opening" x-cloak class="absolute inset-0 z-10 flex items-center justify-center bg-white/70 dark:bg-slate-900/70">
                                        <svg class="h-5 w-5 animate-spin text-orange-600" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                    </div>
                                    {{-- Hero image strip (featured first, else newest) — gradient placeholder when none. --}}
                                    <div class="relative h-14 w-full bg-gradient-to-br from-orange-50 to-slate-100 dark:from-orange-950/40 dark:to-slate-800">
                                        @if ($hero?->url())
                                            <img src="{{ $hero->url() }}" alt="{{ $hero->alt_text ?? $topic->title }}"
                                                 loading="lazy" decoding="async" draggable="false"
                                                 class="h-14 w-full object-cover" onerror="this.remove()">
                                        @else
                                            <span class="absolute inset-0 flex items-center justify-center text-slate-400 opacity-40 dark:text-slate-500">
                                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.375 19.5h17.25c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125z"/></svg>
                                            </span>
                                        @endif
                                    </div>
                                    <div class="p-1.5">
                                    @if ($topic->currentArticle || $cellInFlight)
                                        <a href="{{ route('content.review', $topic->id) }}" wire:navigate draggable="false" class="block hover:opacity-80" x-on:click="opening = true">
                                            <span class="break-words text-xs font-semibold text-slate-800 line-clamp-2 dark:text-slate-100">{{ $topic->title }}</span>
                                        </a>
                                    @else
                                        <a href="{{ route('content.review', $topic->id) }}" wire:navigate draggable="false" class="block hover:opacity-80" x-on:click="opening = true">
                                            <span class="break-words text-xs font-semibold text-slate-800 line-clamp-2 dark:text-slate-100">{{ $topic->title }}</span>
                                        </a>
                                    @endif
                                    @if ($cellScore || $cellImages > 0 || $cellWords > 0)
                                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[10px] text-slate-400 dark:text-slate-500">
                                            @if ($cellScore)
                                                <span class="inline-flex items-center rounded-full px-1.5 py-px font-bold {{ $chipClasses[$cellScoreColor] }}" title="{{ __('SEO score') }}">{{ __('SEO') }} {{ $cellScore }}</span>
                                            @endif
                                            @if ($cellWords > 0)
                                                <span>{{ number_format($cellWords) }} {{ __('words') }}</span>
                                            @endif
                                            @if ($cellImages > 0)
                                                <span class="inline-flex items-center gap-0.5" title="{{ __('Images') }}">
                                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.375 19.5h17.25c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125z"/></svg>
                                                    {{ $cellImages }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif
                                    <div class="mt-1 flex items-center justify-between gap-1">
                                        @php $chipColor = $imgPending ? 'amber' : $p['color']; @endphp
                                        <span class="inline-flex items-center gap-1 rounded-full px-1.5 py-px text-[10px] font-semibold {{ $chipClasses[$chipColor] ?? $chipClasses['slate'] }}">
                                            @if ($cellInFlight)
                                                <svg class="h-2.5 w-2.5 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                            @endif
                                            {{ $imgPending ? __('Finalizing images…') : $p['label'] }}
                                        </span>
                                        <span class="flex items-center gap-1">
                                            @if ($canDrag)
                                                {{-- Calendar icon → inline date picker (move to any day, incl. a 2nd on one day). --}}
                                                <button type="button" draggable="false" x-on:click="pick = !pick" title="{{ __('Change date') }}"
                                                        class="inline-flex shrink-0 items-center justify-center rounded-md p-0.5 text-slate-400 hover:text-orange-600">
                                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4.5" width="18" height="16.5" rx="2"/><path stroke-linecap="round" d="M3 9h18M8 2.5v4M16 2.5v4"/></svg>
                                                </button>
                                            @endif
                                            @if ($canWrite)
                                                <button wire:click="writeNow('{{ $topic->id }}')" wire:loading.attr="disabled" wire:target="writeNow('{{ $topic->id }}')"
                                                        class="inline-flex shrink-0 items-center gap-0.5 rounded-md bg-orange-600 px-1.5 py-0.5 text-[10px] font-bold text-white hover:bg-orange-700" title="{{ __('Write now') }}">
                                                    <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                                    {{ __('Write') }}
                                                </button>
                                            @endif
                                        </span>
                                    </div>
                                    @if ($canDrag)
                                        <div x-show="pick" x-cloak class="mt-1 flex items-center gap-1">
                                            <input type="date" draggable="false" x-model="newDate" min="{{ now()->toDateString() }}"
                                                   class="w-full rounded-md border border-slate-200 bg-white px-1.5 py-0.5 text-[10px] text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" />
                                            <button type="button" draggable="false" wire:key="gsave-{{ $topic->id }}"
                                                    x-on:click="$wire.reschedule('{{ $topic->id }}', newDate); pick = false"
                                                    class="inline-flex shrink-0 items-center justify-center rounded-md bg-orange-600 px-1.5 py-0.5 text-[10px] font-bold text-white hover:bg-orange-700" title="{{ __('Save date') }}">
                                                {{ __('Save') }}
                                            </button>
                                        </div>
                                    @endif
                                    @if ($overCap)
                                        <p class="mt-1 text-[10px] font-semibold text-error">{{ __('Over monthly limit') }}</p>
                                    @endif
                                    @if ($publishConnected && ! $imgPending && \App\Livewire\Content\ContentCalendar::publishableNow($topic))
                                        <button wire:click="publishNow('{{ $topic->id }}')" wire:confirm="{{ __('Publish this article to your site now?') }}" draggable="false"
                                                class="mt-1 inline-flex w-full items-center justify-center gap-0.5 rounded-md bg-success px-1.5 py-0.5 text-[10px] font-bold text-white hover:brightness-110">
                                            <svg class="h-2.5 w-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                                            {{ __('Publish now') }}
                                        </button>
                                    @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="hidden items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-2.5 text-[11px] font-bold uppercase tracking-wider text-slate-500 sm:flex dark:border-slate-800 dark:bg-slate-800/40 dark:text-slate-400">
                    <span class="w-40 shrink-0">{{ __('Date') }}</span>
                    <span class="w-20 shrink-0" aria-hidden="true"></span>
                    <span class="min-w-0 flex-1">{{ __('Article') }}</span>
                    <span>{{ __('Status & actions') }}</span>
                </div>
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($topics as $topic)
                        @php
                            $p = \App\Livewire\Content\ContentCalendar::statusPresentation($topic->status);
                            $imgPending = \App\Livewire\Content\ContentCalendar::imagesPending($topic);
                            $overCap = in_array($topic->id, $overCapIds ?? [], true);
                        @endphp
                        {{-- Whole row opens the topic page (same rage-click fix as
                             the grid cards); the guard skips the row's own controls. --}}
                        <div x-data="{ opening: false }"
                             x-on:click="if (! $event.target.closest('button, a, input, [role=switch]') && ! window.getSelection()?.toString()) { opening = true; window.location = '{{ route('content.review', $topic->id) }}' }"
                             x-on:pageshow.window="opening = false"
                             role="link" tabindex="0" aria-label="{{ $topic->title }}"
                             x-on:keydown.enter="opening = true; window.location = '{{ route('content.review', $topic->id) }}'"
                             class="relative flex cursor-pointer flex-wrap items-center gap-3 px-4 py-4 transition-colors hover:bg-slate-50/70 dark:hover:bg-slate-800/30 {{ $overCap ? 'bg-error/5' : '' }}">
                            <div x-show="opening" x-cloak class="absolute inset-0 z-10 flex items-center justify-center bg-white/70 dark:bg-slate-900/70">
                                <svg class="h-5 w-5 animate-spin text-orange-600" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            </div>
                            @php $canMove = in_array($topic->status, ['suggested', 'approved', 'ready', 'scheduled'], true); @endphp
                            <div class="w-40 shrink-0">
                                @if ($canMove)
                                    {{-- Pick a date, then Save to move it (any day/month). --}}
                                    <div class="flex items-center gap-1" x-data="{ d: '{{ $topic->scheduled_for?->toDateString() }}' }" wire:key="resched-{{ $topic->id }}">
                                        <input type="date" x-model="d" min="{{ now()->toDateString() }}"
                                            title="{{ __('Move to another day or month') }}"
                                            class="min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs text-slate-600 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300" />
                                        <button type="button" x-show="d && d !== '{{ $topic->scheduled_for?->toDateString() }}'"
                                                x-on:click="$wire.reschedule('{{ $topic->id }}', d)"
                                                class="inline-flex shrink-0 items-center rounded-lg bg-orange-600 px-2 py-1 text-xs font-bold text-white hover:bg-orange-700">{{ __('Save') }}</button>
                                    </div>
                                @else
                                    <span class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $topic->scheduled_for?->translatedFormat('M j') }}</span>
                                @endif
                            </div>
                            @php
                                $traffic = \App\Livewire\Content\ContentCalendar::fairMonthlyVisits($topic);
                                $inFlight = in_array($topic->status, \App\Models\ContentTopic::IN_FLIGHT, true);
                                $hero = \App\Livewire\Content\ContentCalendar::heroImage($topic);
                            @endphp
                            {{-- Hero thumbnail (featured first, else newest) — gradient placeholder when none. --}}
                            <div class="hidden h-12 w-20 shrink-0 overflow-hidden rounded-lg bg-gradient-to-br from-orange-50 to-slate-100 sm:block dark:from-orange-950/40 dark:to-slate-800">
                                @if ($hero?->url())
                                    <img src="{{ $hero->url() }}" alt="{{ $hero->alt_text ?? $topic->title }}"
                                         loading="lazy" decoding="async"
                                         class="h-full w-full object-cover" onerror="this.remove()">
                                @else
                                    <span class="flex h-full w-full items-center justify-center text-slate-400 opacity-40 dark:text-slate-500">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.375 19.5h17.25c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125z"/></svg>
                                    </span>
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="break-words text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $topic->title }}</div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
                                    <span>{{ $topic->target_keyword }}@if($topic->keyword_volume) · {{ number_format($topic->keyword_volume) }} {{ __('searches/mo') }}@endif</span>
                                    @if ($traffic)
                                        <span class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-px font-semibold text-success" title="{{ __('A fair, conservative estimate once this ranks — not a best case.') }}">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.256-1.011M21.75 6.75v5.25M21.75 6.75h-5.25"/></svg>
                                            ~{{ number_format($traffic['low']) }}–{{ number_format($traffic['high']) }} {{ __('visits/mo') }}
                                        </span>
                                    @endif
                                    @if ($topic->currentArticle?->seo_score)
                                        @php $scoreColor = $topic->currentArticle->seo_score >= 80 ? 'emerald' : ($topic->currentArticle->seo_score >= 60 ? 'amber' : 'rose'); @endphp
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-px font-semibold {{ $chipClasses[$scoreColor] }}" title="{{ __('SEO score') }}">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            {{ __('SEO') }} {{ $topic->currentArticle->seo_score }}
                                        </span>
                                    @endif
                                    @if (($topic->currentArticle->word_count ?? 0) > 0)
                                        <span>{{ number_format($topic->currentArticle->word_count) }} {{ __('words') }}</span>
                                    @endif
                                    @if (($rowImages = $topic->currentArticle?->images?->count() ?? 0) > 0)
                                        <span class="inline-flex items-center gap-1" title="{{ __('Images') }}">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.375 19.5h17.25c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125z"/></svg>
                                            {{ $rowImages }} {{ __('images') }}
                                        </span>
                                    @endif
                                    @if ($topic->source && ! in_array($topic->source, ['manual', 'llm'], true))
                                        <span class="rounded-full bg-slate-100 px-2 py-px font-medium text-slate-500 dark:bg-slate-800 dark:text-slate-400">{{ __('From search demand') }}</span>
                                    @endif
                                </div>
                            </div>
                            @php $chipColor = $imgPending ? 'amber' : $p['color']; @endphp
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $chipClasses[$chipColor] ?? $chipClasses['slate'] }}">{{ $imgPending ? __('Finalizing images…') : $p['label'] }}</span>
                            @if ($overCap)
                                <span class="rounded-full bg-error/10 px-2 py-0.5 text-xs font-bold text-error">{{ __('Over monthly limit') }}</span>
                            @endif
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
                        <div class="flex flex-col items-center gap-3 px-4 py-14 text-center">
                            <span class="flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-50 to-slate-100 text-slate-400 dark:from-orange-950/40 dark:to-slate-800 dark:text-slate-500">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M3.375 19.5h17.25c.621 0 1.125-.504 1.125-1.125V5.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v12.75c0 .621.504 1.125 1.125 1.125z"/></svg>
                            </span>
                            <div>
                                <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ __('No articles planned this month yet.') }}</p>
                                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Use the month arrows to browse your planned articles.') }}</p>
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>
        @endif

        {{-- ── Insight: what your audience searches ───────────────────
             $audienceSearches, NOT $audience: the component's public string
             $audience (wizard step-1 "Who is this for?") wins Livewire's
             view-data merge and shadowed the array → foreach-on-string 500
             (prod 2026-07-23). --}}
        @if (! empty($audienceSearches))
            <div class="rounded-xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center gap-2">
                    <svg class="h-4 w-4 text-orange-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('What your audience is searching for') }}</h3>
                </div>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('Real search demand behind your planned articles.') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($audienceSearches as $row)
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
            @php
                $palette = ['#F26419', '#0EA5E9', '#10B981', '#8B5CF6', '#EF4444', '#F59E0B', '#64748B'];
                $statusColor = ['ready' => '#10B981', 'scheduled' => '#10B981', 'published' => '#059669',
                    'writing' => '#F59E0B', 'researching' => '#F59E0B', 'scoring' => '#F59E0B', 'revising' => '#F59E0B',
                    'failed' => '#EF4444', 'approved' => '#0EA5E9', 'suggested' => '#94A3B8'];
            @endphp
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="flex items-center gap-2.5">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-orange-50 text-orange-600 dark:bg-orange-950/60 dark:text-orange-400">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25a2.25 2.25 0 01-2.25-2.25v-2.25z"/></svg>
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Content strategy map') }}</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ __('How your articles group into content pillars around your site.') }}</p>
                    </div>
                </div>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($clusters as $ci => $cluster)
                        @php $accent = $palette[$ci % count($palette)]; $topics = $cluster['topics']; $extra = count($topics) - 6; @endphp
                        <div class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50/40 dark:border-slate-800 dark:bg-slate-800/20" wire:key="pillar-{{ $ci }}">
                            <div class="flex items-center justify-between gap-2 border-s-4 bg-white px-3.5 py-2.5 dark:bg-slate-900" style="border-inline-start-color: {{ $accent }}">
                                <span class="truncate text-sm font-bold text-slate-800 dark:text-slate-100">{{ $cluster['theme'] }}</span>
                                <span class="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{{ count($topics) }}</span>
                            </div>
                            <ul class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach (array_slice($topics, 0, 6) as $topic)
                                    <li class="flex items-center gap-2 px-3.5 py-2">
                                        <span class="h-1.5 w-1.5 shrink-0 rounded-full" style="background: {{ $statusColor[$topic['status']] ?? '#94A3B8' }}"></span>
                                        <span class="break-words text-xs text-slate-600 dark:text-slate-300">{{ $topic['title'] }}</span>
                                    </li>
                                @endforeach
                                @if ($extra > 0)
                                    <li class="px-3.5 py-1.5 text-[11px] font-medium text-slate-400 dark:text-slate-500">+{{ $extra }} {{ __('more') }}</li>
                                @endif
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

    @endif
</div>
