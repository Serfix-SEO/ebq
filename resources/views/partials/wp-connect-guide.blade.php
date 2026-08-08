{{--
    "How to connect WordPress" — the step-by-step for creating an Application
    Password, shown inside the WordPress tab of the publishing connect panel.

    Screens are REAL screenshots from a WordPress admin (public/guide/wordpress/).
    Each one degrades to a drawn SVG if the file is missing, so a lost asset
    never leaves a step unillustrated. Annotations are HTML over/under the
    image rather than text baked into the PNG: translatable, crisp at any zoom,
    and editable without re-exporting an image.

    @param bool $guideForceOpen  reopen after a failed connection attempt.
--}}
@php
    $guideForceOpen = $guideForceOpen ?? false;
    $shot = fn (string $file) => is_file(public_path('guide/wordpress/'.$file))
        ? asset('guide/wordpress/'.$file)
        : null;
    $imgClass = 'w-full max-w-xl rounded-lg border border-slate-200 shadow-sm dark:border-slate-700';
    $ink = 'fill-slate-700 dark:fill-slate-200';
    $muted = 'fill-slate-400 dark:fill-slate-500';
    $panel = 'fill-white dark:fill-slate-900';
    $panelEdge = 'stroke-slate-200 dark:stroke-slate-700';
    $bar = 'fill-slate-100 dark:fill-slate-800';
    $stepClass = 'flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-orange-600 text-xs font-bold text-white';
    // Caption under a screenshot, pointing at what the red box marks.
    $caption = 'mt-1.5 text-[11px] font-medium text-orange-700 dark:text-orange-400';
@endphp

