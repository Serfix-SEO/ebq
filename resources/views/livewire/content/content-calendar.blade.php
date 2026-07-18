<div class="space-y-6">
    @if (session('content-status'))
        <div class="rounded-lg border border-success/20 bg-success/10 px-4 py-3 text-sm font-medium text-success">
            {{ session('content-status') }}
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
    @elseif ($inWizard)
        {{-- ══ Setup wizard (5 steps) ══════════════════════════════════ --}}
        @php
            $steps = [1 => __('Business'), 2 => __('Offerings'), 3 => __('How it works'), 4 => __('Competitors'), 5 => __('Keyword research'), 6 => __('First articles')];
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
        <div class="mx-auto w-full max-w-6xl">
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
                                <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 1 of 6') }}</p>
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
                            <button wire:click="toOfferings" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 transition-transform hover:brightness-110">
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
                            <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 2 of 6') }}</p>
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
                        <button wire:click="toHowItWorks" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
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
                        <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 3 of 6') }}</p>
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
                    <div class="mt-8 flex items-center justify-between">
                        <button wire:click="goToStep(2)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            {{ __('Back') }}
                        </button>
                        <button wire:click="toCompetitors" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                            {{ __('Continue') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                        </button>
                    </div>

                {{-- ── Step 4: competitors ──────────────────────────── --}}
                @elseif ($wizardStep === 4)
                    @php
                        $ins = $wizard['insights'] ?? null;
                        $generating = $wizard['generating'] ?? false;
                        $needsReportGen = $wizard['needsReportGen'] ?? false;
                        $hasOverrides = $wizard['hasOverrides'] ?? false;
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
                        <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 4 of 6') }}</p>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Your competitors and their authority') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('How your backlink profile compares to others in your space.') }}</p>
                    </div>

                    @if ($ins && ! empty($ins['competitors']))
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
                        <div class="mt-5 overflow-hidden rounded-2xl border border-slate-200 shadow-sm dark:border-slate-800">
                            <table class="w-full text-sm">
                                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                    <tr>
                                        <th class="px-4 py-2.5 text-start font-bold">{{ __('Competitor') }}</th>
                                        <th class="px-4 py-2.5 text-end font-bold">{{ __('Referring domains') }}</th>
                                        <th class="px-4 py-2.5 text-end font-bold">{{ __('Backlinks') }}</th>
                                        <th class="px-4 py-2.5 text-end font-bold">{{ __('DA') }}</th>
                                        <th class="px-4 py-2.5 text-end font-bold">{{ __('PA') }}</th>
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
                                            <td class="px-4 py-3 text-end font-bold text-slate-800 dark:text-slate-200">{{ $c['referring_domains'] !== null ? number_format($c['referring_domains']) : '—' }}</td>
                                            <td class="px-4 py-3 text-end text-slate-500 dark:text-slate-400">{{ isset($c['backlinks']) && $c['backlinks'] !== null ? number_format($c['backlinks']) : '—' }}</td>
                                            <td class="px-4 py-3 text-end text-slate-500 dark:text-slate-400">{{ $c['da'] ?? '—' }}</td>
                                            <td class="px-4 py-3 text-end text-slate-500 dark:text-slate-400">{{ $c['pa'] ?? '—' }}</td>
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
                        <p class="mt-2 text-center text-xs text-slate-400">{{ __('DA/PA from Moz; referring domains and backlinks from DataForSEO, where available.') }}</p>
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
                            <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('Your competitive landscape appears here shortly. Your content plan is already being built either way.') }}</p>
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
                        <button wire:click="goToStep(3)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            {{ __('Back') }}
                        </button>
                        <button wire:click="toKeywordResearch" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                            {{ __('Continue') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                        </button>
                    </div>

                {{-- ── Step 5: keyword research ─────────────────────── --}}
                @elseif ($wizardStep === 5)
                    @php $kw = $wizard['keywords'] ?? null; @endphp
                    <div class="text-center">
                        <span class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="M21 21l-4.3-4.3M11 8v6M8 11h6"/></svg>
                        </span>
                        <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 5 of 6') }}</p>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('The keyword research behind your plan') }}</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ __('What your audience searches for, grouped and prioritized — this is what your articles are built on.') }}</p>
                    </div>

                    @if ($kw === null)
                        <div wire:poll.5s="refreshKeywordInsights" class="mt-6 flex flex-col items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-10 text-center dark:border-slate-800 dark:bg-slate-800/40">
                            <svg class="h-6 w-6 animate-spin text-orange-500" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ __('Researching live search data for your market…') }}</p>
                            <p class="text-xs text-slate-400">{{ __('This runs in the background — feel free to continue and check back.') }}</p>
                        </div>
                    @else
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

                        {{-- Headline stats --}}
                        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                            <div class="rounded-2xl border border-orange-200 bg-orange-50 p-4 text-center dark:border-orange-900 dark:bg-orange-950">
                                <div class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ number_format($kw['stats']['keywords']) }}</div>
                                <div class="mt-0.5 text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Keywords analyzed') }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-center dark:border-slate-800 dark:bg-slate-800/40">
                                <div class="text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ $kw['stats']['volume'] > 0 ? number_format($kw['stats']['volume']) : '—' }}</div>
                                <div class="mt-0.5 text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ __('Monthly searches') }}</div>
                            </div>
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

                        {{-- Opportunities --}}
                        @if (! empty($kw['opportunities']))
                            <div class="mt-4 overflow-hidden rounded-2xl border border-slate-200 shadow-sm dark:border-slate-800">
                                <table class="w-full text-sm">
                                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/50 dark:text-slate-400">
                                        <tr>
                                            <th class="px-4 py-2.5 text-start font-bold">{{ __('Biggest opportunities') }}</th>
                                            <th class="px-4 py-2.5 text-end font-bold">{{ __('Monthly searches') }}</th>
                                            <th class="px-4 py-2.5 text-end font-bold">{{ __('Competition') }}</th>
                                            <th class="px-4 py-2.5 text-end font-bold">{{ __('Status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                        @foreach ($kw['opportunities'] as $opp)
                                            <tr class="bg-white dark:bg-slate-900" wire:key="kwo-{{ $loop->index }}">
                                                <td class="px-4 py-3 font-medium text-slate-800 dark:text-slate-200">{{ $opp['keyword'] }}</td>
                                                <td class="px-4 py-3 text-end font-bold text-slate-800 dark:text-slate-200">{{ $opp['volume'] !== null ? number_format($opp['volume']) : '—' }}</td>
                                                <td class="px-4 py-3 text-end">
                                                    @php $compClass = ['low' => 'bg-success/10 text-success', 'medium' => 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300', 'high' => 'bg-error/10 text-error', 'unknown' => 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400'][$opp['competition']] ?? 'bg-slate-100 text-slate-500'; @endphp
                                                    <span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $compClass }}">{{ ['low' => __('Low'), 'medium' => __('Medium'), 'high' => __('High'), 'unknown' => '—'][$opp['competition']] ?? '—' }}</span>
                                                </td>
                                                <td class="px-4 py-3 text-end">
                                                    @if ($opp['planned'])
                                                        <span class="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-xs font-bold text-success">
                                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                                            {{ __('In your calendar') }}
                                                        </span>
                                                    @else
                                                        <span class="text-xs text-slate-400">{{ __('Tracked') }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif

                        @if ($kw['partial'])
                            <p class="mt-2 text-center text-xs text-slate-400">{{ __('Research keeps deepening in the background — this view refreshes as more data lands.') }}</p>
                        @endif
                    @endif

                    <div class="mt-7 flex items-center justify-between">
                        <button wire:click="goToStep(4)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            {{ __('Back') }}
                        </button>
                        <button wire:click="toFirstArticles" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                            {{ __('Continue') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                        </button>
                    </div>

                {{-- ── Step 6: first articles ───────────────────────── --}}
                @else
                    @php $dts = $wizard['draftTopics'] ?? collect(); @endphp
                    <div class="text-center">
                        <span class="mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a5.25 5.25 0 016.775-5.025.75.75 0 01.313 1.248l-3.32 3.319c.063.475.276.934.641 1.299.365.365.824.578 1.3.64l3.318-3.319a.75.75 0 011.248.313 5.25 5.25 0 01-5.472 6.756c-1.018-.086-1.87.1-2.309.634L7.344 21.3A3.298 3.298 0 112.7 16.657l8.684-7.151c.533-.44.72-1.291.634-2.309a5.342 5.342 0 01-.068-.447z"/></svg>
                        </span>
                        <p class="text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-400">{{ __('Step 6 of 6') }}</p>
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
                        <p class="mt-4 text-center text-xs text-slate-400">{{ __('More topics keep generating in the background. Publishing: 1 article/day — change anytime.') }}</p>
                    @endif

                    <div class="mt-7 flex items-center justify-between">
                        <button wire:click="goToStep(5)" class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-4 py-3 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                            {{ __('Back') }}
                        </button>
                        <button wire:click="launch" @disabled($dts->isEmpty())
                            class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:cursor-not-allowed disabled:opacity-40">
                            {{ __('Looks good — launch') }}
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a5.25 5.25 0 016.775-5.025.75.75 0 01.313 1.248l-3.32 3.319c.063.475.276.934.641 1.299.365.365.824.578 1.3.64l3.318-3.319a.75.75 0 011.248.313 5.25 5.25 0 01-5.472 6.756c-1.018-.086-1.87.1-2.309.634L7.344 21.3A3.298 3.298 0 112.7 16.657l8.684-7.151c.533-.44.72-1.291.634-2.309a5.342 5.342 0 01-.068-.447z"/></svg>
                        </button>
                    </div>
                @endif
                </div>
            </div>
        </div>
    @else
        {{-- ── Calendar ─────────────────────────────────────────────── --}}
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
                <button wire:click="$toggle('showAddTopic')" class="rounded-lg bg-orange-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-orange-700">+ {{ __('Add topic') }}</button>
                <button wire:click="pauseOrResume"
                    class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                    {{ $plan->isActive() ? __('Pause') : __('Resume') }}
                </button>
            </div>
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

        @unless ($plan->isActive())
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                {{ __('Content planning is paused. New articles are not being written.') }}
            </div>
        @endunless

        @if ($showAddTopic)
            <div class="rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400" for="ca-new-title">{{ __('Article title') }}</label>
                        <input id="ca-new-title" wire:model="newTitle" type="text"
                            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                        @error('newTitle') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400" for="ca-new-kw">{{ __('Target keyword') }}</label>
                        <input id="ca-new-kw" wire:model="newKeyword" type="text"
                            class="mt-1 w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                        @error('newKeyword') <p class="mt-1 text-xs text-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-3 flex justify-end gap-2">
                    <button wire:click="$toggle('showAddTopic')" class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">{{ __('Cancel') }}</button>
                    <button wire:click="addTopic" class="rounded-lg bg-orange-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-orange-700">{{ __('Add') }}</button>
                </div>
            </div>
        @endif

        @if ($view === 'grid')
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="grid grid-cols-7 border-b border-slate-200 text-center text-xs font-semibold uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400" style="min-width:56rem">
                    @foreach ([__('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat'), __('Sun')] as $dow)
                        <div class="px-2 py-2">{{ $dow }}</div>
                    @endforeach
                </div>
                <div class="grid grid-cols-7" style="min-width:56rem">
                    @foreach ($days as $day)
                        @php $dayTopics = $topicsByDate->get($day->toDateString(), collect()); @endphp
                        <div class="border-b border-e border-slate-100 p-1.5 align-top dark:border-slate-800 {{ $day->format('Y-m') !== $month ? 'bg-slate-50 dark:bg-slate-950' : '' }}" style="min-height:6.5rem">
                            <div class="mb-1 text-end text-xs {{ $day->isToday() ? 'font-bold text-orange-600' : 'text-slate-400' }}">{{ $day->day }}</div>
                            @foreach ($dayTopics as $topic)
                                @php $p = \App\Livewire\Content\ContentCalendar::statusPresentation($topic->status); @endphp
                                <a href="{{ $topic->currentArticle ? route('content.review', $topic->id) : '#' }}"
                                   @if(!$topic->currentArticle) onclick="return false" @endif
                                   class="mb-1 block rounded-lg border border-slate-200 bg-white p-1.5 text-start hover:border-orange-300 dark:border-slate-700 dark:bg-slate-800">
                                    <span class="line-clamp-2 text-xs font-medium text-slate-800 dark:text-slate-100">{{ $topic->title }}</span>
                                    <span class="mt-1 inline-block rounded-full bg-{{ $p['color'] }}-100 px-1.5 py-px text-[10px] font-semibold text-{{ $p['color'] }}-700">{{ $p['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
                <div class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($topics as $topic)
                        @php $p = \App\Livewire\Content\ContentCalendar::statusPresentation($topic->status); @endphp
                        <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                            <div class="w-20 shrink-0 text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $topic->scheduled_for?->translatedFormat('M j') }}</div>
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{{ $topic->title }}</div>
                                <div class="text-xs text-slate-500 dark:text-slate-400">{{ $topic->target_keyword }}@if($topic->keyword_volume) · {{ number_format($topic->keyword_volume) }} {{ __('searches/mo') }}@endif</div>
                            </div>
                            <span class="rounded-full bg-{{ $p['color'] }}-100 px-2 py-0.5 text-xs font-semibold text-{{ $p['color'] }}-700">{{ $p['label'] }}</span>
                            @if ($topic->currentArticle)
                                <a href="{{ route('content.review', $topic->id) }}" class="text-sm font-medium text-orange-600 hover:text-orange-700">{{ __('Review') }}</a>
                            @endif
                            @if ($topic->status === \App\Models\ContentTopic::STATUS_SUGGESTED)
                                <button wire:click="approve('{{ $topic->id }}')" class="text-sm font-medium text-success hover:brightness-90">{{ __('Approve') }}</button>
                            @endif
                            @if ($topic->status === \App\Models\ContentTopic::STATUS_FAILED)
                                <button wire:click="retry('{{ $topic->id }}')" class="text-sm font-medium text-orange-600 hover:text-orange-700">{{ __('Try again') }}</button>
                            @endif
                            @if (in_array($topic->status, ['suggested', 'approved', 'ready'], true))
                                <input type="date" min="{{ now()->addDay()->toDateString() }}" value="{{ $topic->scheduled_for?->toDateString() }}"
                                    wire:change="reschedule('{{ $topic->id }}', $event.target.value)"
                                    class="rounded-lg border border-slate-300 px-2 py-1 text-xs text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300" />
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
