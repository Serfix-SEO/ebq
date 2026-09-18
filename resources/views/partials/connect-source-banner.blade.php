{{-- Persistent nudge to connect a missing data source on the current
     website. Shown when the active site is missing GA and/or GSC so users
     who onboarded with the minimum (or skipped Google entirely) always
     have a one-click path to the full report. Dismissible per page load. --}}
@php
    $bannerWebsite = null;
    $bannerWebsiteId = (string) session('current_website_id', '');
    if ($bannerWebsiteId !== '' && auth()->check()) {
        $candidate = \App\Models\Website::find($bannerWebsiteId);
        // Only nudge the owner — shared members can't reconfigure sources.
        if ($candidate && $candidate->user_id === auth()->id()) {
            $bannerWebsite = $candidate;
        }
    }
@endphp
@if ($bannerWebsite && (! $bannerWebsite->hasGa() || ! $bannerWebsite->hasGsc()))
    @php
        if (! $bannerWebsite->hasGa() && ! $bannerWebsite->hasGsc()) {
            $missingLabel = __('Google Analytics and Search Console');
            $bannerLead = __('Connect your data to unlock reports');
        } elseif (! $bannerWebsite->hasGsc()) {
            $missingLabel = __('Search Console');
            $bannerLead = __('Connect Search Console to unlock the full report');
        } else {
            $missingLabel = __('Google Analytics');
            $bannerLead = __('Connect Google Analytics to unlock the full report');
        }
        // Content-only mode has no "full report" to unlock — promising one
        // left a client asking where their site details were (2026-09-17).
        // Say what each source actually does here.
        $bannerDetail = null;
        if (! config('features.seo_platform_ui')) {
            if (! $bannerWebsite->hasGa() && ! $bannerWebsite->hasGsc()) {
                $bannerLead = __('Connect Google to track your articles');
                $bannerDetail = __('Search Console lets us submit each new article to Google. Analytics shows how many visitors each article brings.');
            } elseif (! $bannerWebsite->hasGsc()) {
                $bannerLead = __('Connect Search Console so we can submit your articles to Google');
                $bannerDetail = __('Until then, new articles wait for Google to find them on its own.');
            } else {
                $bannerLead = __('Connect Google Analytics to see visitors per article');
                $bannerDetail = __('Once connected, the Tracker shows how many visitors each published article brings.');
            }
        }
    @endphp
    <div
        x-data="{ open: true }"
        x-show="open"
        class="mb-4 flex flex-wrap items-start justify-between gap-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700/40 dark:bg-amber-900/20 dark:text-amber-100"
        role="status"
    >
        <x-nodus state="searching" :size="40" class="mt-0.5 flex-none text-slate-400 dark:text-slate-500" />
        {{-- min-width floor: without it the buttons squeezed this text to
             2-3 words per line on phones (flex-1 alone can shrink to nothing). --}}
        <div class="min-w-0 flex-1" style="min-width: 12rem">
            <div class="font-semibold">{{ $bannerLead }}</div>
            <div class="mt-1">{{ __('You haven’t connected') }} {{ $missingLabel }} {{ __('for') }} <span class="font-medium">{{ $bannerWebsite->domain ?: __('this website') }}</span>. {{ $bannerDetail ?? __('Some sections stay empty until you do.') }}</div>
        </div>
        <div class="flex items-center gap-2">
            <button
                type="button"
                x-on:click="window.dispatchEvent(new CustomEvent('open-connect-sources'))"
                class="rounded-md bg-amber-600 px-3 py-1.5 font-medium text-white hover:bg-amber-700"
            >{{ __('Connect now') }}</button>
            <button
                type="button"
                x-on:click="open = false"
                class="rounded-md p-1 text-amber-700 hover:bg-amber-100 dark:text-amber-200 dark:hover:bg-amber-900/40"
                aria-label="{{ __('Dismiss') }}"
            >&times;</button>
        </div>
    </div>
@endif
