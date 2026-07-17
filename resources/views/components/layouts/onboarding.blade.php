<!DOCTYPE html>
{{-- Full-width onboarding shell (2026-07-17): unlike layouts.guest there is no
     half-screen brand panel — onboarding gets the whole viewport, a slim
     logo topbar, and a soft gradient backdrop. --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    @include('partials.google-analytics')
    @include('partials.clarity')

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Serfix' }}</title>
    @include('partials.favicon-links')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @if (! app()->environment('testing'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="h-full bg-gradient-to-b from-slate-50 to-white text-slate-900 antialiased {{ app()->getLocale() === 'ar' ? 'font-arabic' : '' }}">
    @include('partials.locale-picker')

    {{-- Slim topbar: logo + logout --}}
    <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/80 backdrop-blur">
        <div class="mx-auto flex h-16 max-w-5xl items-center justify-between px-4 sm:px-6">
            <a href="{{ route('landing') }}" class="inline-flex items-center">
                <img src="{{ asset('serfix-logo.png') }}" alt="Serfix" width="112" height="40" class="h-9 w-auto object-contain">
            </a>
            @auth
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                        class="inline-flex h-9 items-center whitespace-nowrap rounded-lg border border-slate-200 px-3 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900">
                        {{ __('Log out') }}
                    </button>
                </form>
            @endauth
        </div>
    </header>

    <main class="mx-auto w-full max-w-5xl px-4 py-10 sm:px-6">
        {{ $slot }}
    </main>
</body>
</html>
