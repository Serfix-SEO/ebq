<x-layouts.app>
    <div class="max-w-2xl mx-auto py-16 px-4 text-center">
        <div class="inline-flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 text-amber-600 mb-6">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="8" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
                <circle cx="12" cy="12" r="10"/>
            </svg>
        </div>

        <h1 class="text-3xl font-bold text-slate-900 tracking-tight">{{ __('Checkout cancelled') }}</h1>
        <p class="text-slate-600 mt-3 text-base leading-relaxed">
            {{ __('No charge was made and no subscription was started. You can pick a plan again whenever you\'re ready.') }}
        </p>

        <div class="mt-8 flex items-center justify-center gap-3 flex-wrap">
            <a href="{{ route('pricing') }}"
               class="inline-flex items-center rounded-md bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-500">
                {{ __('Back to pricing') }}
            </a>
            <a href="{{ route('dashboard') }}"
               class="inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                {{ __('Go to dashboard') }}
            </a>
        </div>
    </div>
</x-layouts.app>
