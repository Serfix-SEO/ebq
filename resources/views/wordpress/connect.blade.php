<x-layouts.app>
    <div class="mx-auto max-w-xl space-y-5">
        <div class="flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-400">
                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244" /></svg>
            </span>
            <div>
                <h1 class="text-xl font-bold tracking-tight">{{ __('Connect WordPress to Serfix') }}</h1>
                <p class="text-sm text-slate-500 dark:text-slate-400">{{ __('The plugin on') }} <span class="font-semibold text-slate-700 dark:text-slate-200">{{ $siteHost }}</span> {{ __('is asking to read insights.') }}</p>
            </div>
        </div>

        <form method="POST" action="{{ route('wordpress.connect.approve') }}" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            @csrf
            <input type="hidden" name="site_url" value="{{ $siteUrl }}">
            <input type="hidden" name="redirect" value="{{ $redirect }}">
            <input type="hidden" name="state" value="{{ $state }}">

            <div class="rounded-lg border border-slate-200 bg-slate-50/60 p-4 text-xs dark:border-slate-700 dark:bg-slate-800/40">
                <dl class="space-y-1.5">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">{{ __('Site') }}</dt><dd class="font-mono text-slate-800 dark:text-slate-200">{{ $siteUrl }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">{{ __('Token will return to') }}</dt><dd class="truncate font-mono text-slate-800 dark:text-slate-200" title="{{ $redirect }}">{{ \Illuminate\Support\Str::limit($redirect, 60) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500 dark:text-slate-400">{{ __('Token scope') }}</dt><dd class="text-slate-800 dark:text-slate-200">{{ __('read insights (one website)') }}</dd></div>
                </dl>
            </div>

            <div class="mt-5">
                <label for="website_id" class="mb-1 block text-xs font-semibold text-slate-700 dark:text-slate-300">{{ __('Link this WordPress site to') }}</label>
                @if ($websites->isEmpty())
                    <p class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800/60 dark:bg-amber-900/30 dark:text-amber-300">
                        {{ __('You don\'t have any websites yet.') }} <a href="{{ route('onboarding') }}" class="font-semibold underline">{{ __('Add one') }}</a> {{ __('and come back.') }}
                    </p>
                @else
                    <select id="website_id" name="website_id" required
                        class="h-9 w-full rounded-md border border-slate-300 bg-white px-2.5 text-sm shadow-sm focus:border-orange-500 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100">
                        @foreach ($websites as $w)
                            <option value="{{ $w->id }}" @selected($suggestedWebsiteId === $w->id)>{{ $w->domain }}</option>
                        @endforeach
                    </select>
                    @if ($suggestedWebsiteId)
                        <p class="mt-1 text-[11px] text-emerald-600 dark:text-emerald-400">{{ __('Matched to the domain you\'re connecting.') }}</p>
                    @endif
                @endif
            </div>

            <div class="mt-6 flex items-center justify-between gap-3">
                <a href="{{ route('dashboard') }}" class="text-xs font-semibold text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200">{{ __('Cancel') }}</a>
                <button type="submit" @disabled($websites->isEmpty())
                    class="inline-flex h-9 items-center gap-1.5 rounded-md bg-orange-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-500 disabled:opacity-60">
                    {{ __('Connect and send token →') }}
                </button>
            </div>
            <p class="mt-3 text-[11px] text-slate-400">{{ __('You\'ll be redirected back to WordPress with a one-time token. Revoke any time from') }} <span class="font-semibold">{{ __('Settings → Integrations') }}</span>.</p>
        </form>
    </div>
</x-layouts.app>
