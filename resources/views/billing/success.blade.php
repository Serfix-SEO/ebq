<x-layouts.app>
    @php
        /** @var \App\Models\User $user */
        $subscription = $user->subscription('default');
        $isTrialing = $subscription ? $subscription->onTrial() : false;
        $trialEnd = $user->trial_ends_at?->diffForHumans();
    @endphp

    <div class="max-w-2xl mx-auto py-16 px-4 text-center">
        <div class="inline-flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 mb-6">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
        </div>

        <h1 class="text-3xl font-bold text-slate-900 tracking-tight">{{ __('Subscription active') }}</h1>
        <p class="text-slate-600 mt-3 text-base leading-relaxed">
            @if ($isTrialing && $trialEnd)
                {{ __('Your free trial is running. Your card won\'t be charged until it ends :trialEnd, and you can cancel any time before then from Billing.', ['trialEnd' => $trialEnd]) }}
            @else
                {{ __('Thanks for subscribing. Your account is fully unlocked.') }}
            @endif
        </p>

        <div class="mt-8 flex items-center justify-center gap-3 flex-wrap">
            <a href="{{ route('dashboard') }}"
               class="inline-flex items-center rounded-md bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-500">
                {{ __('Go to dashboard') }}
            </a>
            <a href="{{ route('billing.show') }}"
               class="inline-flex items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                {{ __('Manage subscription') }}
            </a>
        </div>

        <p class="text-xs text-slate-500 mt-10">
            {{ __('Receipt and invoice are emailed automatically. You can change your card or cancel from the Billing page at any time.') }}
        </p>
    </div>
</x-layouts.app>
