@php $recaptcha = \App\Support\Recaptcha::isEnabled(); @endphp
<div>
    @if (! $websiteId)
        {{-- ── Domain capture (pre-wizard) ──────────────────────────── --}}
        <div class="mx-auto max-w-xl">
            <div class="text-center">
                <span class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 3.75-5.75 3.75-9S14.5 5.5 12 3m0 18c-2.5-2.5-3.75-5.75-3.75-9S9.5 5.5 12 3M3.6 9h16.8M3.6 15h16.8"/></svg>
                </span>
                <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('What website is this for?') }}</h1>
                <p class="mx-auto mt-3 max-w-md text-[15px] leading-7 text-slate-500 dark:text-slate-400">{{ __('We will research your niche and write SEO articles for this site. It becomes your website when you finish — free for :days days, no card required.', ['days' => \App\Support\ContentAutopilotConfig::trialDays()]) }}</p>
            </div>

            <div class="mt-8 rounded-3xl border border-slate-200 bg-white p-6 shadow-xl sm:p-8 dark:border-slate-800 dark:bg-slate-900">
                <form wire:submit="startWithDomain" class="space-y-4">
                    <div>
                        <input type="text" wire:model="domain" placeholder="yourwebsite.com" autofocus
                            class="w-full rounded-xl border border-slate-300 px-4 py-3.5 text-sm focus:border-orange-500 focus:outline-none focus:ring-1 focus:ring-orange-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100" />
                        @error('domain') <p class="mt-1.5 text-xs font-medium text-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($recaptcha)
                        <div wire:ignore class="g-recaptcha" data-sitekey="{{ config('services.recaptcha.site_key') }}" data-callback="onContentCaptcha"></div>
                    @endif
                    <button type="submit" wire:loading.attr="disabled" wire:target="startWithDomain"
                        class="inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-6 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110 disabled:opacity-70">
                        <svg wire:loading wire:target="startWithDomain" class="-ms-1 me-1 h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/></svg>
                        <span wire:loading.remove wire:target="startWithDomain">{{ __('Get started') }}</span>
                        <span wire:loading wire:target="startWithDomain">{{ __('Setting up…') }}</span>
                    </button>
                </form>
            </div>
        </div>

        @if ($recaptcha)
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
            <script>window.onContentCaptcha = (t) => { @this.set('recaptchaToken', t); };</script>
        @endif
    @else
        {{-- ── Full wizard (business → … → first articles → account) ── --}}
        @include('livewire.content.partials.wizard')
    @endif
</div>
