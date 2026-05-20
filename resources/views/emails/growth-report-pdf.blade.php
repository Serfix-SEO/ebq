{{-- Print-friendly companion to growth-report.blade.php — renders via DomPDF.
     Layout uses tables only (no flex / no transforms) so DomPDF can lay
     it out reliably. Receives the same $branding / $report / $insights
     payload as the email view. --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 32px 36px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; line-height: 1.45; }
        h1 { font-size: 18px; margin: 0 0 4px; color: {{ $branding->accent_color ?? '#4f46e5' }}; }
        .meta { color: #64748b; font-size: 11px; margin: 0 0 4px; }
        .compare-line { color: #94a3b8; font-style: italic; margin: 0 0 12px; font-size: 10px; }

        .brand-header { border-bottom: 2px solid {{ $branding->accent_color ?? '#4f46e5' }}; padding-bottom: 8px; margin-bottom: 14px; }
        .brand-header img { max-height: 44px; max-width: 200px; }
        .brand-header .brand-name { font-size: 14px; font-weight: 700; color: #475569; }

        .section-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #fff; margin: 14px 0 8px; }
        .badge-analytics { background: #3b82f6; }
        .badge-search { background: #8b5cf6; }
        .badge-backlinks { background: #10b981; }

        .section-title { font-size: 13px; font-weight: 700; margin: 0 0 8px; color: #0f172a; }

        table.kpi-grid { width: 100%; border-collapse: separate; border-spacing: 3px; margin: 0 0 12px; }
        table.kpi-grid td { background: #f8fafc; padding: 6px 5px; text-align: center; border-radius: 4px; vertical-align: top; }
        .kpi-value { font-size: 14px; font-weight: 700; color: #0f172a; display: block; }
        .kpi-label { font-size: 8px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-top: 2px; }
        .kpi-change { font-size: 9px; font-weight: 600; display: block; margin-top: 2px; }

        table.data-table { width: 100%; border-collapse: collapse; font-size: 10px; margin: 0 0 10px; }
        table.data-table th { text-align: left; padding: 5px 8px; border-bottom: 1.5px solid #e2e8f0; color: #64748b; font-size: 9px; text-transform: uppercase; }
        table.data-table th.right, table.data-table td.right { text-align: right; }
        table.data-table td { padding: 6px 8px; border-bottom: 1px solid #f1f5f9; }

        .change-up-good, .change-down-good { color: #16a34a; }
        .change-up-bad, .change-down-bad { color: #dc2626; }
        .change-flat { color: #94a3b8; }

        .footer { margin-top: 18px; padding-top: 10px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 10px; line-height: 1.6; }
        .footer strong { color: #475569; }
    </style>
</head>
<body>
<div class="brand-header">
    @if ($branding->logoUrl())
        <img src="{{ $branding->logoUrl() }}" alt="{{ $branding->company_name }}">
    @else
        <div class="brand-name">{{ $branding->company_name }}</div>
    @endif
</div>

<h1>{{ $branding->company_name }} {{ ucfirst($reportType) }} Report</h1>
<p class="meta"><strong>{{ $website->domain }}</strong> &mdash;
    @if ($startDate === $endDate)
        {{ format_user_date($startDate, 'l, F j, Y', $user) }}
    @else
        {{ format_user_date($startDate, 'M j', $user) }} &ndash; {{ format_user_date($endDate, 'M j, Y', $user) }}
    @endif
</p>
@if (! empty($report['period']['previous_label']))
    <p class="compare-line">Compared to {{ $report['period']['previous_label'] }}
        ({{ format_user_date($report['period']['prev_start'], 'M j', $user) }} &ndash; {{ format_user_date($report['period']['prev_end'], 'M j, Y', $user) }})</p>
@endif

{{-- KPI summary: small subset of the full HTML email's KPI grid, focused
     on the headline numbers the client cares about most. The HTML email
     keeps every section; the PDF keeps the executive summary. --}}
@php
    $kpis = $report['analytics'] ?? [];
@endphp
@if (! empty($kpis))
    <p class="section-badge badge-analytics">Analytics</p>
    <table class="kpi-grid">
        <tr>
            @foreach (['sessions', 'users', 'pageviews', 'events'] as $key)
                @if (isset($kpis[$key]))
                    <td>
                        <span class="kpi-value">{{ $kpis[$key]['current'] ?? '—' }}</span>
                        <span class="kpi-label">{{ ucfirst($key) }}</span>
                        @if (isset($kpis[$key]['change_pct']))
                            <span class="kpi-change">{{ $kpis[$key]['change_pct'] }}</span>
                        @endif
                    </td>
                @endif
            @endforeach
        </tr>
    </table>
@endif

@php
    $search = $report['search_console'] ?? [];
@endphp
@if (! empty($search))
    <p class="section-badge badge-search">Search Console</p>
    <table class="kpi-grid">
        <tr>
            @foreach (['clicks', 'impressions', 'ctr', 'position'] as $key)
                @if (isset($search[$key]))
                    <td>
                        <span class="kpi-value">{{ $search[$key]['current'] ?? '—' }}</span>
                        <span class="kpi-label">{{ ucfirst($key) }}</span>
                        @if (isset($search[$key]['change_pct']))
                            <span class="kpi-change">{{ $search[$key]['change_pct'] }}</span>
                        @endif
                    </td>
                @endif
            @endforeach
        </tr>
    </table>
@endif

{{-- Insights: just the cannibalization + striking-distance lists since
     they're the most actionable pieces from the daily report. --}}
@if (! empty($insights['cannibalization']))
    <p class="section-title">Cannibalization (top 5)</p>
    <table class="data-table">
        <thead><tr><th>Query</th><th class="right">Page A</th><th class="right">Page B</th></tr></thead>
        <tbody>
        @foreach ($insights['cannibalization'] as $row)
            <tr>
                <td>{{ $row['query'] ?? '—' }}</td>
                <td class="right">{{ $row['page_a'] ?? '—' }}</td>
                <td class="right">{{ $row['page_b'] ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

@if (! empty($insights['striking_distance']))
    <p class="section-title">Striking-distance opportunities (top 5)</p>
    <table class="data-table">
        <thead><tr><th>Query</th><th class="right">Position</th><th class="right">Impressions</th></tr></thead>
        <tbody>
        @foreach ($insights['striking_distance'] as $row)
            <tr>
                <td>{{ $row['query'] ?? '—' }}</td>
                <td class="right">{{ $row['position'] ?? '—' }}</td>
                <td class="right">{{ $row['impressions'] ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<div class="footer">
    @if ($branding->footer_text)<p>{{ $branding->footer_text }}</p>@endif
    @if ($branding->contact_email)<p><strong>Email:</strong> {{ $branding->contact_email }}</p>@endif
    @if ($branding->contact_phone)<p><strong>Phone:</strong> {{ $branding->contact_phone }}</p>@endif
    @if ($branding->contact_address)<p>{{ $branding->contact_address }}</p>@endif
    <p style="margin-top:6px;">{{ $branding->company_name }} &mdash; generated {{ format_user_now('M d, Y', $user) }}</p>
</div>
</body>
</html>
