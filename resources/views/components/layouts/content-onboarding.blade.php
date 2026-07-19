<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    @include('partials.google-analytics')
    @include('partials.clarity')

    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? __('Get started with Content Autopilot') }} — Serfix</title>
    @include('partials.favicon-links')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @if (! app()->environment('testing'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100 {{ app()->getLocale() === 'ar' ? 'font-arabic' : '' }}">
    {{-- Slim top bar --}}
    <header class="border-b border-slate-200 bg-white/80 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80">
        <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-3 sm:px-6">
            <a href="{{ route('content.landing') }}" class="inline-flex items-center gap-2">
                <img src="{{ asset('serfix-logo.png') }}" alt="Serfix" width="101" height="36" class="h-8 w-auto object-contain">
                <span class="rounded-full bg-orange-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-orange-700 dark:bg-orange-950 dark:text-orange-300">{{ __('Content AI') }}</span>
            </a>
            <div class="flex items-center gap-4 text-sm">
                @include('partials.locale-picker')
                <a href="{{ route('login') }}" class="font-semibold text-slate-600 hover:text-slate-900 dark:text-slate-300 dark:hover:text-white">{{ __('Log in') }}</a>
            </div>
        </div>
    </header>

    <main class="px-4 py-8 sm:py-12">
        {{ $slot }}
    </main>

    <footer class="mx-auto max-w-6xl px-4 pb-10 pt-4 text-center text-xs text-slate-400">
        &copy; {{ date('Y') }} Serfix. {{ __('All rights reserved.') }}
    </footer>
</body>
</html>
