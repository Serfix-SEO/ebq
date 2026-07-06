@props([
    'title' => 'Serfix',
    'description' => 'Serfix — clear SEO operations with rankings, backlinks, audits, and AI-powered content tools.',
    'canonical' => null,
    'active' => null,
    'ogImage' => null,
    'robots' => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
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
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
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
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">
    <meta property="og:site_name" content="Serfix">
    <meta property="og:locale" content="en_US">
    <meta property="og:image" content="{{ $ogImageUrl }}">
    <meta property="og:image:secure_url" content="{{ $ogImageUrl }}">
    <meta property="og:image:alt" content="{{ $title }}">

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
<body class="min-h-full bg-white font-sans text-slate-900 antialiased selection:bg-slate-900 selection:text-white">
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-slate-900 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white">Skip to content</a>

    <header class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/80 backdrop-blur-xl">
        <div class="mx-auto flex w-full max-w-6xl items-center justify-between px-6 py-4 lg:px-8">
            <a href="{{ route('landing') }}" class="inline-flex items-center" aria-label="Serfix home">
                <img src="{{ asset('serfix-logo.png') }}" alt="Serfix" width="101" height="36" class="h-9 w-auto object-contain">
            </a>

            <nav aria-label="Primary" class="hidden items-center gap-7 text-sm text-slate-600 md:flex">
                <a href="{{ route('features') }}" class="transition hover:text-slate-900 {{ $active === 'features' ? 'text-slate-900' : '' }}">Features</a>
                <a href="{{ route('guide') }}" class="transition hover:text-slate-900 {{ $active === 'guide' ? 'text-slate-900' : '' }}">Guide</a>
                <a href="{{ route('pricing') }}" class="transition hover:text-slate-900 {{ $active === 'pricing' ? 'text-slate-900' : '' }}">Pricing</a>
                <a href="{{ route('contact') }}" class="transition hover:text-slate-900 {{ $active === 'contact' ? 'text-slate-900' : '' }}">Contact</a>
                <a href="{{ route('wordpress-plugin') }}" class="transition hover:text-slate-900 {{ $active === 'wordpress' ? 'text-slate-900' : '' }}">WordPress</a>
                <a href="{{ route('landing') }}#faq" class="transition hover:text-slate-900">FAQ</a>
            </nav>

            <div class="flex items-center gap-2">
                <a href="{{ route('login') }}" class="hidden rounded-lg px-3 py-2 text-sm font-medium text-slate-700 transition hover:text-slate-900 sm:inline-flex">Sign in</a>
                <a href="{{ route('register') }}" class="inline-flex items-center rounded-lg bg-slate-900 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-slate-800">Get started</a>
            </div>
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
                <p class="mt-4 max-w-xs text-slate-500">The SEO command center for teams that ship every week. Discover, prioritize, execute, measure.</p>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Product</p>
                <ul class="mt-3 space-y-2.5">
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('features') }}">Features</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('guide') }}">Guide</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('pricing') }}">Pricing</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('website-revamp') }}">Website revamp</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('wordpress-plugin') }}">WordPress plugin</a></li>
                </ul>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Company</p>
                <ul class="mt-3 space-y-2.5">
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('landing') }}#faq">FAQ</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('contact') }}">Contact</a></li>
                </ul>
            </div>
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">Legal</p>
                <ul class="mt-3 space-y-2.5">
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('terms-conditions') }}">Terms</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('privacy-policy') }}">Privacy</a></li>
                    <li><a class="text-slate-600 transition hover:text-slate-900" href="{{ route('refund-policy') }}">Refunds</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t border-slate-200">
            <div class="mx-auto flex max-w-6xl flex-col gap-3 px-6 py-6 text-xs text-slate-500 sm:flex-row sm:items-center sm:justify-between lg:px-8">
                <p>&copy; {{ date('Y') }} Serfix. All rights reserved.</p>
                <p>Built for SEO teams that ship weekly.</p>
            </div>
        </div>
    </footer>
</body>
</html>
