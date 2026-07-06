{{-- Site Audit export — DomPDF-rendered, branded via $branding (same model as
     growth-report-pdf.blade.php). Table/block layout only (no flex/grid) so
     DomPDF lays it out reliably. Receives $website, $branding, $audit from
     CrawlReportService::auditExport(). --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 32px 36px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; line-height: 1.45; }
        h1 { font-size: 19px; margin: 0 0 4px; color: {{ $branding->accent_color ?? '#F26419' }}; }
        .meta { color: #64748b; font-size: 11px; margin: 0 0 14px; }

        .brand-header { border-bottom: 2px solid {{ $branding->accent_color ?? '#F26419' }}; padding-bottom: 8px; margin-bottom: 14px; }
        .brand-header img { max-height: 44px; max-width: 200px; }
        .brand-header .brand-name { font-size: 14px; font-weight: 700; color: #475569; }

        table.kpi-grid { width: 100%; border-collapse: separate; border-spacing: 4px; margin: 0 0 16px; }
        table.kpi-grid td { background: #f8fafc; padding: 10px 6px; text-align: center; border-radius: 4px; vertical-align: top; }
        .kpi-value { font-size: 20px; font-weight: 700; color: #0f172a; display: block; }
        .kpi-label { font-size: 8px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.04em; display: block; margin-top: 3px; }
        .grade-A, .grade-B { color: #16a34a; }
        .grade-C { color: #d97706; }
        .grade-D, .grade-F { color: #dc2626; }

        .priority-box { border: 1.5px solid #FBD1BB; background: #FEF0E7; border-radius: 6px; padding: 12px 14px; margin: 0 0 18px; }
        .priority-box .section-title { color: #C44E0E; margin-bottom: 6px; }
        .priority-box p.lede { color: #C44E0E; font-size: 10px; margin: 0 0 8px; }
        .priority-item { padding: 5px 0; border-top: 1px solid #FBD1BB; }
        .priority-item:first-child { border-top: none; }
        .priority-rank { display: inline-block; width: 16px; font-weight: 700; color: #C44E0E; }

        .section-divider { margin: 22px 0 10px; padding-bottom: 4px; border-bottom: 2px solid #e2e8f0; }
        .section-divider .section-title { font-size: 15px; margin: 0; }
        .section-divider p.lede { color: #64748b; font-size: 10px; margin: 2px 0 0; }
        .sec-errors .section-title { color: #dc2626; }
        .sec-warnings .section-title { color: #d97706; }
        .sec-notices .section-title { color: #2563eb; }

        .section-title { font-size: 13px; font-weight: 700; margin: 0 0 6px; color: #0f172a; }

        .issue-block { padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .issue-head { font-size: 12px; font-weight: 700; color: #0f172a; }
        .issue-count { display: inline-block; padding: 1px 7px; border-radius: 9px; font-size: 10px; font-weight: 700; color: #fff; margin-left: 6px; }
        .count-errors { background: #dc2626; }
        .count-warnings { background: #d97706; }
        .count-notices { background: #2563eb; }
        .new-badge { display: inline-block; padding: 1px 6px; border-radius: 9px; font-size: 9px; font-weight: 700; color: #166534; background: #dcfce7; margin-left: 5px; }
        .gsc-badge { display: inline-block; padding: 1px 6px; border-radius: 9px; font-size: 9px; font-weight: 700; color: #92400e; background: #fef3c7; margin-left: 5px; }
        .issue-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #94a3b8; margin: 6px 0 2px; }
        .issue-body { font-size: 10.5px; color: #334155; margin: 0 0 4px; }
        .issue-fix { font-size: 10.5px; color: #0f172a; margin: 0 0 4px; }
        .issue-fix strong { color: {{ $branding->accent_color ?? '#F26419' }}; }
        .url-list { font-size: 9.5px; color: #64748b; margin: 2px 0 0; padding-left: 14px; }
        .url-list li { margin-bottom: 1px; word-break: break-all; }
        .gsc-note { font-size: 9.5px; color: #92400e; background: #fffbeb; border-left: 3px solid #f59e0b; padding: 4px 8px; margin: 4px 0; }

        .empty-state { color: #16a34a; font-size: 11px; padding: 10px 0; }

        .footer { margin-top: 22px; padding-top: 10px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 10px; line-height: 1.6; }
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

<h1>Site Audit Report</h1>
<p class="meta">
    <strong>{{ $website->domain }}</strong>
    @if ($audit['crawled_at'])
        &mdash; crawled {{ \Illuminate\Support\Carbon::parse($audit['crawled_at'])->format('F j, Y') }}
    @endif
    &mdash; generated {{ now()->format('F j, Y') }}
</p>

{{-- Executive summary: the 4 numbers a non-technical client actually reads. --}}
<table class="kpi-grid">
    <tr>
        <td style="width:25%;">
            <span class="kpi-value {{ $audit['health_grade'] ? 'grade-'.$audit['health_grade'] : '' }}">
                {{ $audit['health_score'] ?? '—' }}@if ($audit['health_grade'])&nbsp;({{ $audit['health_grade'] }})@endif
            </span>
            <span class="kpi-label">Health score</span>
        </td>
        <td style="width:25%;">
            <span class="kpi-value">{{ $audit['pages_crawled'] !== null ? number_format($audit['pages_crawled']) : '—' }}</span>
            <span class="kpi-label">Pages crawled</span>
        </td>
        <td style="width:25%;">
            <span class="kpi-value" style="color:#dc2626;">{{ array_sum(array_column($audit['errors'], 'count')) }}</span>
            <span class="kpi-label">Errors</span>
        </td>
        <td style="width:25%;">
            <span class="kpi-value" style="color:#d97706;">{{ array_sum(array_column($audit['warnings'], 'count')) }}</span>
            <span class="kpi-label">Warnings</span>
        </td>
    </tr>
</table>

{{-- "Start here": ranked shortlist so the reader isn't left guessing which of
     ~30 issue types to tackle first — every other audit tool just dumps a flat
     list and leaves prioritization to the reader. --}}
@if ($audit['priority'] !== [])
    <div class="priority-box">
        <p class="section-title">Start here — highest-impact fixes</p>
        <p class="lede">Ranked by severity and how many pages each one hits. Fixing these first gives the best return before working through the full list below.</p>
        @foreach ($audit['priority'] as $i => $item)
            <div class="priority-item">
                <span class="priority-rank">{{ $i + 1 }}.</span>
                <strong>{{ $item['label'] }}</strong> — {{ $item['count'] }} page{{ $item['count'] === 1 ? '' : 's' }}
                @if ($item['new_count'] > 0)<span class="new-badge">+{{ $item['new_count'] }} new</span>@endif
                <br><span style="font-size:10px;color:#C44E0E;margin-left:20px;">{{ $item['fix'] }}</span>
            </div>
        @endforeach
    </div>
@endif

@include('pdf.partials.site-audit-section', ['key' => 'errors', 'heading' => 'Errors', 'lede' => 'Fix these now — they actively block indexing, break the user experience, or waste crawl budget.', 'cls' => 'count-errors', 'items' => $audit['errors']])
@include('pdf.partials.site-audit-section', ['key' => 'warnings', 'heading' => 'Warnings', 'lede' => 'Fix these soon — they hold the site back from ranking as well as it could.', 'cls' => 'count-warnings', 'items' => $audit['warnings']])
@include('pdf.partials.site-audit-section', ['key' => 'notices', 'heading' => 'Notices', 'lede' => 'Good to address when convenient — lower-impact polish.', 'cls' => 'count-notices', 'items' => $audit['notices']])

<div class="footer">
    @if ($branding->footer_text)<p>{{ $branding->footer_text }}</p>@endif
    @if ($branding->contact_email)<p><strong>Email:</strong> {{ $branding->contact_email }}</p>@endif
    @if ($branding->contact_phone)<p><strong>Phone:</strong> {{ $branding->contact_phone }}</p>@endif
    @if ($branding->contact_address)<p>{{ $branding->contact_address }}</p>@endif
    <p style="margin-top:6px;">{{ $branding->company_name }} &mdash; generated {{ now()->format('M d, Y') }}</p>
</div>
</body>
</html>
