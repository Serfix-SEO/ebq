        {{-- ══ Setup wizard (5 steps) ══════════════════════════════════ --}}
        @php
            $publicOnboarding = $publicOnboarding ?? false;
            $steps = [1 => __('Business'), 2 => __('Offerings'), 3 => __('How it works'), 4 => __('Images'), 5 => __('Competitors'), 6 => __('Keyword research'), 7 => __('First articles')];
            if ($publicOnboarding) {
                $steps[8] = __('Create account');
            }
            $maxUnlocked = $draftPlanId !== null ? 6 : 2;
            $stepCount = count($steps);
            $fillPct = $stepCount > 1 ? (($wizardStep - 1) / ($stepCount - 1)) * 100 : 0;
            // Each step circle sits centered in its own 100/stepCount-wide slot,
            // so its center is half a slot in from each edge — the connector
            // line must start/end there too, or it overshoots past the first
            // and last circles (visibly sticking out either side).
            $halfSlot = 100 / $stepCount / 2;
            $trackWidth = 100 - (2 * $halfSlot);
            $fillWidth = $trackWidth * $fillPct / 100;
        @endphp
        <div class="mx-auto w-full max-w-6xl"
             x-data="{ step: @entangle('wizardStep') }"
             x-init="$watch('step', () => window.scrollTo({ top: 0, behavior: 'smooth' }))">
            {{-- Progress rail --}}
            <div class="mb-8 px-2 sm:px-6">
                <div class="relative">
                    <div class="absolute h-1 rounded-full bg-slate-200 dark:bg-slate-800" style="top:0.875rem; inset-inline-start: {{ $halfSlot }}%; inset-inline-end: {{ $halfSlot }}%"></div>
                    <div class="absolute h-1 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 transition-all duration-200" style="top:0.875rem; inset-inline-start: {{ $halfSlot }}%; width: {{ $fillWidth }}%"></div>
                    <div class="relative flex items-start justify-between">
                        @foreach ($steps as $s => $label)
                            <button type="button" @if($s <= $maxUnlocked) wire:click="goToStep({{ $s }})" @endif
                                class="flex flex-col items-center gap-2 {{ $s <= $maxUnlocked ? 'cursor-pointer' : 'cursor-default' }}" style="width: {{ 100 / $stepCount }}%">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold shadow-sm transition-all
                                    {{ $wizardStep === $s ? 'bg-gradient-to-br from-orange-500 to-orange-600 text-white ring-2 ring-orange-100 dark:ring-orange-900'
                                       : ($wizardStep > $s ? 'bg-gradient-to-br from-orange-500 to-orange-600 text-white' : 'bg-white text-slate-400 ring-2 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700') }}">
                                    @if ($wizardStep > $s)
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    @else
                                        {{ $s }}
                                    @endif
                                </span>
                                <span class="hidden text-center text-xs font-semibold leading-tight sm:block {{ $wizardStep >= $s ? 'text-orange-600 dark:text-orange-400' : 'text-slate-400' }}">{{ $label }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="relative overflow-hidden rounded-3xl border border-slate-200 bg-white p-6 shadow-xl sm:p-8 dark:border-slate-800 dark:bg-slate-900">
                {{-- decorative brand glow --}}
                <div class="pointer-events-none absolute -top-10 end-0 h-56 w-56 rounded-full bg-gradient-to-br from-orange-500 to-orange-600 opacity-20 blur-3xl"></div>

                {{-- Transition overlay: step moves can be slow (they persist the plan,
                     dispatch research, and the next step's first render pulls live data).
                     Show an interactive "working" state so the click never feels dead. --}}
                <div wire:loading.flex wire:target="analyzeSite,toOfferings,toHowItWorks,toImages,toCompetitors,loadCompetitors,toKeywordResearch,toFirstArticles,toAccount,createAccount"
                     class="absolute inset-0 z-30 hidden flex-col items-center justify-center gap-4 rounded-3xl bg-white/85 text-center backdrop-blur-sm dark:bg-slate-900/85">
                    <span class="relative flex h-14 w-14 items-center justify-center">
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-orange-400 opacity-40"></span>
                        <svg class="h-10 w-10 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                    </span>
                    <div>
                        <p class="text-sm font-bold text-slate-900 dark:text-slate-100">
                            <span wire:loading wire:target="analyzeSite">{{ __('Reading your website…') }}</span>
                            <span wire:loading wire:target="toOfferings">{{ __('Saving your business profile…') }}</span>
                            <span wire:loading wire:target="toHowItWorks">{{ __('Building your content plan…') }}</span>
                            <span wire:loading wire:target="toCompetitors,loadCompetitors">{{ __('Finding your competitors…') }}</span>
                            <span wire:loading wire:target="toKeywordResearch">{{ __('Researching keywords for your site…') }}</span>
                            <span wire:loading wire:target="toFirstArticles">{{ __('Lining up your first articles…') }}</span>
                            <span wire:loading wire:target="createAccount">{{ __('Creating your account…') }}</span>
                            <span wire:loading wire:target="toImages">{{ __('One moment…') }}</span>
                        </p>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('This can take a few seconds — please keep this tab open.') }}</p>
                    </div>
                </div>

                <div class="relative">
                @error('plan')
                    <p class="mb-4 rounded-xl bg-error/10 px-4 py-3 text-sm font-medium text-error">{{ $message }}</p>
                @enderror

                {{-- ── Step 1: business ─────────────────────────────── --}}
                @if ($wizardStep === 1)
                    <div @if($analyzing) wire:init="analyzeSite" @endif>
                        <div class="flex items-center gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                                <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15l-.75 18h-13.5L4.5 3zM9 3v18M15 3v18"/></svg>
                            </span>
                            <div>
                                <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 1 of 7') }}</p>
                                <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Tell us about your business') }}</h2>
                            </div>
                        </div>
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ __('We analyzed your website and filled this in. Adjust anything so every article fits perfectly.') }}</p>

                        @if ($analyzing)
                            <div class="mt-5 flex items-center gap-3 rounded-xl border border-orange-200 bg-orange-50 px-4 py-3.5 text-sm font-medium text-orange-800 dark:border-orange-900 dark:bg-orange-950 dark:text-orange-200">
                                <svg class="h-5 w-5 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                {{ __('Analyzing your website…') }}
                            </div>
                        @endif

                        <div class="mt-6 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300" for="w-brand">{{ __('Brand name') }}</label>
                                <input id="w-brand" wire:model="brandName" type="text"
                                    class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-800 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300" for="w-lang">{{ __('Article language') }}</label>
                                <select id="w-lang" wire:model="language"
                                    class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white pl-3 pr-8 py-2.5 text-sm text-slate-800 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                                    <option value="en">English</option>
                                    <option value="ar">العربية</option>
                                </select>
                            </div>
                        </div>

                        <label class="mt-5 block text-sm font-semibold text-slate-700 dark:text-slate-300" for="w-desc">{{ __('What does your business do?') }}</label>
                        <textarea id="w-desc" wire:model="businessDescription" rows="4" maxlength="1000"
                            class="mt-1.5 w-full rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm text-slate-800 shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
                            placeholder="{{ __('Describe your products, services, and who they are for…') }}"></textarea>
                        @error('businessDescription') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror

                        <div class="mt-7 flex justify-end">
                            <button wire:click="toOfferings" wire:loading.attr="disabled" wire:target="toOfferings" @disabled($analyzing) class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 transition-transform hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-70"><svg wire:loading wire:target="toOfferings" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                {{ __('Continue') }}
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                            </button>
                        </div>
                    </div>

                {{-- ── Step 2: offerings ────────────────────────────── --}}
                @elseif ($wizardStep === 2)
                    <div class="flex items-center gap-3">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.1-.9 2-2 2H5.75c-1.1 0-2-.9-2-2V9.85c0-1.1.9-2 2-2h4.25M20.25 14.15L15 14.15M20.25 14.15l-4.5-4.5M15 4.5h5.25v5.25"/></svg>
                        </span>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 2 of 7') }}</p>
                            <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('What you offer') }}</h2>
                        </div>
                    </div>
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">{{ __('So we write about what you actually sell, and avoid what you don\'t. Order the top list by importance.') }}</p>

                    <div class="mt-6 grid gap-4 sm:grid-cols-2">
                        {{-- Sell --}}
                        <div class="rounded-3xl border border-success/20 bg-gradient-to-b from-success/10 to-white p-5 dark:border-success/30 dark:from-success/5 dark:to-slate-900">
                            <div class="flex items-center justify-between">
                                <h3 class="flex items-center gap-2.5 text-sm font-extrabold text-slate-900 dark:text-slate-100">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-2xl bg-success text-white shadow-lg shadow-success/30">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    </span>
                                    {{ __('What you sell') }}
                                </h3>
                                <span class="rounded-full bg-success/15 px-2.5 py-1 text-xs font-bold text-success">{{ count($sellItems) }}</span>
                            </div>
                            <p class="mt-1.5 text-xs font-medium text-success/80">{{ __('Most important first — drag to reorder') }}</p>
                            <div class="mt-4 space-y-2" x-data="{ dragIndex: null }">
                                @forelse ($sellItems as $i => $item)
                                    <div
                                        draggable="true"
                                        x-on:dragstart="dragIndex = {{ $i }}"
                                        x-on:dragover.prevent
                                        x-on:drop="if (dragIndex !== null && dragIndex !== {{ $i }}) { $wire.reorderSell(dragIndex, {{ $i }}) }; dragIndex = null"
                                        x-on:dragend="dragIndex = null"
                                        :class="dragIndex === {{ $i }} ? 'opacity-40' : ''"
                                        class="group flex items-center gap-2 rounded-xl border border-success/15 bg-white px-3 py-2.5 shadow-sm transition hover:border-success/40 hover:shadow-md dark:border-slate-700 dark:bg-slate-800"
                                        wire:key="sell-{{ $i }}">
                                        <span class="cursor-grab text-slate-300 hover:text-success active:cursor-grabbing dark:text-slate-600" title="{{ __('Drag to reorder') }}">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="currentColor">
                                                <circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/>
                                                <circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>
                                                <circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/>
                                            </svg>
                                        </span>
                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-success/15 text-xs font-extrabold text-success">{{ $i + 1 }}</span>
                                        <input wire:model="sellItems.{{ $i }}" type="text" title="{{ $item }}" class="min-w-0 flex-1 truncate border-0 bg-transparent p-0 text-sm font-medium text-slate-800 focus:ring-0 dark:text-slate-100" />
                                        <button type="button" wire:click="removeSell({{ $i }})" class="shrink-0 text-slate-300 opacity-0 transition hover:text-error group-hover:opacity-100" aria-label="{{ __('Remove') }}">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                @empty
                                    <p class="rounded-xl border border-dashed border-success/25 bg-white/60 px-3 py-4 text-center text-sm text-slate-500 dark:bg-transparent dark:text-slate-400">{{ __('Add the products, tools, or services you offer.') }}</p>
                                @endforelse
                            </div>
                            <div class="mt-3 flex gap-2">
                                <input wire:model="newSell" wire:keydown.enter.prevent="addSell" type="text" placeholder="{{ __('Add an offering…') }}"
                                    class="min-w-0 flex-1 rounded-xl border border-success/25 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-success focus:outline-none focus:ring-1 focus:ring-success dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                <button type="button" wire:click="addSell" aria-label="{{ __('Add') }}" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-success text-white shadow-lg shadow-success/30 hover:brightness-110">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                </button>
                            </div>
                        </div>

                        {{-- Don't sell --}}
                        <div class="rounded-3xl border border-error/20 bg-gradient-to-b from-error/10 to-white p-5 dark:border-error/30 dark:from-error/5 dark:to-slate-900">
                            <div class="flex items-center justify-between">
                                <h3 class="flex items-center gap-2.5 text-sm font-extrabold text-slate-900 dark:text-slate-100">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-2xl bg-error text-white shadow-lg shadow-error/30">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </span>
                                    {{ __("What you don't sell") }}
                                </h3>
                                <span class="rounded-full bg-error/15 px-2.5 py-1 text-xs font-bold text-error">{{ count($dontSellItems) }}</span>
                            </div>
                            <p class="mt-1.5 text-xs font-medium text-error/80">{{ __("Keeps us off the wrong products") }}</p>
                            <div class="mt-4 space-y-2">
                                @forelse ($dontSellItems as $i => $item)
                                    <div class="group flex items-center gap-2 rounded-xl border border-error/15 bg-white px-3 py-2.5 shadow-sm transition hover:border-error/40 hover:shadow-md dark:border-slate-700 dark:bg-slate-800" wire:key="dont-{{ $i }}">
                                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-error/15 text-error">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </span>
                                        <input wire:model="dontSellItems.{{ $i }}" type="text" title="{{ $item }}" class="min-w-0 flex-1 truncate border-0 bg-transparent p-0 text-sm font-medium text-slate-800 focus:ring-0 dark:text-slate-100" />
                                        <button type="button" wire:click="removeDont({{ $i }})" class="shrink-0 text-slate-300 opacity-0 transition hover:text-error group-hover:opacity-100" aria-label="{{ __('Remove') }}">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                @empty
                                    <p class="rounded-xl border border-dashed border-error/25 bg-white/60 px-3 py-4 text-center text-sm text-slate-500 dark:bg-transparent dark:text-slate-400">{{ __("Add related things you don't offer.") }}</p>
                                @endforelse
                            </div>
                            <div class="mt-3 flex gap-2">
                                <input wire:model="newDont" wire:keydown.enter.prevent="addDont" type="text" placeholder="{{ __('Add something you don\'t offer…') }}"
                                    class="min-w-0 flex-1 rounded-xl border border-error/25 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-error focus:outline-none focus:ring-1 focus:ring-error dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                                <button type="button" wire:click="addDont" aria-label="{{ __('Add') }}" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-error text-white shadow-lg shadow-error/30 hover:brightness-110">
                                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-7 flex items-center justify-between">
                        <button wire:click="goToStep(1)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            {{ __('Back') }}
                        </button>
                        <button wire:click="toHowItWorks" wire:loading.attr="disabled" wire:target="toHowItWorks" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-70"><svg wire:loading wire:target="toHowItWorks" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            <span wire:loading.remove wire:target="toHowItWorks" class="inline-flex items-center gap-1.5">{{ __('Continue') }}
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                            </span>
                            <span wire:loading wire:target="toHowItWorks">{{ __('Saving…') }}</span>
                        </button>
                    </div>

                {{-- ── Step 3: how it works ─────────────────────────── --}}
                @elseif ($wizardStep === 3)
                    <div class="text-center">
                        <span class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </span>
                        <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 3 of 7') }}</p>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('How this grows your traffic') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Fully automatic. You just review and approve.') }}</p>
                    </div>
                    @php
                        $hiwColors = [
                            ['solid' => 'bg-gradient-to-br from-orange-500 to-orange-600', 'shadow' => 'shadow-orange-600/25', 'tag' => 'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300'],
                            ['solid' => 'bg-emerald-600', 'shadow' => 'shadow-lg', 'tag' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'],
                            ['solid' => 'bg-amber-600', 'shadow' => 'shadow-lg', 'tag' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'],
                        ];
                        $hiwIcons = [
                            'M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z',
                            'M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z',
                            'M2.25 18L9 11.25l4.306 4.306a11.95 11.95 0 015.814-5.518l2.256-1.011M21.75 6.75v5.25M21.75 6.75h-5.25',
                        ];
                    @endphp
                    <div class="relative mx-auto mt-8 max-w-2xl">
                        <div class="absolute w-0.5 bg-slate-200 dark:bg-slate-700" style="inset-inline-start:1.5rem; top:0.75rem; bottom:0.75rem"></div>
                        <div class="space-y-6">
                            @foreach ([
                                ['n' => 1, 'title' => __('Deep research on your business'), 'body' => __('We study your site, your competitors, and real Google searches to find what your customers actually look for.'), 'tag' => __('Grounded in your real data')],
                                ['n' => 2, 'title' => __('One expert article, every day'), 'body' => __('A genuinely useful article that answers a real customer question, checked against technical SEO before it reaches you.'), 'tag' => __('1,500–2,500 words each')],
                                ['n' => 3, 'title' => __('Watch your traffic grow'), 'body' => __('Search engines and AI assistants surface your articles. New customers find you, on autopilot.'), 'tag' => __('You review and approve everything')],
                            ] as $i => $step)
                                <div class="relative flex gap-4">
                                    <span class="relative z-10 flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl {{ $hiwColors[$i]['solid'] }} text-white {{ $hiwColors[$i]['shadow'] }}">
                                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $hiwIcons[$i] }}"/></svg>
                                    </span>
                                    <div class="pt-1">
                                        <h3 class="text-base font-bold text-slate-900 dark:text-slate-100">{{ $step['title'] }}</h3>
                                        <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ $step['body'] }}</p>
                                        <span class="mt-2 inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-bold {{ $hiwColors[$i]['tag'] }}">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            {{ $step['tag'] }}
                                        </span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                    {{-- Article structure — what goes into every article --}}
                    <div class="mx-auto mt-8 max-w-2xl rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('What goes into every article') }}</h3>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Turn these sections on or off. Applies to future articles.') }}</p>
                        <div class="mt-4 space-y-2.5">
                            @php
                                $structOpts = [
                                    ['key' => 'key_takeaways', 'title' => __('Key takeaways'), 'desc' => __('A quick bullet summary near the top.')],
                                    ['key' => 'toc', 'title' => __('“In this article” list'), 'desc' => __('A clickable table of contents after the intro.')],
                                    ['key' => 'faq', 'title' => __('FAQ section'), 'desc' => __('Common questions answered at the end.')],
                                ];
                            @endphp
                            @foreach ($structOpts as $opt)
                                @php $on = (bool) ($structureToggles[$opt['key']] ?? true); @endphp
                                <div class="flex items-center justify-between gap-4 rounded-xl border border-slate-100 p-3 dark:border-slate-800" wire:key="struct-{{ $opt['key'] }}">
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

                    <div class="mt-8 flex items-center justify-between">
                        <button wire:click="goToStep(2)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            {{ __('Back') }}
                        </button>
                        <button wire:click="toImages" wire:loading.attr="disabled" wire:target="toImages" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-70"><svg wire:loading wire:target="toImages" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            {{ __('Continue') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                        </button>
                    </div>

                {{-- ── Step 4: images ───────────────────────────────── --}}
                @elseif ($wizardStep === 4)
                    <div class="text-center">
                        <span class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M18 8.25h.008v.008H18V8.25zm.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM3 19.5V6a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 6v13.5A2.25 2.25 0 0118.75 21H5.25A2.25 2.25 0 013 19.5z"/></svg>
                        </span>
                        <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 4 of 7') }}</p>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Images for your articles') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('We can generate a featured image and in-article visuals for every post.') }}</p>
                    </div>

                    {{-- Enable toggle --}}
                    <div class="mx-auto mt-6 flex max-w-2xl items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                        <div class="min-w-0">
                            <div class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Generate images') }}</div>
                            <div class="text-xs text-slate-500 dark:text-slate-400">{{ __('Turn off if you prefer to add your own images.') }}</div>
                        </div>
                        <button type="button" wire:click="toggleImages" role="switch" aria-checked="{{ $imagesEnabled ? 'true' : 'false' }}"
                            class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition {{ $imagesEnabled ? 'bg-orange-600' : 'bg-slate-300 dark:bg-slate-700' }}">
                            <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $imagesEnabled ? 'translate-x-6' : 'translate-x-1' }}"></span>
                        </button>
                    </div>

                    {{-- Style picker (only when enabled) --}}
                    @if ($imagesEnabled)
                        <div class="mx-auto mt-5 max-w-2xl">
                            <div class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Pick a visual style') }}</div>
                            <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3">
                                @foreach (\App\Support\ContentImageStyles::all() as $key => $st)
                                    @php $sel = $imageStyle === $key; @endphp
                                    <button type="button" wire:click="selectImageStyle('{{ $key }}')" wire:key="imgstyle-{{ $key }}"
                                        class="rounded-2xl border-2 p-4 text-start transition {{ $sel ? 'border-orange-500 bg-orange-50 dark:border-orange-500 dark:bg-orange-950' : 'border-slate-200 bg-white hover:border-orange-300 dark:border-slate-800 dark:bg-slate-900' }}">
                                        <div class="flex items-center justify-between">
                                            <span class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __($st['label']) }}</span>
                                            @if ($sel)
                                                <svg class="h-4 w-4 text-orange-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            @endif
                                        </div>
                                        <div class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __($st['desc']) }}</div>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="mt-8 flex items-center justify-between">
                        <button wire:click="goToStep(3)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            {{ __('Back') }}
                        </button>
                        <button wire:click="toCompetitors" wire:loading.attr="disabled" wire:target="toCompetitors" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-70"><svg wire:loading wire:target="toCompetitors" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            {{ __('Continue') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                        </button>
                    </div>

                {{-- ── Step 5: competitors ──────────────────────────── --}}
                @elseif ($wizardStep === 5)
                    @php
                        $ins = $wizard['insights'] ?? null;
                        $generating = $wizard['generating'] ?? false;
                        $needsReportGen = $wizard['needsReportGen'] ?? false;
                        $hasOverrides = $wizard['hasOverrides'] ?? false;
                        // Fresh site: no own backlink footprint → SERP-ranking framing
                        // (no authority comparison anywhere on the step).
                        $fresh = $ins !== null && (int) ($ins['my_referring_domains'] ?? 0) < 1;
                        // Per-competitor metric columns show whenever ANY competitor
                        // actually has metrics (e.g. a manually-added one) — only the
                        // all-empty fresh auto-list hides them.
                        $showCompMetrics = ! $fresh || collect($ins['competitors'] ?? [])->contains(
                            fn ($c) => ($c['referring_domains'] ?? null) !== null
                                || ($c['da'] ?? null) !== null || ($c['backlinks'] ?? null) !== null
                        );
                    @endphp
                    <div class="relative text-center" @if($needsReportGen) wire:init="loadCompetitors" @endif>
                        <div class="absolute end-0 top-0 flex items-center gap-3 text-xs font-semibold">
                            <button type="button" wire:click="refreshCompetitors" class="inline-flex items-center gap-1 text-slate-400 hover:text-orange-600 dark:text-slate-500">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                                {{ __('Refetch') }}
                            </button>
                            @if ($hasOverrides)
                                <button type="button" wire:click="resetCompetitors" wire:confirm="{{ __('Reset to the auto-discovered list? Your manual adds and removals will be lost.') }}" class="inline-flex items-center gap-1 text-slate-400 hover:text-error dark:text-slate-500">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
                                    {{ __('Reset') }}
                                </button>
                            @endif
                        </div>
                        <span class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 5 of 7') }}</p>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ $fresh ? __('Who you\'re competing with') : __('Your competitors and their authority') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $fresh ? __('Sites already ranking for your target searches — the competitors your articles will be up against.') : __('How your backlink profile compares to others in your space.') }}</p>
                    </div>

                    @if ($ins && ! empty($ins['competitors']))
                        {{-- Established site only: authority comparison (referring domains /
                             median / gap). Fresh sites ($fresh) skip it — the header already
                             frames the step as a plain competitor list. --}}
                        @unless ($fresh)
                            @if ($ins['behind'])
                                <div class="mx-auto mt-4 inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-3.5 py-1.5 text-xs font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                                    {{ __('Room to grow') }}
                                </div>
                            @endif
                            <div class="mt-5 grid gap-3 sm:grid-cols-3">
                                <div class="rounded-2xl border border-orange-200 bg-orange-50 p-5 dark:border-orange-900 dark:bg-orange-950">
                                    <div class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Your referring domains') }}</div>
                                    <div class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($ins['my_referring_domains']) }}</div>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 dark:border-slate-800 dark:bg-slate-800/40">
                                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Competitor median') }}</div>
                                    <div class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ $ins['median'] !== null ? number_format($ins['median']) : '—' }}</div>
                                </div>
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-5 dark:border-slate-800 dark:bg-slate-800/40">
                                    <div class="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Current gap') }}</div>
                                    <div class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ $ins['gap'] !== null ? $ins['gap'].'×' : '—' }}</div>
                                </div>
                            </div>
                        @endunless
                        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 shadow-sm dark:border-slate-800">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                    <tr>
                                        <th class="px-4 py-2.5 text-start font-bold">{{ __('Competitor') }}</th>
                                        @if ($showCompMetrics)
                                            <th class="px-4 py-2.5 text-end font-bold">{{ __('Referring domains') }}</th>
                                            <th class="px-4 py-2.5 text-end font-bold">{{ __('Backlinks') }}</th>
                                            <th class="px-4 py-2.5 text-end font-bold">{{ __('DA') }}</th>
                                            <th class="px-4 py-2.5 text-end font-bold">{{ __('PA') }}</th>
                                        @endif
                                        <th class="w-10 px-2 py-2.5"></th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                    @foreach ($ins['competitors'] as $c)
                                        <tr class="group bg-white dark:bg-slate-900" wire:key="comp-{{ $c['domain'] }}">
                                            <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-200">
                                                <div class="flex items-center gap-2">
                                                    <img src="https://www.google.com/s2/favicons?domain={{ urlencode($c['domain']) }}&sz=32"
                                                         alt="" width="16" height="16" loading="lazy"
                                                         class="h-4 w-4 flex-none rounded-sm bg-slate-100 dark:bg-slate-800"
                                                         onerror="this.style.visibility='hidden'">
                                                    {{ $c['domain'] }}
                                                </div>
                                            </td>
                                            @if ($showCompMetrics)
                                                <td class="px-4 py-3 text-end font-bold text-slate-800 dark:text-slate-200">{{ $c['referring_domains'] !== null ? number_format($c['referring_domains']) : '—' }}</td>
                                                <td class="px-4 py-3 text-end text-slate-500 dark:text-slate-400">{{ isset($c['backlinks']) && $c['backlinks'] !== null ? number_format($c['backlinks']) : '—' }}</td>
                                                <td class="px-4 py-3 text-end text-slate-500 dark:text-slate-400">{{ $c['da'] ?? '—' }}</td>
                                                <td class="px-4 py-3 text-end text-slate-500 dark:text-slate-400">{{ $c['pa'] ?? '—' }}</td>
                                            @endif
                                            <td class="px-2 py-3 text-end">
                                                <button type="button" wire:click="removeCompetitor('{{ $c['domain'] }}')" wire:key="comp-rm-{{ $c['domain'] }}"
                                                        class="text-slate-300 opacity-0 transition hover:text-error group-hover:opacity-100" aria-label="{{ __('Remove') }}">
                                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($showCompMetrics)
                            <p class="mt-2 text-center text-xs text-slate-400">{{ __('DA/PA from Moz; referring domains and backlinks from DataForSEO, where available.') }}</p>
                        @endif
                    @elseif ($ins !== null && $hasOverrides)
                        {{-- Data exists, but the user's own edits emptied the table — distinct from "still loading". --}}
                        <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-8 text-center dark:border-slate-700 dark:bg-slate-800/40">
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __("You've removed every competitor. Reset to bring back the auto-discovered list, or add your own below.") }}</p>
                            <button type="button" wire:click="resetCompetitors" class="mt-3 inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-white dark:border-slate-700 dark:text-slate-300">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/></svg>
                                {{ __('Reset to auto-discovered') }}
                            </button>
                        </div>
                    @elseif ($generating)
                        <div wire:poll.5s="refreshCompetitors" class="mt-6 flex flex-col items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-10 text-center dark:border-slate-800 dark:bg-slate-800/40">
                            <svg class="h-6 w-6 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('Analyzing your competitive landscape…') }}</p>
                        </div>
                    @else
                        <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-8 text-center dark:border-slate-800 dark:bg-slate-800/40">
                            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('No competitor authority data yet') }}</p>
                            <p class="mx-auto mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">{{ __("This is normal for newer sites with a small backlink footprint — there's no established authority to compare yet. Add competitors you know below, or just continue; your content plan is already being built.") }}</p>
                        </div>
                    @endif

                    {{-- Manually add a competitor — always available regardless of report state --}}
                    <div class="mt-4 rounded-2xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Add a competitor') }}</h3>
                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __("Know a competitor we missed? Add their domain and we'll look up their authority.") }}</p>
                        <div class="mt-3 flex gap-2">
                            <input wire:model="newCompetitorDomain" wire:keydown.enter.prevent="addCompetitor" type="text" placeholder="{{ __('competitor-domain.com') }}"
                                class="min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-3.5 py-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                            <button type="button" wire:click="addCompetitor" aria-label="{{ __('Add') }}" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-orange-600 text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                            </button>
                        </div>
                        @error('newCompetitorDomain') <p class="mt-1.5 text-xs text-error">{{ $message }}</p> @enderror
                    </div>

                    <div class="mt-7 flex items-center justify-between">
                        <button wire:click="goToStep(4)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            {{ __('Back') }}
                        </button>
                        <button wire:click="toKeywordResearch" wire:loading.attr="disabled" wire:target="toKeywordResearch" @disabled($generating) class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-70"><svg wire:loading wire:target="toKeywordResearch" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            {{ __('Continue') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                        </button>
                    </div>

                {{-- ── Step 5: keyword research ─────────────────────── --}}
                @elseif ($wizardStep === 6)
                    @php $kw = $wizard['keywords'] ?? null; @endphp
                    <div class="text-center">
                        <span class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3M11 8v6M8 11h6"/></svg>
                        </span>
                        <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 6 of 7') }}</p>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('The keyword research behind your plan') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('What your audience searches for, grouped and prioritized — this is what your articles are built on.') }}</p>
                    </div>

                    @if ($kw === null)
                        <div wire:poll.5s="refreshKeywordInsights" class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-8 text-center dark:border-slate-800 dark:bg-slate-800/40">
                            <svg class="mx-auto h-6 w-6 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            <p class="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200">{{ __('Researching live search data for your market…') }}</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ __('We analyze your site and your competitors in depth — this can take a few minutes. Your full results appear here all at once.') }}</p>

                            @php $kwStatus = $wizard['keywordStatus'] ?? []; @endphp
                            @if (! empty($kwStatus))
                                <ul class="mx-auto mt-5 max-w-sm space-y-2 text-start">
                                    @foreach ($kwStatus as $src)
                                        <li class="flex items-center gap-2.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2.5 text-sm dark:border-slate-700 dark:bg-slate-900" wire:key="kwsrc-{{ $loop->index }}">
                                            @if ($src['done'])
                                                <svg class="h-4 w-4 flex-none text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                            @else
                                                <svg class="h-4 w-4 flex-none animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                            @endif
                                            <span class="flex-1 truncate {{ $src['done'] ? 'text-slate-500 dark:text-slate-400' : 'font-medium text-slate-800 dark:text-slate-100' }}">{{ $src['label'] }}</span>
                                            <span class="flex-none text-xs font-semibold {{ $src['done'] ? 'text-success' : 'text-orange-600 dark:text-orange-400' }}">{{ $src['done'] ? __('Done') : __('Analyzing…') }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @else
                        {{-- Own keywords are in; competitors/gap still upgrading → keep polling so
                             the digest refreshes in place (the loader's poll is gone once $kw is set). --}}
                        @if (! empty($kw['competitors_pending']))
                            <div wire:poll.6s="refreshKeywordInsights" class="hidden"></div>
                        @endif
                        {{-- "This is a live sample, not the whole picture" callout --}}
                        <div class="mt-6 flex items-start gap-3 rounded-2xl border border-orange-200 bg-gradient-to-r from-orange-50 to-white p-4 dark:border-orange-900 dark:from-orange-950 dark:to-slate-900">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __("A live glimpse of your research engine — not the whole picture") }}</p>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ __("What you see here is a real-time sample of how we research your market. The deeper analysis never stops: every single article gets its own fresh round of research — search trends, competing pages, and related questions — the moment it's written.") }}</p>
                            </div>
                        </div>

                        {{-- Headline stats (volume card hidden until real volume data exists) --}}
                        @php $kwHasVolume = ($kw['stats']['volume'] ?? 0) > 0; @endphp
                        <div class="mt-4 grid grid-cols-2 gap-3 {{ $kwHasVolume ? 'sm:grid-cols-4' : 'sm:grid-cols-3' }}">
                            <div class="rounded-2xl border border-orange-200 bg-orange-50 p-4 text-center dark:border-orange-900 dark:bg-orange-950">
                                <div class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($kw['stats']['keywords']) }}</div>
                                <div class="mt-0.5 text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Keywords analyzed') }}</div>
                            </div>
                            @if ($kwHasVolume)
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-center dark:border-slate-800 dark:bg-slate-800/40">
                                    <div class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($kw['stats']['volume']) }}</div>
                                    <div class="mt-0.5 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Monthly searches') }}</div>
                                </div>
                            @endif
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-center dark:border-slate-800 dark:bg-slate-800/40">
                                <div class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($kw['stats']['clusters']) }}</div>
                                <div class="mt-0.5 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Topic groups') }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-center dark:border-slate-800 dark:bg-slate-800/40">
                                <div class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($kw['stats']['questions']) }}</div>
                                <div class="mt-0.5 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Questions asked') }}</div>
                            </div>
                        </div>

                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            {{-- Topic clusters --}}
                            @if (! empty($kw['clusters']))
                                <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                    <h3 class="flex items-center gap-2 text-sm font-bold text-slate-900 dark:text-slate-100">
                                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-orange-100 text-orange-600 dark:bg-orange-950 dark:text-orange-400">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                                        </span>
                                        {{ __('Your content pillars') }}
                                    </h3>
                                    <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Related searches grouped into the themes your articles will own.') }}</p>
                                    <div class="mt-3 space-y-2.5">
                                        @foreach ($kw['clusters'] as $cluster)
                                            <div class="rounded-xl border border-slate-100 bg-slate-50/60 px-3.5 py-2.5 dark:border-slate-800 dark:bg-slate-800/40" wire:key="kwc-{{ $loop->index }}">
                                                <div class="flex items-center justify-between gap-2">
                                                    <span class="truncate text-sm font-bold text-slate-800 dark:text-slate-100">{{ $cluster['label'] }}</span>
                                                    <span class="shrink-0 rounded-full bg-orange-100 px-2 py-0.5 text-xs font-bold text-orange-700 dark:bg-orange-950 dark:text-orange-300">{{ $cluster['count'] }} {{ __('keywords') }}@if($cluster['volume'] > 0) · {{ number_format($cluster['volume']) }}/mo @endif</span>
                                                </div>
                                                @if (! empty($cluster['top']))
                                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                                        @foreach ($cluster['top'] as $topKw)
                                                            <span class="rounded-full bg-white px-2 py-0.5 text-xs text-slate-600 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700">{{ $topKw }}</span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div class="space-y-4">
                                {{-- Intent mix --}}
                                @if (! empty($kw['intents']))
                                    @php
                                        $intentTotal = max(1, array_sum($kw['intents']));
                                        $intentMeta = [
                                            'informational' => ['label' => __('Learning'), 'hint' => __('how-to & answers'), 'bar' => 'bg-orange-500'],
                                            'transactional' => ['label' => __('Ready to act'), 'hint' => __('tools, buying, doing'), 'bar' => 'bg-success'],
                                            'commercial' => ['label' => __('Comparing options'), 'hint' => __('best-of & reviews'), 'bar' => 'bg-amber-500'],
                                            'navigational' => ['label' => __('Finding a site'), 'hint' => __('brand lookups'), 'bar' => 'bg-slate-400'],
                                            'other' => ['label' => __('Broad interest'), 'hint' => __('everything else'), 'bar' => 'bg-slate-300'],
                                        ];
                                    @endphp
                                    <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Why people search these') }}</h3>
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Your calendar balances teaching content with content that converts.') }}</p>
                                        <div class="mt-3 space-y-2">
                                            @foreach ($kw['intents'] as $intent => $count)
                                                @php $meta = $intentMeta[$intent] ?? $intentMeta['other']; $pct = (int) round($count / $intentTotal * 100); @endphp
                                                <div wire:key="kwi-{{ $intent }}">
                                                    <div class="flex items-center justify-between text-xs">
                                                        <span class="font-semibold text-slate-700 dark:text-slate-300">{{ $meta['label'] }} <span class="font-normal text-slate-400">· {{ $meta['hint'] }}</span></span>
                                                        <span class="font-bold text-slate-600 dark:text-slate-400">{{ $pct }}%</span>
                                                    </div>
                                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                                                        <div class="h-1.5 rounded-full {{ $meta['bar'] }}" style="width: {{ max(3, $pct) }}%"></div>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- What people are searching for: Google's "People also search for" (related searches) --}}
                                @php $pa = $kw['people_also'] ?? []; @endphp
                                @if (! empty($pa['search']))
                                    <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                        <h3 class="flex items-center gap-2 text-sm font-bold text-slate-900 dark:text-slate-100">
                                            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-orange-100 text-orange-600 dark:bg-orange-950 dark:text-orange-400">
                                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3"/></svg>
                                            </span>
                                            {{ __('What people are searching for') }}
                                        </h3>
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Related searches Google shows around your topic — what your audience explores next.') }}</p>
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @foreach ($pa['search'] as $rs)
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1.5 text-sm font-medium text-slate-700 dark:bg-slate-800 dark:text-slate-200" wire:key="pas-{{ $loop->index }}">
                                                    <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3"/></svg>
                                                    {{ $rs }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- Questions --}}
                                @if (! empty($kw['questions']))
                                    <div class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
                                        <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Questions your audience is asking') }}</h3>
                                        <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{{ __('Your articles will answer these directly — the fastest way to earn trust and rankings.') }}</p>
                                        <ul class="mt-3 space-y-1.5">
                                            @foreach ($kw['questions'] as $q)
                                                <li class="flex items-center justify-between gap-2 text-sm" wire:key="kwq-{{ $loop->index }}">
                                                    <span class="truncate text-slate-700 dark:text-slate-300">{{ $q['keyword'] }}</span>
                                                    @if ($q['volume'])
                                                        <span class="shrink-0 text-xs font-semibold text-orange-600 dark:text-orange-400">{{ number_format($q['volume']) }}/mo</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Keyword gap + Top searches by demand, side by side (half each, equal height) --}}
                        <div class="mt-4 grid gap-4 lg:grid-cols-2">
                            {{-- Keyword gap: what competitors rank for that the client doesn't --}}
                            @if (! empty($kw['gap']))
                                <div class="overflow-hidden rounded-2xl border border-orange-200 shadow-sm dark:border-orange-900">
                                    <div class="flex items-start gap-3 bg-gradient-to-r from-orange-50 to-white px-4 py-3 dark:from-orange-950 dark:to-slate-900">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
                                        </span>
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">
                                                @if (($kw['gap_total'] ?? 0) > count($kw['gap']))
                                                    {{ __(':count keywords you\'re not targeting yet', ['count' => number_format($kw['gap_total'])]) }}
                                                @else
                                                    {{ __('Keyword gap — competitors rank, you don\'t (yet)') }}
                                                @endif
                                            </h3>
                                            <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-400">{{ __('High-demand searches your competitors show up for and you\'re missing — prime targets for your new articles.') }}</p>
                                        </div>
                                    </div>
                                    <table class="w-full text-sm">
                                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                            <tr>
                                                <th class="px-4 py-2.5 text-start font-bold">{{ __('Keyword') }}</th>
                                                <th class="px-4 py-2.5 text-end font-bold">{{ __('Monthly searches') }}</th>
                                                <th class="px-4 py-2.5 text-end font-bold">{{ __('Competition') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                            @foreach ($kw['gap'] as $g)
                                                <tr class="bg-white dark:bg-slate-900" wire:key="kwg-{{ $loop->index }}">
                                                    <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-200">{{ $g['keyword'] }}</td>
                                                    <td class="px-4 py-3 text-end font-bold text-slate-800 dark:text-slate-200">{{ $g['volume'] !== null ? number_format($g['volume']) : '—' }}</td>
                                                    <td class="px-4 py-3 text-end">
                                                        @php $gc = ['low' => 'bg-success/10 text-success', 'medium' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300', 'high' => 'bg-error/10 text-error', 'unknown' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$g['competition']] ?? 'bg-slate-100 text-slate-500'; @endphp
                                                        <span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $gc }}">{{ ['low' => __('Low'), 'medium' => __('Medium'), 'high' => __('High'), 'unknown' => '—'][$g['competition']] ?? '—' }}</span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                    @if (($kw['gap_total'] ?? 0) > count($kw['gap']))
                                        <div class="border-t border-slate-100 bg-slate-50 px-4 py-3 text-center dark:border-slate-800 dark:bg-slate-800/40">
                                            <p class="text-xs font-medium text-slate-600 dark:text-slate-300">{{ __('Showing :shown of :total — see the full list once you finish setting up.', ['shown' => count($kw['gap']), 'total' => number_format($kw['gap_total'])]) }}</p>
                                        </div>
                                    @endif
                                </div>
                            @elseif (! empty($kw['competitors_pending']))
                                {{-- Own keywords are shown; the competitor gap analysis is still running. --}}
                                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-4 dark:border-slate-800 dark:bg-slate-800/40">
                                    <svg class="h-5 w-5 flex-none animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-slate-900 dark:text-slate-100">
                                            @if (($kw['competitors_total'] ?? 0) > 0)
                                                {{ __('Analyzing :done of :total competitors for your keyword gap…', ['done' => (int) ($kw['competitors_done'] ?? 0), 'total' => (int) $kw['competitors_total']]) }}
                                            @else
                                                {{ __('Analyzing your competitors for your keyword gap…') }}
                                            @endif
                                        </p>
                                        <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-400">{{ __('The keywords your competitors rank for and you don\'t will appear here in a moment.') }}</p>
                                    </div>
                                </div>
                            @endif

                            {{-- Top searches by demand --}}
                            @if (! empty($kw['top_searches']))
                                <div class="overflow-hidden rounded-2xl border border-slate-200 shadow-sm dark:border-slate-800">
                                    <div class="flex items-start gap-3 bg-gradient-to-r from-orange-50 to-white px-4 py-3 dark:from-orange-950 dark:to-slate-900">
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3"/></svg>
                                        </span>
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Top searches by demand') }}</h3>
                                            <p class="mt-0.5 text-xs text-slate-600 dark:text-slate-400">{{ __('The highest-demand search terms in your market — the exact keywords your articles will target.') }}</p>
                                        </div>
                                    </div>
                                    <table class="w-full text-sm">
                                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                            <tr>
                                                <th class="px-4 py-2.5 text-start font-bold">{{ __('Search term') }}</th>
                                                <th class="px-4 py-2.5 text-end font-bold">{{ __('Monthly searches') }}</th>
                                                <th class="px-4 py-2.5 text-end font-bold">{{ __('Competition') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                            @foreach ($kw['top_searches'] as $s)
                                                <tr class="bg-white dark:bg-slate-900" wire:key="kwts-{{ $loop->index }}">
                                                    <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-200">{{ $s['keyword'] }}</td>
                                                    <td class="px-4 py-3 text-end font-bold text-slate-800 dark:text-slate-200">{{ $s['volume'] !== null ? number_format($s['volume']) : '—' }}</td>
                                                    <td class="px-4 py-3 text-end">
                                                        @php $sc = ['low' => 'bg-success/10 text-success', 'medium' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300', 'high' => 'bg-error/10 text-error', 'unknown' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$s['competition']] ?? 'bg-slate-100 text-slate-500'; @endphp
                                                        <span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $sc }}">{{ ['low' => __('Low'), 'medium' => __('Medium'), 'high' => __('High'), 'unknown' => '—'][$s['competition']] ?? '—' }}</span>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>

                    @endif

                    <div class="mt-7 flex items-center justify-between">
                        <button wire:click="goToStep(5)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            {{ __('Back') }}
                        </button>
                        <button wire:click="toFirstArticles" wire:loading.attr="disabled" wire:target="toFirstArticles" @disabled($kw === null) class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-70"><svg wire:loading wire:target="toFirstArticles" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            {{ __('Continue') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                        </button>
                    </div>

                {{-- ── Step 7: first articles ───────────────────────── --}}
                @elseif ($wizardStep === 7)
                    @php $dts = $wizard['draftTopics'] ?? collect(); @endphp
                    <div class="text-center">
                        <span class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a5.25 5.25 0 016.775-5.025.75.75 0 01.313 1.248l-3.32 3.319c.063.475.276.934.641 1.299.365.365.824.578 1.3.64l3.318-3.319a.75.75 0 011.248.313 5.25 5.25 0 01-5.472 6.756c-1.018-.086-1.87.1-2.309.634L7.344 21.3A3.298 3.298 0 112.7 16.657l8.684-7.151c.533-.44.72-1.291.634-2.309a5.342 5.342 0 01-.068-.447z"/></svg>
                        </span>
                        <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 7 of 7') }}</p>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Your first articles are ready') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('Built from what your audience is really searching for. Remove any that don\'t fit, then launch.') }}</p>
                    </div>

                    @if ($dts->isEmpty())
                        <div wire:poll.4s class="mt-6 flex flex-col items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-10 text-center dark:border-slate-800 dark:bg-slate-800/40">
                            <svg class="h-6 w-6 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('Researching the best topics for your site…') }}</p>
                        </div>
                    @else
                        <div class="mt-6 space-y-2.5">
                            @foreach ($dts as $t)
                                <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3.5 shadow-sm transition hover:border-orange-200 dark:border-slate-700 dark:bg-slate-800" wire:key="dt-{{ $t->id }}">
                                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $t->title }}</div>
                                        <div class="text-xs text-slate-500 dark:text-slate-400">{{ $t->target_keyword }}@if($t->keyword_volume) · <span class="font-semibold text-orange-600 dark:text-orange-400">{{ number_format($t->keyword_volume) }} {{ __('searches/mo') }}</span>@endif</div>
                                    </div>
                                    <button wire:click="dropTopic('{{ $t->id }}')" class="shrink-0 text-slate-300 hover:text-error" aria-label="{{ __('Remove') }}">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                        {{-- "This list keeps growing" callout (mirrors the step-5 research-engine callout) --}}
                        <div class="mt-4 flex items-start gap-3 rounded-2xl border border-orange-200 bg-gradient-to-r from-orange-50 to-white p-4 dark:border-orange-900 dark:from-orange-950 dark:to-slate-900">
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('This is just the beginning — your calendar keeps filling itself') }}</p>
                                <p class="mt-0.5 text-sm text-slate-600 dark:text-slate-400">{{ __('New topics keep generating automatically, so you always have articles lined up. Publishing starts at 1 article per day — you can change the pace anytime.') }}</p>
                            </div>
                        </div>
                    @endif

                    <div class="mt-7 flex items-center justify-between">
                        <button wire:click="goToStep(6)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            {{ __('Back') }}
                        </button>
                        @if ($publicOnboarding)
                            {{-- Never gate account creation on async research — topics keep
                                 generating after signup, in the dashboard. --}}
                            <button wire:click="toAccount" wire:loading.attr="disabled" wire:target="toAccount"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40">
                                {{ __('Continue') }}
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                            </button>
                        @else
                            <button wire:click="launch" @disabled($dts->isEmpty()) wire:loading.attr="disabled" wire:target="launch"
                                class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40">
                                <svg wire:loading wire:target="launch" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                                {{ __('Looks good — launch') }}
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a5.25 5.25 0 016.775-5.025.75.75 0 01.313 1.248l-3.32 3.319c.063.475.276.934.641 1.299.365.365.824.578 1.3.64l3.318-3.319a.75.75 0 011.248.313 5.25 5.25 0 01-5.472 6.756c-1.018-.086-1.87.1-2.309.634L7.344 21.3A3.298 3.298 0 112.7 16.657l8.684-7.151c.533-.44.72-1.291.634-2.309a5.342 5.342 0 01-.068-.447z"/></svg>
                            </button>
                        @endif
                    </div>

                {{-- ── Step 8: create account (public onboarding only) ── --}}
                @elseif ($wizardStep === 8)
                    @include('livewire.content.partials.wizard-account')
                @endif
                </div>
            </div>
        </div>
