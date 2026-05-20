<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1e293b; line-height: 1.6; margin: 0; padding: 0; background: #f1f5f9; }
        .container { max-width: 680px; margin: 0 auto; padding: 32px 16px; }
        .card { background: #ffffff; border-radius: 12px; padding: 32px; margin-bottom: 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; font-weight: 700; }
        .meta { color: #64748b; margin: 0 0 4px; font-size: 14px; }
        .compare-line { color: #94a3b8; margin: 0 0 8px; font-size: 13px; font-style: italic; }
        .freshness { color: #64748b; margin: 0 0 24px; font-size: 12px; padding: 8px 12px; background: #f8fafc; border-left: 3px solid #cbd5e1; border-radius: 4px; }
        .greeting { color: #475569; margin: 0 0 28px; font-size: 14px; }

        .section-badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #fff; margin-bottom: 14px; }
        .badge-analytics { background: #3b82f6; }
        .badge-search { background: #8b5cf6; }
        .badge-backlinks { background: #10b981; }

        .section-title { font-size: 16px; font-weight: 700; margin: 0 0 16px; color: #0f172a; }
        .section-divider { border: none; border-top: 1px solid #e2e8f0; margin: 28px 0; }

        .kpi-grid { width: 100%; border-collapse: separate; border-spacing: 4px; margin: 0 -4px 20px; table-layout: fixed; }
        .kpi-grid td { padding: 10px 6px; text-align: center; vertical-align: top; background: #f8fafc; border-radius: 6px; }
        .kpi-value { font-size: 18px; font-weight: 700; color: #0f172a; display: block; word-break: break-word; line-height: 1.2; }
        .kpi-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; display: block; margin-top: 2px; }
        .kpi-change { font-size: 10px; font-weight: 600; display: block; margin-top: 3px; }
        .kpi-prev { font-size: 9px; color: #94a3b8; display: block; margin-top: 1px; }

        .change-up-good { color: #16a34a; }
        .change-down-good { color: #16a34a; }
        .change-up-bad { color: #dc2626; }
        .change-down-bad { color: #dc2626; }
        .change-flat { color: #94a3b8; }

        .data-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 8px; }
        .data-table th { text-align: left; padding: 8px 10px; border-bottom: 2px solid #e2e8f0; color: #64748b; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; }
        .data-table th.right { text-align: right; }
        .data-table td { padding: 10px; border-bottom: 1px solid #f1f5f9; }
        .data-table td.right { text-align: right; }
        .data-table tr:last-child td { border-bottom: none; }

        .sub-heading { font-size: 13px; font-weight: 600; color: #334155; margin: 0 0 10px; text-transform: uppercase; letter-spacing: 0.03em; }
        .empty-note { color: #94a3b8; font-size: 13px; margin: 0 0 16px; }
        .mini-grid { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 -8px 16px; table-layout: fixed; }
        .mini-grid td { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; vertical-align: top; }
        .mini-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin: 0; }
        .mini-value { font-size: 15px; font-weight: 700; color: #0f172a; margin: 4px 0 2px; }
        .mini-note { font-size: 11px; color: #64748b; margin: 0; }
        .insight-cols { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 0 -8px 14px; table-layout: fixed; }
        .insight-cols td { vertical-align: top; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; }
        .insight-list { margin: 0; padding: 0; list-style: none; }
        .insight-list li { display: block; border: 1px solid #e2e8f0; border-radius: 6px; padding: 7px 8px; margin-bottom: 6px; font-size: 12px; color: #334155; }
        .insight-list li:last-child { margin-bottom: 0; }
        .insight-row { display: table; width: 100%; table-layout: fixed; }
        .insight-row .label { display: table-cell; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding-right: 10px; }
        .insight-row .value { display: table-cell; width: 72px; text-align: right; font-weight: 700; white-space: nowrap; }
        .value-up { color: #16a34a; }
        .value-down { color: #dc2626; }

        .btn { display: inline-block; background: {{ $branding->accent_color ?? '#4f46e5' }}; color: #ffffff !important; text-decoration: none; border-radius: 8px; padding: 12px 28px; font-size: 14px; font-weight: 600; }
        .footer { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 24px; }
        .brand-header { text-align: left; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0; margin-bottom: 20px; }
        .brand-header img { max-height: 48px; max-width: 240px; display: block; }
        .brand-header .brand-name { font-size: 14px; font-weight: 700; color: #475569; margin: 0; }
        .contact-block { font-size: 11px; color: #64748b; line-height: 1.6; margin-top: 12px; }
        .contact-block strong { color: #475569; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        @if ($branding->logoUrl())
            <div class="brand-header"><img src="{{ $branding->logoUrl() }}" alt="{{ $branding->company_name }}"></div>
        @elseif (! $branding->isDefault())
            <div class="brand-header"><p class="brand-name">{{ $branding->company_name }}</p></div>
        @endif
        <h1>{{ $branding->company_name }} {{ ucfirst($reportType) }} Report</h1>
        <p class="meta">
            <strong>{{ $website->domain }}</strong> &mdash;
            @if ($startDate === $endDate)
                {{ format_user_date($startDate, 'l, F j, Y', $user) }}
            @else
                {{ format_user_date($startDate, 'M j', $user) }} &ndash; {{ format_user_date($endDate, 'M j, Y', $user) }}
            @endif
        </p>
        <p class="compare-line">
            Compared to {{ $report['period']['previous_label'] }}
            ({{ format_user_date($report['period']['prev_start'], 'M j', $user) }} &ndash; {{ format_user_date($report['period']['prev_end'], 'M j, Y', $user) }})
        </p>
        <p class="freshness">
            <strong>Data through {{ format_user_date($endDate, 'M j, Y', $user) }}.</strong>
            @if ($startDate === $endDate)
                Google Search Console finalises numbers 1&ndash;3 days after the day ends, so this is the most recent fully&#8209;synced day for a fair comparison.
            @else
                Google Search Console finalises numbers 1&ndash;3 days after each day ends, so the period ends on the most recent fully&#8209;synced day for a fair comparison.
            @endif
        </p>
        <p class="greeting">Hello {{ $user->name }}, here is the performance summary for your website.</p>

        {{-- ==================== GOOGLE ANALYTICS ==================== --}}
        <span class="section-badge badge-analytics">Google Analytics</span>
        <h2 class="section-title">Website Traffic</h2>

        <table class="kpi-grid" role="presentation">
            <tr>
                <td>
                    <span class="kpi-value">{{ number_format($report['analytics']['users']['current']) }}</span>
                    <span class="kpi-label">Users</span>
                    @include('emails.partials.change-badge', ['metric' => $report['analytics']['users']])
                    <span class="kpi-prev">was {{ number_format($report['analytics']['users']['previous']) }}</span>
                </td>
                <td>
                    <span class="kpi-value">{{ number_format($report['analytics']['sessions']['current']) }}</span>
                    <span class="kpi-label">Sessions</span>
                    @include('emails.partials.change-badge', ['metric' => $report['analytics']['sessions']])
                    <span class="kpi-prev">was {{ number_format($report['analytics']['sessions']['previous']) }}</span>
                </td>
                <td>
                    <span class="kpi-value">{{ $report['analytics']['bounce_rate']['current'] }}%</span>
                    <span class="kpi-label">Bounce Rate</span>
                    @include('emails.partials.change-badge', ['metric' => $report['analytics']['bounce_rate'], 'suffix' => 'pp'])
                    <span class="kpi-prev">was {{ $report['analytics']['bounce_rate']['previous'] }}%</span>
                </td>
            </tr>
        </table>

        @if (count($report['analytics']['top_sources']) > 0)
            <p class="sub-heading">Top Traffic Sources</p>
            <table class="data-table" role="presentation">
                <thead>
                    <tr>
                        <th>Source</th>
                        <th class="right">Users</th>
                        <th class="right">Prev</th>
                        <th class="right">Change</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['analytics']['top_sources'] as $source)
                        <tr>
                            <td>{{ $source['source'] }}</td>
                            <td class="right">{{ number_format($source['users']) }}</td>
                            <td class="right" style="color:#94a3b8">{{ number_format($source['prev_users']) }}</td>
                            <td class="right">@include('emails.partials.change-inline', ['metric' => $source['change']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">No analytics data available for this period.</p>
        @endif

        <table class="mini-grid" role="presentation">
            <tr>
                <td>
                    <p class="mini-label">Engagement Insight</p>
                    <p class="mini-value">{{ $report['analytics']['sessions_per_user']['current'] ?? 0 }} sessions/user</p>
                    <p class="mini-note">was {{ $report['analytics']['sessions_per_user']['previous'] ?? 0 }} in {{ $report['period']['previous_label'] }}</p>
                </td>
                <td>
                    <p class="mini-label">Source Concentration</p>
                    <p class="mini-value">{{ $report['analytics']['source_concentration_top3'] ?? 0 }}% from top 3 sources</p>
                    <p class="mini-note">Higher values can indicate channel concentration risk.</p>
                </td>
            </tr>
        </table>

        @if (count($report['analytics']['top_source_gainers'] ?? []) > 0 || count($report['analytics']['top_source_losers'] ?? []) > 0)
            <table class="insight-cols" role="presentation">
                <tr>
                    <td>
                        <p class="sub-heading" style="margin-bottom:8px;color:#16a34a;">Source Gainers</p>
                        <ul class="insight-list">
                            @foreach (($report['analytics']['top_source_gainers'] ?? []) as $item)
                                <li>
                                    <span class="insight-row">
                                        <span class="label">{{ $item['source'] }}</span>
                                        <span class="value value-up">+{{ number_format($item['change']) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </td>
                    <td>
                        <p class="sub-heading" style="margin-bottom:8px;color:#dc2626;">Source Losers</p>
                        <ul class="insight-list">
                            @foreach (($report['analytics']['top_source_losers'] ?? []) as $item)
                                <li>
                                    <span class="insight-row">
                                        <span class="label">{{ $item['source'] }}</span>
                                        <span class="value value-down">{{ number_format($item['change']) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
            </table>
        @endif

        <hr class="section-divider">

        {{-- ==================== GOOGLE SEARCH CONSOLE ==================== --}}
        <span class="section-badge badge-search">Google Search Console</span>
        <h2 class="section-title">Search Performance</h2>

        <table class="kpi-grid" role="presentation">
            <tr>
                <td>
                    <span class="kpi-value">{{ number_format($report['search_console']['clicks']['current']) }}</span>
                    <span class="kpi-label">Clicks</span>
                    @include('emails.partials.change-badge', ['metric' => $report['search_console']['clicks']])
                    <span class="kpi-prev">was {{ number_format($report['search_console']['clicks']['previous']) }}</span>
                </td>
                <td>
                    <span class="kpi-value">{{ number_format($report['search_console']['impressions']['current']) }}</span>
                    <span class="kpi-label">Impressions</span>
                    @include('emails.partials.change-badge', ['metric' => $report['search_console']['impressions']])
                    <span class="kpi-prev">was {{ number_format($report['search_console']['impressions']['previous']) }}</span>
                </td>
                <td>
                    <span class="kpi-value">{{ $report['search_console']['position']['current'] }}</span>
                    <span class="kpi-label">Avg Position</span>
                    @include('emails.partials.change-badge', ['metric' => $report['search_console']['position']])
                    <span class="kpi-prev">was {{ $report['search_console']['position']['previous'] }}</span>
                </td>
                <td>
                    <span class="kpi-value">{{ $report['search_console']['ctr']['current'] }}%</span>
                    <span class="kpi-label">Avg CTR</span>
                    @include('emails.partials.change-badge', ['metric' => $report['search_console']['ctr'], 'suffix' => 'pp'])
                    <span class="kpi-prev">was {{ $report['search_console']['ctr']['previous'] }}%</span>
                </td>
            </tr>
        </table>

        @if (! empty($report['ppc_equivalent']))
            <p style="margin: 8px 0 0; font-size: 13px; color: #4f46e5;">
                Your organic traffic is worth approximately
                <strong>${{ number_format($report['ppc_equivalent']['value'], 0) }}/month</strong>
                in PPC equivalent
                <span style="color: #94a3b8; font-size: 11px;">(based on {{ number_format($report['ppc_equivalent']['keywords']) }} priced queries · Google Ads CPC rates)</span>.
            </p>
        @endif

        @if (count($report['search_console']['top_queries']) > 0)
            <p class="sub-heading">Top Search Queries</p>
            <table class="data-table" role="presentation">
                <thead>
                    <tr>
                        <th>Query</th>
                        <th class="right">Clicks</th>
                        <th class="right">Prev</th>
                        <th class="right">Pos</th>
                        <th class="right">Change</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['search_console']['top_queries'] as $q)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::limit($q['query'], 40) }}</td>
                            <td class="right">{{ number_format($q['clicks']) }}</td>
                            <td class="right" style="color:#94a3b8">{{ number_format($q['prev_clicks']) }}</td>
                            <td class="right">{{ $q['position'] }}</td>
                            <td class="right">@include('emails.partials.change-inline', ['metric' => $q['change']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (count($report['search_console']['top_pages']) > 0)
            <p class="sub-heading">Top Pages</p>
            <table class="data-table" role="presentation">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th class="right">Clicks</th>
                        <th class="right">Prev</th>
                        <th class="right">Change</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['search_console']['top_pages'] as $p)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::limit($p['page'], 50) }}</td>
                            <td class="right">{{ number_format($p['clicks']) }}</td>
                            <td class="right" style="color:#94a3b8">{{ number_format($p['prev_clicks']) }}</td>
                            <td class="right">@include('emails.partials.change-inline', ['metric' => $p['change']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (count($report['search_console']['top_queries']) === 0 && count($report['search_console']['top_pages']) === 0)
            <p class="empty-note">No search console data available for this period.</p>
        @endif

        @if (! empty($report['search_console']['position_buckets']))
            <p class="sub-heading">Position Buckets</p>
            <table class="mini-grid" role="presentation">
                <tr>
                    <td>
                        <p class="mini-label">Top 3</p>
                        <p class="mini-value">{{ number_format($report['search_console']['position_buckets']['top_3'] ?? 0) }}</p>
                        <p class="mini-note">keywords</p>
                    </td>
                    <td>
                        <p class="mini-label">4-10</p>
                        <p class="mini-value">{{ number_format($report['search_console']['position_buckets']['top_10'] ?? 0) }}</p>
                        <p class="mini-note">keywords</p>
                    </td>
                    <td>
                        <p class="mini-label">11-20</p>
                        <p class="mini-value">{{ number_format($report['search_console']['position_buckets']['near_page_1'] ?? 0) }}</p>
                        <p class="mini-note">keywords</p>
                    </td>
                    <td>
                        <p class="mini-label">20+</p>
                        <p class="mini-value">{{ number_format($report['search_console']['position_buckets']['beyond_20'] ?? 0) }}</p>
                        <p class="mini-note">keywords</p>
                    </td>
                </tr>
            </table>
        @endif

        @if (count($report['search_console']['opportunities'] ?? []) > 0)
            <p class="sub-heading">Optimization Opportunities</p>
            <table class="data-table" role="presentation">
                <thead>
                    <tr>
                        <th>Query</th>
                        <th class="right">Impr.</th>
                        <th class="right">CTR</th>
                        <th class="right">Pos</th>
                        <th class="right">Score</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (($report['search_console']['opportunities'] ?? []) as $opp)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::limit($opp['query'], 40) }}</td>
                            <td class="right">{{ number_format($opp['impressions']) }}</td>
                            <td class="right">{{ $opp['ctr'] }}%</td>
                            <td class="right">{{ $opp['position'] }}</td>
                            <td class="right" style="font-weight:700;color:#4f46e5">{{ $opp['score'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (count($report['search_console']['top_query_gainers'] ?? []) > 0 || count($report['search_console']['top_query_losers'] ?? []) > 0)
            <table class="insight-cols" role="presentation">
                <tr>
                    <td>
                        <p class="sub-heading" style="margin-bottom:8px;color:#16a34a;">Query Gainers</p>
                        <ul class="insight-list">
                            @foreach (($report['search_console']['top_query_gainers'] ?? []) as $item)
                                <li>
                                    <span class="insight-row">
                                        <span class="label">{{ \Illuminate\Support\Str::limit($item['query'], 42) }}</span>
                                        <span class="value value-up">+{{ number_format($item['change']) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </td>
                    <td>
                        <p class="sub-heading" style="margin-bottom:8px;color:#dc2626;">Query Losers</p>
                        <ul class="insight-list">
                            @foreach (($report['search_console']['top_query_losers'] ?? []) as $item)
                                <li>
                                    <span class="insight-row">
                                        <span class="label">{{ \Illuminate\Support\Str::limit($item['query'], 42) }}</span>
                                        <span class="value value-down">{{ number_format($item['change']) }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </td>
                </tr>
            </table>
        @endif

        <hr class="section-divider">

        {{-- ==================== BACKLINKS ==================== --}}
        <span class="section-badge badge-backlinks">Backlinks</span>
        <h2 class="section-title">Link Profile</h2>

        <table class="kpi-grid" role="presentation">
            <tr>
                <td>
                    <span class="kpi-value">{{ number_format($report['backlinks']['count']['current']) }}</span>
                    <span class="kpi-label">New Backlinks</span>
                    @include('emails.partials.change-badge', ['metric' => $report['backlinks']['count']])
                    <span class="kpi-prev">was {{ number_format($report['backlinks']['count']['previous']) }}</span>
                </td>
                <td>
                    <span class="kpi-value">{{ $report['backlinks']['avg_da']['current'] }}</span>
                    <span class="kpi-label">Avg DA</span>
                    @include('emails.partials.change-badge', ['metric' => $report['backlinks']['avg_da']])
                    <span class="kpi-prev">was {{ $report['backlinks']['avg_da']['previous'] }}</span>
                </td>
                <td>
                    <span class="kpi-value">{{ number_format($report['backlinks']['dofollow']['current']) }}</span>
                    <span class="kpi-label">Dofollow</span>
                    @include('emails.partials.change-badge', ['metric' => $report['backlinks']['dofollow']])
                    <span class="kpi-prev">was {{ number_format($report['backlinks']['dofollow']['previous']) }}</span>
                </td>
                <td>
                    <span class="kpi-value">{{ number_format($report['backlinks']['nofollow']['current']) }}</span>
                    <span class="kpi-label">Nofollow</span>
                    @include('emails.partials.change-badge', ['metric' => $report['backlinks']['nofollow']])
                    <span class="kpi-prev">was {{ number_format($report['backlinks']['nofollow']['previous']) }}</span>
                </td>
            </tr>
        </table>

        @if (count($report['backlinks']['top_backlinks']) > 0)
            <p class="sub-heading">Top Backlinks by Domain Authority</p>
            <table class="data-table" role="presentation">
                <thead>
                    <tr>
                        <th>Referring Page</th>
                        <th>Target</th>
                        <th class="right">DA</th>
                        <th>Follow</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['backlinks']['top_backlinks'] as $b)
                        <tr>
                            <td><a href="{{ $b['referring_page_url'] }}" style="color:#4f46e5">{{ \Illuminate\Support\Str::limit($b['referring_page_url'], 40) }}</a></td>
                            <td>{{ \Illuminate\Support\Str::limit($b['target_page_url'], 35) }}</td>
                            <td class="right">{{ $b['domain_authority'] ?? '—' }}</td>
                            <td>{{ $b['is_dofollow'] ? 'Do' : 'No' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">No backlinks recorded for this period.</p>
        @endif

        <hr class="section-divider">

        {{-- ==================== INDEXING STATUS ==================== --}}
        <span class="section-badge" style="background:#0891b2">Indexing</span>
        <h2 class="section-title">Latest Google Indexing Status</h2>

        <table class="mini-grid" role="presentation">
            <tr>
                <td>
                    <p class="mini-label">Tracked Pages</p>
                    <p class="mini-value">{{ number_format($report['indexing']['summary']['tracked_pages'] ?? 0) }}</p>
                </td>
                <td>
                    <p class="mini-label">Checked Pages</p>
                    <p class="mini-value">{{ number_format($report['indexing']['summary']['checked_pages'] ?? 0) }}</p>
                </td>
                <td>
                    <p class="mini-label">PASS Verdict</p>
                    <p class="mini-value" style="color:#16a34a">{{ number_format($report['indexing']['summary']['pass_pages'] ?? 0) }}</p>
                </td>
                <td>
                    <p class="mini-label">FAIL Verdict</p>
                    <p class="mini-value" style="color:#dc2626">{{ number_format($report['indexing']['summary']['fail_pages'] ?? 0) }}</p>
                </td>
            </tr>
        </table>

        <p class="meta" style="margin-top:-6px; margin-bottom:12px;">
            Last checked:
            <strong>
                {{ !empty($report['indexing']['summary']['last_checked_at']) ? format_user_datetime($report['indexing']['summary']['last_checked_at'], 'M j, Y g:i A', $user) : 'Never' }}
            </strong>
        </p>

        @if (count($report['indexing']['latest'] ?? []) > 0)
            <table class="data-table" role="presentation">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th>Verdict</th>
                        <th>Coverage</th>
                        <th class="right">Last Crawl</th>
                        <th class="right">Checked</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach (($report['indexing']['latest'] ?? []) as $row)
                        <tr>
                            <td>{{ \Illuminate\Support\Str::limit($row['page'], 55) }}</td>
                            <td>
                                <span style="font-weight:700;color:{{ $row['verdict'] === 'PASS' ? '#16a34a' : ($row['verdict'] === 'FAIL' ? '#dc2626' : '#64748b') }}">
                                    {{ $row['verdict'] }}
                                </span>
                            </td>
                            <td>{{ $row['coverage_state'] }}</td>
                            <td class="right">{{ $row['last_crawl_at'] ? format_user_datetime($row['last_crawl_at'], 'M j, Y', $user) : '—' }}</td>
                            <td class="right">{{ $row['checked_at'] ? format_user_datetime($row['checked_at'], 'M j, Y g:i A', $user) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="empty-note">No indexing status checks recorded yet.</p>
        @endif

        @if (! empty($insights['cannibalization']) || ! empty($insights['striking_distance']) || ! empty($insights['indexing_fails_with_traffic']))
            <hr class="section-divider">

            <h2 class="section-title">Action Insights</h2>

            @if (! empty($insights['striking_distance']))
                <h3 style="font-size:13px;margin:12px 0 6px;">Top striking-distance keywords</h3>
                <table>
                    <thead><tr><th>Query</th><th class="right">Pos</th><th class="right">Impr</th><th class="right">CTR</th></tr></thead>
                    <tbody>
                        @foreach ($insights['striking_distance'] as $row)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::limit($row['query'], 60) }}@if (filled($row['language'] ?? null)) <span style="color:#94a3b8;font-size:10px;">[{{ strtoupper($row['language']) }}]</span>@endif</td>
                                <td class="right">{{ $row['position'] }}</td>
                                <td class="right">{{ number_format($row['impressions']) }}</td>
                                <td class="right">{{ $row['ctr'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if (! empty($insights['cannibalization']))
                <h3 style="font-size:13px;margin:12px 0 6px;">Top cannibalization queries</h3>
                <table>
                    <thead><tr><th>Query</th><th>Primary page</th><th class="right">Pages</th><th class="right">Impr</th></tr></thead>
                    <tbody>
                        @foreach ($insights['cannibalization'] as $row)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::limit($row['query'], 40) }}@if (filled($row['language'] ?? null)) <span style="color:#94a3b8;font-size:10px;">[{{ strtoupper($row['language']) }}]</span>@endif</td>
                                <td>{{ \Illuminate\Support\Str::limit($row['primary_page'], 45) }}</td>
                                <td class="right">{{ $row['page_count'] }}</td>
                                <td class="right">{{ number_format($row['total_impressions']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if (! empty($insights['indexing_fails_with_traffic']))
                <h3 style="font-size:13px;margin:12px 0 6px;">Indexing failures still getting traffic</h3>
                <table>
                    <thead><tr><th>Page</th><th>Verdict</th><th class="right">Clicks (14d)</th><th class="right">Impr (14d)</th></tr></thead>
                    <tbody>
                        @foreach ($insights['indexing_fails_with_traffic'] as $row)
                            <tr>
                                <td>{{ \Illuminate\Support\Str::limit($row['page'], 55) }}</td>
                                <td><span style="color:#dc2626;font-weight:700;">{{ $row['verdict'] }}</span></td>
                                <td class="right">{{ number_format($row['recent_clicks']) }}</td>
                                <td class="right">{{ number_format($row['recent_impressions']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endif

        <hr class="section-divider">

        <div style="text-align: center; padding-top: 8px;">
            <a href="{{ route('reports.index') }}" class="btn">View Full Report in Dashboard</a>
        </div>
    </div>
    @if (! $branding->isDefault())
        @if ($branding->footer_text || $branding->contact_email || $branding->contact_phone || $branding->contact_address)
            <div class="contact-block">
                @if ($branding->footer_text)<p>{{ $branding->footer_text }}</p>@endif
                @if ($branding->contact_email)<p><strong>Email:</strong> {{ $branding->contact_email }}</p>@endif
                @if ($branding->contact_phone)<p><strong>Phone:</strong> {{ $branding->contact_phone }}</p>@endif
                @if ($branding->contact_address)<p>{{ $branding->contact_address }}</p>@endif
            </div>
        @endif
        <p class="footer">{{ $branding->company_name }} &mdash; {{ format_user_now('M d, Y', $user) }}</p>
    @else
        <p class="footer">Sent by EBQ &mdash; {{ format_user_now('M d, Y', $user) }}</p>
    @endif
</div>
</body>
</html>
