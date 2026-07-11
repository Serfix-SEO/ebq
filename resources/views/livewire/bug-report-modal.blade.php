<div
    x-data="{ show: false, capturing: false,
        openModal(url) {
            this.show = true;
            // Instant client-side prefill — the server round-trip below takes
            // ~1s and the link field would sit visibly empty until the morph.
            $nextTick(() => {
                const input = this.$root.querySelector('#bug-url');
                if (input) { input.value = url; input.dispatchEvent(new Event('input', { bubbles: true })); }
            });
            $wire.open(url);
        },
        async startSnip() {
            this.show = false;
            this.capturing = true;
            try {
                const shot = await window.ebqSnip();
                if (shot) {
                    $wire.set('viewport', shot.viewport, false);
                    await $wire.set('screenshotDataUrl', shot.dataUrl);
                }
            } catch (e) {
                console.error('snip failed', e);
            } finally {
                // The modal must ALWAYS come back — capture errors, Esc
                // cancels and upload failures included.
                this.capturing = false;
                this.show = true;
            }
        }
    }"
    x-on:open-bug-report.window="openModal($event.detail?.url ?? window.location.href)"
    x-on:keydown.escape.window="show = false"
>
    <div x-show="show" x-cloak class="fixed inset-0 z-50 flex items-center justify-center px-4">
        {{-- Backdrop --}}
        <div x-show="show" x-transition.opacity class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" x-on:click="show = false"></div>

        {{-- Panel --}}
        <div
            x-show="show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-4 scale-95"
            x-transition:enter-end="opacity-100 translate-y-0 scale-100"
            class="relative w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700"
            role="dialog"
            aria-modal="true"
        >
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5 dark:border-slate-800">
                <div>
                    <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Report a bug') }}</h2>
                    <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{{ __('Tell us what went wrong — a screenshot helps a lot.') }}</p>
                </div>
                <button type="button" x-on:click="show = false" class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800" aria-label="{{ __('Close') }}">
                    <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd" /></svg>
                </button>
            </div>

            <div class="px-5 py-4">
                @if ($submitted)
                    <div class="py-8 text-center">
                        <div class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-full bg-emerald-50 dark:bg-emerald-500/10">
                            <svg class="h-5 w-5 text-emerald-600 dark:text-emerald-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        </div>
                        <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ __('Thanks — we\'ve received your report.') }}</p>
                        <button type="button" x-on:click="show = false" class="mt-4 inline-flex h-8 items-center rounded-md border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">{{ __('Close') }}</button>
                    </div>
                @else
                    <form wire:submit="submit" class="space-y-4">
                        <div>
                            <label for="bug-description" class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('What happened?') }}</label>
                            <textarea id="bug-description" wire:model="description" rows="4" required
                                placeholder="{{ __('Describe the problem') }}"
                                class="block w-full rounded-lg border-slate-300 bg-white text-sm text-slate-900 shadow-sm transition focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100"></textarea>
                            @error('description') <p class="mt-1 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="bug-url" class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Page link') }}</label>
                            <input id="bug-url" wire:model="url" type="text"
                                class="block h-8 w-full rounded-lg border-slate-300 bg-white px-2.5 text-xs text-slate-600 shadow-sm transition focus:border-orange-500 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300" />
                            @error('url') <p class="mt-1 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <span class="mb-1 block text-[11px] font-medium text-slate-700 dark:text-slate-300">{{ __('Screenshot (optional)') }}</span>
                            @if ($screenshotDataUrl !== '')
                                <div class="flex items-start gap-3">
                                    <img src="{{ $screenshotDataUrl }}" alt="{{ __('Screenshot preview') }}" class="max-h-28 rounded-lg border border-slate-200 dark:border-slate-700" />
                                    <div class="flex flex-col gap-1.5">
                                        <button type="button" x-on:click="startSnip()" class="inline-flex h-7 items-center rounded-md border border-slate-200 px-2.5 text-[11px] font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">{{ __('Retake') }}</button>
                                        <button type="button" wire:click="removeScreenshot" class="inline-flex h-7 items-center rounded-md border border-slate-200 px-2.5 text-[11px] font-medium text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">{{ __('Remove screenshot') }}</button>
                                    </div>
                                </div>
                            @else
                                <button type="button" x-on:click="startSnip()" :disabled="capturing"
                                    class="inline-flex h-8 items-center gap-1.5 rounded-md border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 disabled:opacity-60 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800">
                                    <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" /><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z" /></svg>
                                    <span x-show="!capturing">{{ __('Capture area') }}</span>
                                    <span x-show="capturing" x-cloak>{{ __('Capturing…') }}</span>
                                </button>
                                <p class="mt-1 text-[11px] text-slate-400 dark:text-slate-500">{{ __('Drag to select the area to capture. Press Esc to cancel.') }}</p>
                            @endif
                            @error('screenshotDataUrl') <p class="mt-1 text-[11px] text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex items-center justify-end gap-2 border-t border-slate-100 pt-3 dark:border-slate-800">
                            <button type="button" x-on:click="show = false" class="inline-flex h-8 items-center rounded-md px-3 text-xs font-medium text-slate-500 transition hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">{{ __('Cancel') }}</button>
                            <button type="submit" wire:loading.attr="disabled" wire:target="submit"
                                class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700 disabled:opacity-60">
                                <span wire:loading.remove wire:target="submit">{{ __('Send report') }}</span>
                                <span wire:loading.inline-flex wire:target="submit" class="items-center gap-1.5">
                                    <svg class="h-3.5 w-3.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25"></circle><path fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" class="opacity-75"></path></svg>
                                    {{ __('Sending…') }}
                                </span>
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
