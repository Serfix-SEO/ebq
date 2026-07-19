<div class="mx-auto max-w-3xl">
    @if (session('error'))
        <div class="mb-4 rounded-xl border border-error/25 bg-white p-3 text-sm font-medium text-error dark:bg-slate-900">{{ session('error') }}</div>
    @endif

    <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-lg shadow-orange-600/25">
            <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
        </span>
        <h1 class="mt-5 text-2xl font-extrabold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Content Autopilot') }}</h1>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
            {{ __('An expert SEO article for :site — written, optimized, illustrated and published for you, on autopilot.', ['site' => $website?->domain ?? __('your website')]) }}
        </p>

        @if ($state === 'trial')
            <div class="mx-auto mt-6 max-w-md rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                {{ __(':days-day free trial · :n free articles · no card required', ['days' => $trialDays, 'n' => $trialArticles]) }}
            </div>
            <button wire:click="startTrial" wire:loading.attr="disabled"
                class="mt-6 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-8 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                {{ __('Start free trial') }}
            </button>
            <p class="mt-4 text-xs text-slate-400">
                {{ __('After the trial: :first for your first month, then $:m/mo — or $:a/mo billed yearly.', ['first' => '$'.$prices['first_month'], 'm' => $prices['monthly'], 'a' => $prices['annual']]) }}
            </p>

        @elseif ($state === 'pricing')
            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border-2 border-orange-300 bg-orange-50/40 p-6 text-start dark:border-orange-900 dark:bg-orange-950/30">
                    <div class="inline-flex rounded-full bg-orange-600 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-white">{{ __('$:p first month', ['p' => $prices['first_month']]) }}</div>
                    <div class="mt-3 text-3xl font-extrabold text-slate-900 dark:text-slate-100">${{ $prices['monthly'] }}<span class="text-base font-medium text-slate-500">/{{ __('mo') }}</span></div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ __('Billed monthly') }}</div>
                    <a href="{{ route('content.billing.checkout', ['interval' => 'monthly', 'website' => $website?->id]) }}"
                        class="mt-4 block rounded-xl bg-orange-600 px-4 py-2.5 text-center text-sm font-bold text-white hover:bg-orange-700">{{ __('Choose monthly') }}</a>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-6 text-start dark:border-slate-700 dark:bg-slate-900">
                    <div class="inline-flex rounded-full bg-success/15 px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wide text-success">{{ __('Best value') }}</div>
                    <div class="mt-3 text-3xl font-extrabold text-slate-900 dark:text-slate-100">${{ $prices['annual'] }}<span class="text-base font-medium text-slate-500">/{{ __('mo') }}</span></div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ __('Billed yearly') }}</div>
                    <a href="{{ route('content.billing.checkout', ['interval' => 'annual', 'website' => $website?->id]) }}"
                        class="mt-4 block rounded-xl border border-slate-300 px-4 py-2.5 text-center text-sm font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800">{{ __('Choose annual') }}</a>
                </div>
            </div>
            <p class="mt-4 text-xs text-slate-400">{{ __('Each additional website: $:m/mo (or $:a/mo billed yearly).', ['m' => $prices['addon_monthly'], 'a' => $prices['addon_annual']]) }}</p>

        @elseif ($state === 'activate')
            <div class="mx-auto mt-6 max-w-md rounded-xl bg-success/10 p-4 text-sm text-success">
                {{ __('Your content subscription has a free website slot.') }}
            </div>
            <button wire:click="activate" wire:loading.attr="disabled"
                class="mt-6 inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-8 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                {{ __('Activate on :site', ['site' => $website?->domain ?? __('this website')]) }}
            </button>

        @else {{-- add_website --}}
            <div class="mx-auto mt-6 max-w-md rounded-xl bg-slate-50 p-4 text-sm text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                {{ __('All your website slots are in use. Add :site to your content plan.', ['site' => $website?->domain ?? __('this website')]) }}
            </div>
            <form method="POST" action="{{ route('content.billing.add-website') }}" class="mt-6">
                @csrf
                <input type="hidden" name="website" value="{{ $website?->id }}" />
                <button type="submit"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-8 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('Add this website — $:m/mo', ['m' => $prices['addon_monthly']]) }}
                </button>
            </form>
        @endif
    </div>
</div>
