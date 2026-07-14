<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ ($branding->company_name ?? 'Serfix') }} — Backlink report{{ isset($website) ? ' · '.$website->domain : (isset($payload) ? ' · '.$payload['domain'] : '') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; }</style>
</head>
<body class="min-h-full bg-slate-50 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between px-6 py-4 lg:px-8">
            <div class="flex items-center gap-3">
                @if ($branding && method_exists($branding, 'logoUrl') && $branding->logoUrl())
                    <img src="{{ $branding->logoUrl() }}" alt="{{ $branding->company_name }}" class="h-8 w-auto object-contain">
                @else
                    <span class="text-lg font-bold" style="color: {{ $branding->accent_color ?? '#F26419' }}">{{ $branding->company_name ?? 'Serfix' }}</span>
                @endif
            </div>
            @if (empty($pending))
                <button onclick="window.print()" class="rounded-lg border border-slate-300 px-3.5 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50">Print / save PDF</button>
            @endif
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-6 py-10 lg:px-8">
        @if (! empty($pending))
            <div class="rounded-2xl bg-white p-14 text-center shadow-sm ring-1 ring-slate-200">
                <p class="text-lg font-semibold text-slate-900">{{ $website->domain ?? '' }}</p>
                <p class="mt-2 text-sm text-slate-500">This report is being prepared. Check back in a few minutes.</p>
            </div>
        @else
            @include('reports.web-body', ['payload' => $payload, 'branding' => $branding, 'generatedAt' => $generatedAt ?? null])
        @endif
    </main>
</body>
</html>
