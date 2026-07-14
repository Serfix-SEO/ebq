{{-- Shared "domain report" status block — used by the standalone report page
     (reports/view.blade.php) AND the post-signup website overview hub's Site
     Explorer tab. Expects the same variables ReportViewController::resolve()
     returns: $unavailable, $noData, $pending, $payload, $branding,
     $generatedAt, $website (optional), $domain. --}}
@if (! empty($unavailable))
    <div class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Backlink reports are temporarily unavailable. Please try again later.</p>
    </div>
@elseif (! empty($noData))
    <div class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
        <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">No backlink data is available for this domain yet.</p>
        <a href="{{ route('site-explorer') }}" class="mt-4 inline-block rounded-lg bg-orange-600 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-700">Try another site</a>
    </div>
@elseif (! empty($pending))
    @php $attempt = (int) request('_t', 0); @endphp
    <div class="rounded-2xl bg-white p-14 text-center shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <div class="mx-auto mb-4 h-9 w-9 animate-spin rounded-full border-[3px] border-slate-200 border-t-orange-500 dark:border-slate-700"></div>
        <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ $domain }}</p>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Building your backlink report…</p>
        @if ($attempt < 15)
            <script>setTimeout(function () { location.replace('{{ ($reloadUrl ?? route('report.view', ['url' => $domain])) }}{{ str_contains($reloadUrl ?? route('report.view', ['url' => $domain]), '?') ? '&' : '?' }}_t={{ $attempt + 1 }}'); }, 6000);</script>
        @else
            <p class="mt-3 text-xs text-slate-400 dark:text-slate-500">This is taking longer than usual. <a href="{{ $reloadUrl ?? route('report.view', ['url' => $domain]) }}" class="text-orange-600 hover:underline">Refresh</a></p>
        @endif
    </div>
@else
    @include('reports.web-body', [
        'payload' => $payload,
        'branding' => $branding,
        'generatedAt' => $generatedAt ?? null,
        'downloadUrl' => ! empty($website ?? null) ? route('report.download') : null,
    ])
@endif
