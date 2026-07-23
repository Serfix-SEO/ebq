@props([
    'title' => 'Serfix',
    'description' => 'Serfix — clear SEO operations with rankings, backlinks, audits, and AI-powered content tools.',
    'canonical' => null,
    'active' => null,
    'ogImage' => null,
    'robots' => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
    // 'article' on blog posts (unlocks article:published_time/modified_time
    // below); default 'website' everywhere else.
    'ogType' => 'website',
    'publishedTime' => null,
    'modifiedTime' => null,
])
@php
    $canonicalUrl = $canonical ?? url()->current();
    $ogImageUrl = $ogImage ?? asset('serfix-logo.png');
    $homeUrl = route('landing');

    // Site-wide structured data — Organization + WebSite. Page-specific
    // schema (SoftwareApplication, FAQPage, etc.) comes in via the
    // optional `schema` slot.
    $orgSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => 'Serfix',
        'url' => $homeUrl,
        'logo' => asset('serfix-logo.png'),
        'description' => 'Connected SEO suite: rankings, backlinks, audits, live Search Console data, and AI content tools.',
    ];
    $siteSchema = [
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => 'Serfix',
        'url' => $homeUrl,
    ];
    $jsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    @include('partials.google-analytics')
    @include('partials.clarity')

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#ffffff">

    @include('partials.favicon-links')

    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    <meta name="robots" content="{{ $robots }}">
    <meta name="author" content="Serfix">
    <link rel="canonical" href="{{ $canonicalUrl }}">

    {{-- Open Graph --}}
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:site_name" content="Serfix">
    <meta property="og:locale" content="en_US">
    <meta property="og:image" content="{{ $ogImageUrl }}">
    <meta property="og:image:secure_url" content="{{ $ogImageUrl }}">
    <meta property="og:image:alt" content="{{ $title }}">
    @if ($ogType === 'article' && $publishedTime)
        <meta property="article:published_time" content="{{ $publishedTime }}">
    @endif
    @if ($ogType === 'article' && $modifiedTime)
        <meta property="article:modified_time" content="{{ $modifiedTime }}">
    @endif

    {{-- Twitter card --}}
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $ogImageUrl }}">
    <meta name="twitter:image:alt" content="{{ $title }}">

    {{-- Structured data --}}
    <script type="application/ld+json">{!! json_encode($orgSchema, $jsonFlags) !!}</script>
    <script type="application/ld+json">{!! json_encode($siteSchema, $jsonFlags) !!}</script>
    @isset($schema)
        {{ $schema }}
    @endisset

    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
    @if (! app()->environment('testing'))
        @vite(['resources/css/app.css', 'resources/js/marketing.js'])
    @endif
