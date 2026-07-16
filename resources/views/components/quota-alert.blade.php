{{-- Prominent plan-limit alert — used wherever a monthly usage cap blocks an
     action, so the user immediately understands WHY it stopped and what to do.
     Render via <x-quota-alert :message="$msg" /> when
     \App\Support\QuotaMessage::isQuota($msg) says the error is a cap. --}}
@props(['message'])
<div class="mt-3 flex flex-col gap-4 rounded-2xl border-l-4 border-amber-500 bg-amber-50 p-5 ring-1 ring-amber-200 dark:bg-amber-500/10 dark:ring-amber-800 sm:flex-row sm:items-center sm:justify-between">
    <div class="flex items-start gap-3">
        <span class="flex h-10 w-10 flex-none items-center justify-center rounded-full bg-amber-500 text-white">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
        </span>
        <div>
            <p class="text-base font-bold text-amber-900 dark:text-amber-200">{{ __('You’ve reached your plan’s monthly limit') }}</p>
            <p class="mt-0.5 text-sm leading-relaxed text-amber-800 dark:text-amber-300">{{ $message }}</p>
        </div>
    </div>
    <a href="{{ route('billing.show') }}" wire:navigate
       class="inline-flex flex-none items-center justify-center gap-2 self-start rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-amber-700 sm:self-auto">
        {{ __('Upgrade plan') }}
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
    </a>
</div>
