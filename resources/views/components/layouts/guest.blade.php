<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
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
<body class="h-full bg-white text-slate-900 antialiased">
    <div class="flex min-h-full">
        {{-- Brand panel --}}
        <div class="hidden w-1/2 flex-col justify-between border-r border-slate-200 bg-slate-50 p-12 lg:flex">
            <a href="{{ route('landing') }}" class="inline-flex items-center">
                <img src="{{ asset('serfix-logo.png') }}" alt="Serfix" width="112" height="40" class="h-10 w-auto object-contain">
            </a>

            <div class="max-w-md">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">SEO command center</p>
                <h2 class="mt-4 text-3xl font-semibold tracking-tight text-slate-900">
                    The clearest way to run SEO across every site you own.
                </h2>
                <p class="mt-4 text-[15px] leading-7 text-slate-600">
                    Connect Search Console and Analytics. Get prioritized actions, ranking trends, audits, and reporting in one workspace.
                </p>

                <dl class="mt-10 grid grid-cols-3 gap-4 text-center">
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Sources</dt>
                        <dd class="mt-1.5 text-base font-semibold text-slate-900">GA4 · GSC</dd>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Insights</dt>
                        <dd class="mt-1.5 text-base font-semibold text-slate-900">6 boards</dd>
                    </div>
                    <div class="rounded-xl border border-slate-200 bg-white p-4">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Setup</dt>
                        <dd class="mt-1.5 text-base font-semibold text-slate-900">&lt; 10 min</dd>
                    </div>
                </dl>
            </div>

            <p class="text-xs text-slate-500">&copy; {{ date('Y') }} Serfix. All rights reserved.</p>
        </div>

        {{-- Form panel --}}
        <div class="flex w-full flex-col items-center justify-center bg-white p-6 lg:w-1/2 lg:p-12">
            <div class="mb-8 lg:hidden">
                <a href="{{ route('landing') }}" class="inline-flex items-center">
                    <img src="{{ asset('serfix-logo.png') }}" alt="Serfix" width="101" height="36" class="h-9 w-auto object-contain">
                </a>
            </div>
            <div class="w-full max-w-md">
                {{ $slot }}
            </div>
        </div>
    </div>
</body>
</html>