<details class="mt-4 rounded-xl border border-slate-200 bg-slate-50/60 dark:border-slate-700 dark:bg-slate-800/40"
         wire:key="wp-guide-{{ $guideForceOpen ? 'error' : 'idle' }}" @if ($guideForceOpen) open @else open @endif>
    <summary class="cursor-pointer list-none px-4 py-3 text-sm font-bold text-slate-800 marker:content-none dark:text-slate-100">
        <span class="inline-flex items-center gap-2">
            <svg class="h-4 w-4 text-orange-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 16v-4m0-4h.01"/>
            </svg>
            {{ __('How to get your WordPress application password') }}
            <span class="text-xs font-medium text-slate-400">{{ __('(about 1 minute)') }}</span>
        </span>
    </summary>

    <div class="space-y-5 border-t border-slate-200 px-4 py-4 dark:border-slate-700">

        @if ($guideForceOpen)
            {{-- Reopened because the connection failed: the steps below are the
                 answer to nearly every cause, so lead with that instead of
                 leaving the raw API error as the last word. --}}
            <p class="rounded-lg border border-error/30 bg-error/5 px-3 py-2 text-xs font-semibold text-error">
                {{ __('The connection didn’t go through. Walk the steps below — the password is shown only once, so the most common fix is simply creating a fresh one.') }}
            </p>
        @endif

        {{-- 1 --}}
        <div class="flex gap-3">
            <span class="{{ $stepClass }}">1</span>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ __('Sign in to your WordPress admin') }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Usually your-site.com/wp-admin. The account must be an Author, Editor or Administrator — Subscribers cannot publish.') }}
                </p>
            </div>
        </div>

        {{-- 2 — Application Passwords section --}}
        <div class="flex gap-3">
            <span class="{{ $stepClass }}">2</span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ __('Open Users → Profile and scroll to “Application Passwords”') }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('It sits near the bottom of your own profile page, below “Sessions”. Type a name there — “Serfix” is fine — then click Add Application Password.') }}
                </p>
                <p class="mt-1 text-xs font-medium text-slate-600 dark:text-slate-300">
                    {{ __('Do NOT use “Set New Password” higher up the page: that changes your real login password and is not what we need.') }}
                </p>

                @if ($url = $shot('01-application-passwords.png'))
                    <img src="{{ $url }}" class="{{ $imgClass }} mt-3" loading="lazy" decoding="async"
                         alt="{{ __('The Application Passwords section of a WordPress profile page') }}">
                    <p class="{{ $caption }}">
                        {{ __('The red box marks the name field and the button you need.') }}
                    </p>
                @else
                    <svg viewBox="0 0 420 190" class="mt-3 w-full max-w-lg rounded-lg border border-slate-200 dark:border-slate-700" role="img"
                         aria-label="{{ __('The Application Passwords section of a WordPress profile page') }}">
                        <rect width="420" height="190" class="{{ $panel }}"/>
                        <rect x="0" y="0" width="86" height="190" class="{{ $bar }}"/>
                        @foreach ([16, 34, 52, 88, 106] as $i => $y)
                            <rect x="10" y="{{ $y }}" width="{{ [46, 52, 40, 44, 50][$i] }}" height="6" rx="3" class="{{ $muted }}"/>
                        @endforeach
                        <rect x="0" y="64" width="86" height="18" class="fill-orange-100 dark:fill-orange-950"/>
                        <rect x="10" y="70" width="34" height="6" rx="3" class="fill-orange-600"/>
                        <text x="52" y="77" class="fill-orange-700 dark:fill-orange-300" style="font-size:7px;font-weight:700">{{ __('Users') }}</text>
                        <text x="102" y="24" class="{{ $ink }}" style="font-size:11px;font-weight:700">{{ __('Profile') }}</text>
                        @foreach ([40, 54, 68] as $y)
                            <rect x="102" y="{{ $y }}" width="120" height="6" rx="3" class="{{ $muted }}"/>
                            <rect x="240" y="{{ $y }}" width="150" height="6" rx="3" class="{{ $bar }}" stroke-width="1"/>
                        @endforeach
                        <rect x="98" y="92" width="304" height="80" rx="6" class="fill-orange-50 dark:fill-orange-950/40" stroke-width="1.5" style="stroke:#F26419"/>
                        <text x="108" y="108" style="font-size:9px;font-weight:800;fill:#C44E0E">{{ __('Application Passwords') }}</text>
                        <rect x="108" y="118" width="180" height="5" rx="2.5" class="{{ $muted }}"/>
                        <rect x="108" y="134" width="150" height="16" rx="4" class="{{ $panel }} {{ $panelEdge }}" stroke-width="1"/>
                        <text x="114" y="145" class="{{ $muted }}" style="font-size:7px">{{ __('New Application Password Name') }}</text>
                        <rect x="266" y="134" width="86" height="16" rx="4" style="fill:#F26419"/>
                        <text x="273" y="145" style="font-size:7px;font-weight:700;fill:#ffffff">{{ __('Add New') }}</text>
                    </svg>
                @endif
            </div>
        </div>

        {{-- 3 — the reveal --}}
        <div class="flex gap-3">
            <span class="{{ $stepClass }}">3</span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ __('Copy the password WordPress shows you') }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('It appears once, as six four-character groups. Close the page without copying and it is gone for good — delete that entry and add another.') }}
                </p>

                @if ($url = $shot('02-password-revealed.png'))
                    <img src="{{ $url }}" class="{{ $imgClass }} mt-3" loading="lazy" decoding="async"
                         alt="{{ __('WordPress showing a newly generated application password with a Copy button') }}">
                    <p class="{{ $caption }}">
                        {{ __('Use the Copy button — the spaces are part of it, and we accept it exactly as WordPress shows it.') }}
                    </p>
                @else
                    <svg viewBox="0 0 420 96" class="mt-3 w-full max-w-lg rounded-lg border border-slate-200 dark:border-slate-700" role="img"
                         aria-label="{{ __('WordPress showing a newly generated application password with a Copy button') }}">
                        <rect width="420" height="96" class="{{ $panel }}"/>
                        <rect x="16" y="16" width="388" height="64" rx="6" class="fill-emerald-50 dark:fill-emerald-950/40" stroke-width="1.5" style="stroke:#10b981"/>
                        <text x="28" y="36" style="font-size:8px;font-weight:700" class="fill-emerald-800 dark:fill-emerald-300">{{ __('Your new password for Serfix is:') }}</text>
                        <rect x="28" y="44" width="200" height="22" rx="4" class="{{ $panel }} {{ $panelEdge }}" stroke-width="1"/>
                        <text x="38" y="59" class="{{ $ink }}" style="font-size:11px;font-family:monospace;letter-spacing:1px">abcd EFGH 1234 wxyz</text>
                        <text x="240" y="59" class="fill-emerald-700 dark:fill-emerald-400" style="font-size:7px;font-weight:700">{{ __('Copy this whole value') }}</text>
                    </svg>
                @endif
            </div>
        </div>

        {{-- 4 — the username, which is where connections silently fail --}}
        <div class="flex gap-3">
            <span class="{{ $stepClass }}">4</span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ __('Find your WordPress username on the same page') }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Scroll up to “Name → Username”. Copy it exactly — on many sites it looks like an email address, and it is NOT the same as your display name.') }}
                </p>

                @if ($url = $shot('03-username.png'))
                    <img src="{{ $url }}" class="{{ $imgClass }} mt-3" loading="lazy" decoding="async"
                         alt="{{ __('The Username field on a WordPress profile page') }}">
                    <p class="{{ $caption }}">
                        {{ __('Whatever is in this greyed-out box is your username — copy it character for character.') }}
                    </p>
                @endif
            </div>
        </div>

        {{-- 5 --}}
        <div class="flex gap-3">
            <span class="{{ $stepClass }}">5</span>
            <div class="min-w-0">
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ __('Paste both below, with your site address') }}</p>
                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {{ __('Site address is the front page of your site, including https://.') }}
                </p>
            </div>
        </div>

        {{-- Troubleshooting: where connections actually fail. --}}
        <div class="rounded-lg border border-amber-200 bg-amber-50/70 px-4 py-3 dark:border-amber-900/60 dark:bg-amber-950/30">
            <p class="text-xs font-bold text-amber-900 dark:text-amber-200">{{ __('Don’t see “Application Passwords”, or it won’t connect?') }}</p>
            <ul class="mt-1.5 space-y-1 text-xs text-amber-900/90 dark:text-amber-200/90">
                <li>• {{ __('Your site must be on HTTPS — WordPress hides the feature on plain http.') }}</li>
                <li>• {{ __('It needs WordPress 5.6 or newer.') }}</li>
                <li>• {{ __('Security plugins (Wordfence, iThemes Security, Solid Security) often disable application passwords or the REST API — check their settings.') }}</li>
                <li>• {{ __('Some managed hosts disable it platform-wide; your host’s support can re-enable it.') }}</li>
                <li>• {{ __('If it still fails, delete the password in WordPress and create a fresh one — a half-copied value is the usual culprit.') }}</li>
            </ul>
        </div>

        <p class="text-xs text-slate-500 dark:text-slate-400">
            {{ __('An application password only allows posting through the WordPress API. It is not your login password, it cannot be used to sign in to wp-admin, and you can revoke it at any time from the same screen.') }}
        </p>
    </div>
</details>