</head>
<body class="min-h-full bg-white font-sans text-slate-900 antialiased selection:bg-slate-900 selection:text-white {{ app()->getLocale() === 'ar' ? 'font-arabic' : '' }}">
    @include('partials.locale-picker')
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:start-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-slate-900 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">{{ __('Skip to content') }}</a>

    <header x-data="{ mobileOpen: false }" @keydown.escape.window="mobileOpen = false" class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/80 backdrop-blur-xl">
        <div class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-4 lg:px-8">
            <a href="{{ route('landing') }}" class="inline-flex items-center" aria-label="Serfix home">
                <img src="{{ asset('serfix-logo.png') }}" alt="Serfix" width="101" height="36" class="h-9 w-auto object-contain">
            </a>

            <nav aria-label="Primary" class="hidden items-center gap-7 text-sm text-slate-600 md:flex">
                <a href="{{ route('features') }}" class="transition hover:text-slate-900 {{ $active === 'features' ? 'text-slate-900' : '' }}">{{ __('Features') }}</a>
                <a href="{{ route('content.landing') }}" class="inline-flex items-center transition hover:text-slate-900 {{ $active === 'content' ? 'text-slate-900' : '' }}">{{ __('Content AI') }}<span class="ms-1 rounded-full bg-orange-100 px-1.5 py-px text-[9px] font-bold uppercase tracking-wider text-orange-700">{{ __('New') }}</span></a>
                <a href="{{ route('guide') }}" class="transition hover:text-slate-900 {{ $active === 'guide' ? 'text-slate-900' : '' }}">{{ __('Guide') }}</a>
                <a href="{{ route('pricing') }}" class="transition hover:text-slate-900 {{ $active === 'pricing' ? 'text-slate-900' : '' }}">{{ __('Pricing') }}</a>
                <a href="{{ route('contact') }}" class="transition hover:text-slate-900 {{ $active === 'contact' ? 'text-slate-900' : '' }}">{{ __('Contact') }}</a>
                <a href="{{ route('wordpress-plugin') }}" class="inline-flex items-center transition hover:text-slate-900 {{ $active === 'wordpress' ? 'text-slate-900' : '' }}">{{ __('WordPress') }}@if (config('services.wordpress_plugin.coming_soon'))<span class="ms-1 rounded-full bg-orange-100 px-1.5 py-px text-[9px] font-bold uppercase tracking-wider text-orange-700">{{ __('Soon') }}</span>@endif</a>
                <a href="{{ route('pricing') }}#faq" class="transition hover:text-slate-900">{{ __('FAQ') }}</a>
            </nav>

            <div class="flex items-center gap-2">
                @if (\App\Support\LocaleConfig::active())
                    <a href="{{ route('locale.set', app()->getLocale() === 'ar' ? 'en' : 'ar') }}"
                        class="hidden rounded-lg px-2.5 py-2 text-xs font-semibold text-slate-500 transition hover:text-slate-900 sm:inline-flex">
                        {{ app()->getLocale() === 'ar' ? 'EN' : 'AR' }}
                    </a>
                @endif
                @auth
                    <form method="POST" action="{{ route('logout') }}" class="hidden sm:inline-flex">
                        @csrf
                        <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 transition hover:text-slate-900">{{ __('Log out') }}</button>
                    </form>
                    <a href="{{ route('dashboard') }}" class="hidden items-center rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 sm:inline-flex">{{ __('Dashboard') }}</a>
                @else
                    <a href="{{ route('login') }}" class="hidden rounded-lg px-3 py-2 text-sm font-medium text-slate-700 transition hover:text-slate-900 sm:inline-flex">{{ __('Sign in') }}</a>
                    <a href="{{ route('register') }}" class="hidden items-center rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800 sm:inline-flex">{{ __('Get started') }}</a>
                @endauth

                {{-- Mobile menu toggle: the nav above is `hidden md:flex`, and the CTA
                     buttons are `sm:inline-flex`, so below `sm` nothing was reachable —
                     this button + the panel below are the only way to see Features/
                     Pricing/Sign in/etc. on a phone. --}}
                <button type="button" @click="mobileOpen = !mobileOpen" :aria-expanded="mobileOpen.toString()" aria-controls="mobile-nav-panel"
                    class="inline-flex items-center justify-center rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 md:hidden">
                    <span class="sr-only">{{ __('Menu') }}</span>
                    <svg x-show="!mobileOpen" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                    <svg x-show="mobileOpen" style="display:none" class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </div>

        <div id="mobile-nav-panel" x-show="mobileOpen" x-cloak
            x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
            class="border-t border-slate-200/80 bg-white md:hidden">
            <nav aria-label="Primary mobile" @click="mobileOpen = false" class="mx-auto flex max-w-6xl flex-col gap-1 px-6 py-4 text-sm text-slate-600">
                <a href="{{ route('features') }}" class="rounded-lg px-3 py-2.5 transition hover:bg-slate-50 hover:text-slate-900 {{ $active === 'features' ? 'font-semibold text-slate-900' : '' }}">{{ __('Features') }}</a>
                <a href="{{ route('content.landing') }}" class="rounded-lg px-3 py-2.5 transition hover:bg-slate-50 hover:text-slate-900 {{ $active === 'content' ? 'font-semibold text-slate-900' : '' }}">{{ __('Content AI') }}<span class="ms-1 rounded-full bg-orange-100 px-1.5 py-px text-[9px] font-bold uppercase tracking-wider text-orange-700">{{ __('New') }}</span></a>
                <a href="{{ route('guide') }}" class="rounded-lg px-3 py-2.5 transition hover:bg-slate-50 hover:text-slate-900 {{ $active === 'guide' ? 'font-semibold text-slate-900' : '' }}">{{ __('Guide') }}</a>
                <a href="{{ route('pricing') }}" class="rounded-lg px-3 py-2.5 transition hover:bg-slate-50 hover:text-slate-900 {{ $active === 'pricing' ? 'font-semibold text-slate-900' : '' }}">{{ __('Pricing') }}</a>
                <a href="{{ route('contact') }}" class="rounded-lg px-3 py-2.5 transition hover:bg-slate-50 hover:text-slate-900 {{ $active === 'contact' ? 'font-semibold text-slate-900' : '' }}">{{ __('Contact') }}</a>
                <a href="{{ route('wordpress-plugin') }}" class="rounded-lg px-3 py-2.5 transition hover:bg-slate-50 hover:text-slate-900 {{ $active === 'wordpress' ? 'font-semibold text-slate-900' : '' }}">{{ __('WordPress') }}@if (config('services.wordpress_plugin.coming_soon'))<span class="ms-1 rounded-full bg-orange-100 px-1.5 py-px text-[9px] font-bold uppercase tracking-wider text-orange-700">{{ __('Soon') }}</span>@endif</a>
                <a href="{{ route('pricing') }}#faq" class="rounded-lg px-3 py-2.5 transition hover:bg-slate-50 hover:text-slate-900">{{ __('FAQ') }}</a>

                <div class="my-2 border-t border-slate-200"></div>

                @if (\App\Support\LocaleConfig::active())
                    <a href="{{ route('locale.set', app()->getLocale() === 'ar' ? 'en' : 'ar') }}" class="rounded-lg px-3 py-2.5 transition hover:bg-slate-50 hover:text-slate-900">
                        {{ app()->getLocale() === 'ar' ? __('English') : __('العربية') }}
                    </a>
                @endif
                @auth
                    <a href="{{ route('dashboard') }}" class="rounded-lg px-3 py-2.5 font-semibold text-slate-900 transition hover:bg-slate-50">{{ __('Dashboard') }}</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full rounded-lg px-3 py-2.5 text-start transition hover:bg-slate-50 hover:text-slate-900">{{ __('Log out') }}</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="rounded-lg px-3 py-2.5 transition hover:bg-slate-50 hover:text-slate-900">{{ __('Sign in') }}</a>
                    <a href="{{ route('register') }}" class="rounded-lg bg-slate-900 px-3 py-2.5 font-semibold text-white transition hover:bg-slate-800">{{ __('Get started') }}</a>
                @endauth
            </nav>
        </div>
    </header>

    <main id="main">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto grid max-w-6xl gap-10 px-6 py-14 text-sm text-slate-600 sm:grid-cols-2 lg:grid-cols-5 lg:px-8">
            <div class="lg:col-span-2">
                <a href="{{ route('landing') }}" class="inline-flex items-center" aria-label="Serfix home">
                    <img src="{{ asset('serfix-logo.png') }}" alt="Serfix" width="101" height="36" class="h-9 w-auto object-contain">
                </a>
                <p class="mt-4 max-w-xs text-slate-500">{{ __('The SEO command center for teams that ship every week. Discover, prioritize, execute, measure.') }}</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Product') }}</p>
                <ul class="mt-3 space-y-2.5">
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('features') }}">{{ __('Features') }}</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('guide') }}">{{ __('Guide') }}</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('pricing') }}">{{ __('Pricing') }}</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('wordpress-plugin') }}">{{ __('WordPress plugin') }}@if (config('services.wordpress_plugin.coming_soon'))<span class="ms-1 rounded-full bg-orange-100 px-1.5 py-px text-[9px] font-bold uppercase tracking-wider text-orange-700">{{ __('Soon') }}</span>@endif</a></li>
                </ul>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Company') }}</p>
                <ul class="mt-3 space-y-2.5">
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('pricing') }}#faq">{{ __('FAQ') }}</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('contact') }}">{{ __('Contact') }}</a></li>
                </ul>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">{{ __('Legal') }}</p>
                <ul class="mt-3 space-y-2.5">
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('terms-conditions') }}">{{ __('Terms') }}</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('privacy-policy') }}">{{ __('Privacy') }}</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('refund-policy') }}">{{ __('Refunds') }}</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t border-slate-200">
            <div class="mx-auto flex max-w-6xl flex-col gap-3 px-6 py-6 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between lg:px-8">
                <p>&copy; {{ date('Y') }} Serfix. {{ __('All rights reserved.') }}</p>
                <p>{{ __('Built for SEO teams that ship weekly.') }}</p>
            </div>
        </div>
    </footer>
</body>
</html>
