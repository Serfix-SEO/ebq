{{--
    Standalone shell for HTTP error pages. Deliberately does NOT use
    x-layouts.app — an error page (especially 500) must render even when the
    app shell itself is failing, so this is self-contained HTML that pulls only
    the compiled CSS. Nodus is an inline SVG, so the mascot shows even if the
    stylesheet fails to load; a tiny inline body style keeps it centered and
    legible in that worst case.

    Props: code, title, message, state (Nodus mood), home (url), homeLabel.
--}}
@props([
    'code' => 500,
    'title' => 'Something went wrong',
    'message' => null,
    'state' => 'confused',
    'home' => null,
    'homeLabel' => null,
])
@php
    $rtl = app()->getLocale() === 'ar';
    $homeUrl = $home ?? (auth()->check() ? url('/dashboard') : url('/'));
    $homeText = $homeLabel ?? (auth()->check() ? __('Back to dashboard') : __('Back to home'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $code }} · {{ $title }} · Serfix</title>
    @include('partials.favicon-links')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @if (! app()->environment('testing'))
        @vite(['resources/css/app.css'])
    @endif
    {{-- Worst-case fallback if the stylesheet never loads --}}
    <style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:Inter,system-ui,sans-serif}</style>
</head>
<body class="h-full bg-white text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100 {{ $rtl ? 'font-arabic' : '' }}">
    <main class="flex min-h-full w-full flex-col items-center justify-center px-6 py-16 text-center">
        <x-nodus :state="$state" :size="132" class="text-slate-400 dark:text-slate-500"/>

        <p class="mt-6 text-sm font-bold uppercase tracking-[0.2em] text-orange-600 dark:text-orange-400">{{ __('Error') }} {{ $code }}</p>
        <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900 dark:text-slate-50 sm:text-4xl">{{ $title }}</h1>

        @if ($message)
            <p class="mx-auto mt-3 max-w-md text-[15px] leading-7 text-slate-500 dark:text-slate-400">{{ $message }}</p>
        @endif

        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ $homeUrl }}" class="inline-flex items-center gap-2 rounded-xl bg-orange-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-orange-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-950">
                {{ $homeText }}
            </a>
            <button type="button" onclick="history.back()" class="inline-flex items-center gap-2 rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800/60">
                {{ __('Go back') }}
            </button>
        </div>

        {{ $slot }}

        <p class="mt-12 text-xs text-slate-400 dark:text-slate-600">&copy; {{ date('Y') }} Serfix</p>
    </main>
</body>
</html>
