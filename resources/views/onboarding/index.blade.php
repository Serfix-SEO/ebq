<x-layouts.onboarding :title="'Get Started — Serfix'">
    @if (session()->has('impersonator_id'))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            You are impersonating another client account.
            @if (auth()->user() && \App\Support\TrialStatus::isLockedOut(auth()->user()))
                <span class="font-semibold">This client's trial has expired — on their own login they only see the Billing page (impersonation bypasses that lockout).</span>
            @endif
            <form method="POST" action="{{ route('admin.impersonation.stop') }}" class="inline-block">
                @csrf
                <button type="submit" class="ms-2 font-semibold underline">Return to admin</button>
            </form>
        </div>
    @endif
    @if (auth()->user() && \App\Support\TrialStatus::isExpired(auth()->user()))
        {{-- Trial expired: show the state + a path to billing, never the
             add-website flow (a site added now would just enter the deletion
             countdown again). Normally the lockout middleware sends expired
             users straight to /billing — this covers impersonating admins
             and any other path that still lands here. --}}
        <div class="space-y-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-lg font-bold tracking-tight text-slate-900 dark:text-slate-100">{{ __('Your free trial has ended') }}</h1>
                    <p class="mt-1 text-xs text-slate-600 dark:text-slate-400">
                        @if (auth()->user()->trial_data_deleted_at)
                            {{ __('Your trial websites and their data have been removed.') }}
                        @else
                            {{ __('Your data is held for 3 days after expiry — subscribe to keep it.') }}
                        @endif
                    </p>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="shrink-0">
                    @csrf
                    <button type="submit"
                        class="inline-flex h-8 items-center whitespace-nowrap rounded-md border border-slate-200 px-3 text-xs font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-slate-100">
                        {{ __('Log out') }}
                    </button>
                </form>
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-6 text-center shadow-sm dark:border-slate-800 dark:bg-slate-900">
                <div class="mx-auto mb-3 flex h-11 w-11 items-center justify-center rounded-xl bg-amber-50 dark:bg-amber-500/10">
                    <svg class="h-5 w-5 text-amber-600 dark:text-amber-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                </div>
                <h2 class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ __('Your free trial has ended') }}</h2>
                <p class="mx-auto mt-1 max-w-sm text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Pick a plan to keep using Serfix. Once subscribed, you can set up your website again in minutes.') }}
                </p>
                <div class="mt-4">
                    <a href="{{ route('billing.show') }}"
                        class="inline-flex h-8 items-center gap-1.5 rounded-md bg-orange-600 px-3 text-xs font-semibold text-white shadow-sm transition hover:bg-orange-700">
                        {{ __('View plans & subscribe') }}
                        <svg class="h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    </a>
                </div>
            </div>
        </div>
    @else
        <livewire:onboarding.connect-google />
    @endif
</x-layouts.onboarding>
