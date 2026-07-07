@php
    $result = $auditReport->result ?? [];
    $meta = $result['metadata'] ?? [];
    $content = $result['content'] ?? [];
    $images = $result['images'] ?? [];
    $links = $result['links'] ?? [];
    $technical = $result['technical'] ?? [];
    $advanced = $result['advanced'] ?? [];
    $keywordData = $result['keywords'] ?? [];
    $kwAvailable = (bool) ($keywordData['available'] ?? false);
    $benchmark = $result['benchmark'] ?? null;
    $pageLocale = is_array($result['page_locale'] ?? null) ? $result['page_locale'] : null;
    $pageLocaleLabel = \App\Support\Audit\PageLocalePresentation::shortLabel($pageLocale);

    $skippedReasonLabel = fn (?string $reason) => match ($reason) {
        'no_primary_keyword' => __('Benchmark unavailable: no Search Console primary keyword for this page yet.'),
        'serper_request_failed' => __('Benchmark unavailable: the SERP API did not respond.'),
        'no_organic_results' => __('Benchmark unavailable: the SERP API returned no organic results for this keyword.'),
        'no_competitor_pages_fetched' => __('Benchmark unavailable: none of the top-ranking pages could be fetched.'),
        'benchmark_error' => __('Benchmark unavailable: an unexpected error occurred while benchmarking.'),
        null, '' => null,
        default => __('Benchmark unavailable:') . ' ' . str_replace('_', ' ', $reason) . '.',
    };
    $failed = $auditReport->status === 'failed';
    $auditStatusLabelsExport = [
        'queued' => __('Queued'),
        'running' => __('Running'),
        'completed' => __('Completed'),
        'failed' => __('Failed'),
    ];
    $auditStatusLabelExport = $auditStatusLabelsExport[$auditReport->status] ?? ucfirst((string) $auditReport->status);
    $sevLabelsExport = [
        'critical' => __('Critical'),
        'warning' => __('Warning'),
        'serp_gap' => __('SERP gap'),
        'info' => __('Info'),
        'good' => __('Good'),
    ];
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<title>{{ __('Page Audit Report') }} — {{ $auditReport->page }}</title>
<style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #0f172a; background: #f8fafc; margin: 0; padding: 24px; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .wrap { max-width: 960px; margin: 0 auto; }
    .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
    h1 { font-size: 20px; margin: 0 0 4px; }
    h2 { font-size: 13px; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin: 0 0 10px; }
    h3 { font-size: 14px; margin: 0 0 8px; }
    p { margin: 4px 0; font-size: 12px; line-height: 1.5; }
    .muted { color: #64748b; font-size: 11px; }
    .row { display: flex; flex-wrap: wrap; gap: 10px; }
    .kpi { flex: 1 1 140px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; }
    .kpi .label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 0.06em; }
    .kpi .value { font-size: 18px; font-weight: 700; margin-top: 4px; }
    .ok { color: #047857; }
    .warn { color: #b45309; }
    .bad { color: #b91c1c; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; }
    .badge.ok { background: #d1fae5; color: #047857; }
    .badge.bad { background: #fee2e2; color: #b91c1c; }
    .kv { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
    .kv:last-child { border-bottom: 0; }
    .kv dt { color: #64748b; }
    .kv dd { margin: 0; font-weight: 600; color: #0f172a; text-align: end; word-break: break-all; }
    .list { list-style: none; padding: 0; margin: 6px 0 0; font-size: 11px; max-height: 280px; overflow: auto; }
    .list li { padding: 3px 0; border-bottom: 1px solid #f1f5f9; word-break: break-all; }
    .list li:last-child { border-bottom: 0; }
    .tag { display: inline-block; background: #f1f5f9; color: #334155; padding: 2px 8px; border-radius: 999px; font-size: 10px; margin: 2px; }
    .section { margin-top: 18px; }
    a { color: #1d4ed8; text-decoration: none; }
    @media print {
        body { padding: 0; background: #fff; }
        .card { break-inside: avoid; box-shadow: none; }
    }
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <p class="muted">{{ __('Page Audit Report') }}</p>
        <h1 style="word-break: break-all;">{{ $auditReport->page }}</h1>
        <p class="muted">
            {{ __('Audited:') }} <strong>{{ $auditReport->audited_at ? format_user_datetime($auditReport->audited_at, 'M j, Y g:i A', $timezoneContextUser ?? null) : '—' }}</strong>
            &nbsp;·&nbsp; {{ __('Status:') }} <span class="badge {{ $failed ? 'bad' : 'ok' }}">{{ $auditStatusLabelExport }}</span>
        </p>
        @if ($pageLocaleLabel)
            <p style="margin-top: 8px; font-size: 11px; color: #475569;">
                <strong>{{ __('Detected market:') }}</strong> {{ $pageLocaleLabel }}
                @if (! empty($pageLocale['source'] ?? null))
                    <span style="color: #94a3b8;"> · {{ str_replace('_', ' ', (string) $pageLocale['source']) }}</span>
                @endif
            </p>
        @endif
        @if (filled($auditReport->primary_keyword))
            <p style="margin-top: 8px; font-size: 12px;">
                <strong>{{ __('Primary keyword:') }}</strong>
                <span style="font-family: ui-monospace, monospace;">{{ $auditReport->primary_keyword }}</span>
                @if (($auditReport->primary_keyword_source ?? null) === 'custom_audit')
                    <span style="margin-inline-start: 6px; font-size: 9px; font-weight: 800; text-transform: uppercase; color: #5b21b6;">({{ __('custom') }})</span>
                @elseif (($auditReport->primary_keyword_source ?? null) === 'gsc_primary')
                    <span class="muted" style="margin-inline-start: 6px; font-size: 10px;">({{ __('Search Console') }})</span>
                @endif
            </p>
        @endif
    </div>

    @if ($failed)
        <div class="card">
            <p class="bad"><strong>{{ __('Audit failed:') }}</strong> {{ $auditReport->error_message ?? __('Unknown error') }}</p>
            @if (filled($auditReport->primary_keyword))
                <p class="muted" style="margin-top: 8px; font-size: 12px;">
                    <strong>{{ __('Intended primary keyword:') }}</strong>
                    <span style="font-family: ui-monospace, monospace;">{{ $auditReport->primary_keyword }}</span>
                </p>
            @endif
        </div>
    @else
        @php $recs = $result['recommendations'] ?? []; @endphp
        @if (! empty($recs))
            @php $counts = collect($recs)->groupBy('severity')->map->count(); @endphp
            <div class="card">
                <h2>{{ __('Recommendations') }}</h2>
                <p class="muted" style="margin-bottom: 10px;">
                    @foreach (['critical', 'warning', 'serp_gap', 'info', 'good'] as $sev)
                        @if (($counts[$sev] ?? 0) > 0)
                            <span class="tag"><strong>{{ $counts[$sev] }}</strong> {{ $sevLabelsExport[$sev] ?? ucfirst($sev) }}</span>
                        @endif
                    @endforeach
                </p>
                @foreach ($recs as $r)
                    @php
                        $sevColor = match ($r['severity']) {
                            'critical' => '#b91c1c',
                            'warning' => '#b45309',
                            'serp_gap' => '#5b21b6',
                            'info' => '#0369a1',
                            'good' => '#047857',
                            default => '#64748b',
                        };
                        $sevBg = match ($r['severity']) {
                            'critical' => '#fee2e2',
                            'warning' => '#fef3c7',
                            'serp_gap' => '#ede9fe',
                            'info' => '#e0f2fe',
                            'good' => '#d1fae5',
                            default => '#f1f5f9',
                        };
                    @endphp
                    <div style="border-inline-start: 4px solid {{ $sevColor }}; background: {{ $sevBg }}; padding: 10px 12px; border-radius: 6px; margin-bottom: 8px;">
                        <p style="margin: 0;">
                            <span class="badge" style="background: #fff; color: {{ $sevColor }}; border: 1px solid {{ $sevColor }};">{{ strtoupper($sevLabelsExport[$r['severity']] ?? $r['severity']) }}</span>
                            <span class="muted" style="margin-inline-start: 6px;">{{ $r['section'] }}</span>
                            <strong style="margin-inline-start: 6px;">{{ $r['title'] }}</strong>
                        </p>
                        <p style="margin-top: 6px;"><strong>{{ __('Why it matters:') }}</strong> {{ $r['why'] }}</p>
                        <p><strong>{{ __('Fix:') }}</strong> {{ $r['fix'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Keyword Strategy --}}
        @if ($kwAvailable)
            @php
                $pp = $keywordData['power_placement'] ?? [];
                $cov = $keywordData['coverage'] ?? [];
                $intent = $keywordData['intent'] ?? [];
                $accidental = $keywordData['accidental'] ?? [];
                $primary = $keywordData['primary'] ?? null;
                $covScore = (float) ($cov['score'] ?? 0);
                $covColor = $covScore >= 80 ? '#047857' : ($covScore >= 50 ? '#b45309' : '#b91c1c');
                $covBg = $covScore >= 80 ? '#d1fae5' : ($covScore >= 50 ? '#fef3c7' : '#fee2e2');
            @endphp
            <div class="card">
                <h2>{{ __('Keyword Strategy') }}</h2>

                @if ($primary)
                    <p>
                        <strong>{{ __('Primary keyword:') }}</strong> "{{ $primary['query'] }}"
                        @if (($keywordData['primary_source'] ?? null) === 'custom_audit')
                            <span style="margin-inline-start: 6px; font-size: 9px; font-weight: 800; text-transform: uppercase; color: #5b21b6;">({{ __('custom audit') }})</span>
                        @else
                            &nbsp;·&nbsp; {{ number_format($primary['clicks'] ?? 0) }} {{ __('clicks') }} · {{ number_format($primary['impressions'] ?? 0) }} {{ __('impressions') }} · {{ __('position') }} {{ number_format($primary['position'] ?? 0, 1) }}
                        @endif
                    </p>
                    <div class="row" style="margin-top: 8px;">
                        @foreach ([['in_title', 'Title'], ['in_h1', 'H1'], ['in_meta_description', 'Meta description']] as [$key, $label])
                            @php $present = (bool) ($pp[$key] ?? false); @endphp
                            <div class="kpi" style="border-color: {{ $present ? '#a7f3d0' : '#fecaca' }}; background: {{ $present ? '#ecfdf5' : '#fef2f2' }};">
                                <div class="label" style="color: {{ $present ? '#047857' : '#b91c1c' }};">{{ $label === 'H1' ? $label : __($label) }}</div>
                                <div class="value" style="color: {{ $present ? '#047857' : '#b91c1c' }}; font-size: 14px;">{{ $present ? __('Present') : __('Missing') }}</div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <h3 style="margin-top: 14px;">{{ __('Topical coverage') }}</h3>
                <p>
                    <span style="display: inline-block; background: {{ $covBg }}; color: {{ $covColor }}; border-radius: 6px; padding: 4px 10px; font-weight: 700;">
                        {{ $cov['found_count'] ?? 0 }} / {{ $cov['total'] ?? 0 }} · {{ $covScore }}%
                    </span>
                    <span class="muted" style="margin-inline-start: 8px;">
                        @if ($covScore >= 80) {{ __('High topical authority') }}
                        @elseif ($covScore < 50) {{ __('Expansion needed') }}
                        @else {{ __('Partial coverage') }} @endif
                    </span>
                </p>

                @if (! empty($cov['missing']))
                    <h3 style="margin-top: 12px;">{{ __('Missing from body') }} ({{ $cov['missing_count'] ?? count($cov['missing']) }})</h3>
                    <ul class="list">
                        @foreach (array_slice($cov['missing'], 0, 30) as $m)
                            <li><strong>{{ $m['query'] }}</strong> <span class="muted">— {{ number_format($m['impressions'] ?? 0) }} {{ __('impressions') }} · {{ __('position') }} {{ number_format($m['position'] ?? 0, 1) }}</span></li>
                        @endforeach
                    </ul>
                @endif

                <h3 style="margin-top: 14px;">{{ __('Search intent') }}</h3>
                @php
                    $domExport = $intent['dominant'] ?? 'unclear';
                    $intentDominantLabelsExport = [
                        'informational' => __('Informational'),
                        'utility' => __('Tool / app'),
                        'commercial' => __('Commercial'),
                        'transactional' => __('Transactional'),
                        'navigational' => __('Navigational'),
                        'local' => __('Local'),
                        'support' => __('Support'),
                        'commercial_utility' => __('Commercial + tool / app'),
                        'commercial_informational' => __('Commercial + informational'),
                        'commercial_transactional' => __('Commercial + transactional'),
                        'informational_utility' => __('Informational + tool / app'),
                        'mixed' => __('Mixed (3+ tied top scores)'),
                        'unclear' => __('Unclear'),
                    ];
                    $dominantLabelExport = $intentDominantLabelsExport[$domExport] ?? collect(explode('_', $domExport))->map(function (string $part) {
                        return $part === 'utility' ? __('Tool / app') : ucfirst($part);
                    })->implode(' + ');
                    $intentBucketsExport = [
                        __('Informational') => (int) ($intent['informational_count'] ?? 0),
                        __('Tool / app') => (int) ($intent['utility_count'] ?? 0),
                        __('Commercial') => (int) ($intent['commercial_count'] ?? 0),
                        __('Transactional') => (int) ($intent['transactional_count'] ?? 0),
                        __('Navigational') => (int) ($intent['navigational_count'] ?? 0),
                        __('Local') => (int) ($intent['local_count'] ?? 0),
                        __('Support') => (int) ($intent['support_count'] ?? 0),
                    ];
                    $intentSummaryPartsExport = [];
                    foreach ($intentBucketsExport as $label => $n) {
                        if ($n > 0) {
                            $intentSummaryPartsExport[] = $label . ': ' . $n;
                        }
                    }
                    $intentSummaryExport = $intentSummaryPartsExport !== [] ? implode(' · ', $intentSummaryPartsExport) : __('No trigger matches');
                    $scorePartsExport = [];
                    foreach ($intent['intent_scores'] ?? [] as $esk => $esv) {
                        if ((float) $esv > 0) {
                            $scorePartsExport[] = $esk . ': ' . $esv;
                        }
                    }
                    $intentScoreLineExport = $scorePartsExport !== [] ? __('Weighted:') . ' ' . implode(' · ', $scorePartsExport) : '';
                @endphp
                <p>
                    <strong>{{ $dominantLabelExport }}</strong>
                    <span class="muted"> — {{ $intentSummaryExport }}</span>
                    @if ($intentScoreLineExport !== '')
                        <br><span class="muted" style="font-size: 10px;">{{ $intentScoreLineExport }}</span>
                    @endif
                </p>

                @if (! empty($accidental))
                    <h3 style="margin-top: 14px;">{{ __('Accidental authority candidates') }}</h3>
                    <div>
                        @foreach ($accidental as $a)
                            <span class="tag" style="background: #fef3c7; color: #b45309;"><strong>{{ $a['term'] }}</strong> — {{ $a['density'] }}%</span>
                        @endforeach
                    </div>
                @endif

                @if (! empty($keywordData['target_keywords']))
                    @php
                        $_exportTargetQueries = collect($keywordData['target_keywords'])->pluck('query')->map(fn ($q) => (string) $q)->values()->all();
                        $_exportTargetKe = $_exportTargetQueries === []
                            ? []
                            : \App\Models\KeywordMetric::query()
                                ->whereIn('keyword_hash', array_unique(array_map(fn ($q) => \App\Models\KeywordMetric::hashKeyword($q), $_exportTargetQueries)))
                                ->where('country', 'global')
                                ->get()
                                ->keyBy('keyword_hash')
                                ->all();
                    @endphp
                    <h3 style="margin-top: 14px;">{{ __('Target keywords from Search Console') }} ({{ count($keywordData['target_keywords']) }})</h3>
                    <table style="width: 100%; border-collapse: collapse; font-size: 11px;">
                        <thead>
                            <tr style="background: #f1f5f9;">
                                <th style="text-align: start; padding: 4px 6px;">{{ __('Keyword') }}</th>
                                <th style="text-align: end; padding: 4px 6px;">{{ __('Clicks') }}</th>
                                <th style="text-align: end; padding: 4px 6px;">{{ __('Impr.') }}</th>
                                <th style="text-align: end; padding: 4px 6px;">{{ __('Pos') }}</th>
                                <th style="text-align: end; padding: 4px 6px;">{{ __('Vol/mo') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($keywordData['target_keywords'] as $t)
                                @php $_exportKe = $_exportTargetKe[\App\Models\KeywordMetric::hashKeyword((string) $t['query'])] ?? null; @endphp
                                <tr style="border-top: 1px solid #e2e8f0;">
                                    <td style="padding: 4px 6px;">{{ $t['query'] }}</td>
                                    <td style="padding: 4px 6px; text-align: end;">{{ number_format($t['clicks']) }}</td>
                                    <td style="padding: 4px 6px; text-align: end;">{{ number_format($t['impressions']) }}</td>
                                    <td style="padding: 4px 6px; text-align: end;">{{ number_format($t['position'], 1) }}</td>
                                    <td style="padding: 4px 6px; text-align: end;">{{ ($_exportKe && $_exportKe->search_volume !== null) ? number_format($_exportKe->search_volume) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif

        {{-- Traffic by country (GSC) --}}
        @php
            $countryData = app(\App\Services\PluginInsightResolver::class)
                ->countryBreakdownForAuditReport($auditReport->website, (string) $auditReport->page, 10);
            $countryRows = $countryData['rows'];
            $countryTotalClicks = $countryData['total_clicks'];
        @endphp
        <div class="card">
            <h2>{{ __('Traffic by country') }}</h2>
            <p class="muted" style="margin-top: -6px;">{{ __('Search Console data for this URL, last 30 days.') }}</p>
            @if (empty($countryRows))
                <p class="muted" style="margin-top: 10px;">{{ __('No Search Console country data yet for this page.') }}</p>
            @else
                @php $primaryCountry = $countryRows[0]; @endphp
                <p style="margin-top: 8px; font-size: 12px; color: #334155;">
                    <strong>{{ count($countryRows) }}</strong> {{ count($countryRows) === 1 ? __('market') : __('markets') }}
                    &nbsp;·&nbsp; <strong>{{ number_format($countryTotalClicks) }}</strong> {{ __('clicks') }}
                    &nbsp;·&nbsp; {{ __('top:') }}
                    @if (! empty($primaryCountry['flag']))
                        {{ $primaryCountry['flag'] }}
                    @endif
                    <strong>{{ $primaryCountry['name'] }}</strong>
                    <span class="muted">({{ $primaryCountry['share_pct'] }}%)</span>
                </p>
                <table style="width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 11px;">
                    <thead>
                        <tr style="background: #f8fafc; border-bottom: 2px solid #e2e8f0;">
                            <th style="padding: 6px 8px; text-align: start; width: 28px; color: #64748b; font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em;">#</th>
                            <th style="padding: 6px 8px; text-align: start; color: #64748b; font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em;">{{ __('Market') }}</th>
                            <th style="padding: 6px 8px; text-align: start; color: #64748b; font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em;">{{ __('Share') }}</th>
                            <th style="padding: 6px 8px; text-align: end; color: #64748b; font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em;">{{ __('Clicks') }}</th>
                            <th style="padding: 6px 8px; text-align: end; color: #64748b; font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em;">{{ __('Impressions') }}</th>
                            <th style="padding: 6px 8px; text-align: end; color: #64748b; font-weight: 700; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em;">{{ __('Avg pos') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($countryRows as $i => $row)
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 6px 8px; color: #94a3b8; font-family: ui-monospace, monospace;">{{ $i + 1 }}</td>
                                <td style="padding: 6px 8px; color: #0f172a;">
                                    @if (! empty($row['flag']))
                                        {{ $row['flag'] }}
                                    @endif
                                    <strong>{{ $row['name'] }}</strong>
                                    <span class="muted" style="font-family: ui-monospace, monospace;">{{ $row['country'] }}</span>
                                </td>
                                <td style="padding: 6px 8px; min-width: 140px;">
                                    <table cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse;">
                                        <tr>
                                            <td style="padding: 0;">
                                                <div style="background: #e2e8f0; height: 6px; border-radius: 3px; overflow: hidden;">
                                                    <div style="background: {{ $i === 0 ? '#F26419' : '#F8B18B' }}; width: {{ $row['width_pct'] }}%; height: 100%;"></div>
                                                </div>
                                            </td>
                                            <td style="padding: 0 0 0 8px; width: 38px; text-align: end; font-weight: 700; color: #475569; font-variant-numeric: tabular-nums;">{{ $row['share_pct'] }}%</td>
                                        </tr>
                                    </table>
                                </td>
                                <td style="padding: 6px 8px; text-align: end; font-weight: 700; color: #0f172a; font-variant-numeric: tabular-nums;">{{ number_format($row['clicks']) }}</td>
                                <td style="padding: 6px 8px; text-align: end; color: #475569; font-variant-numeric: tabular-nums;">{{ number_format($row['impressions']) }}</td>
                                <td style="padding: 6px 8px; text-align: end; color: #475569; font-variant-numeric: tabular-nums;">
                                    @if ($row['position'] !== null)
                                        {{ $row['position'] }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- 1. Metadata --}}
        <div class="card">
            <h2>{{ __('1. Metadata') }}</h2>
            <div class="row">
                <div class="kpi" style="flex: 1 1 100%;">
                    <div class="label">{{ __('Title') }} ({{ $meta['title_length'] ?? 0 }} {{ __('chars') }})</div>
                    <p>{{ $meta['title'] ?? '—' }}</p>
                </div>
                <div class="kpi" style="flex: 1 1 100%;">
                    <div class="label">{{ __('Meta description') }} ({{ $meta['meta_description_length'] ?? 0 }} {{ __('chars') }})</div>
                    <p>{{ $meta['meta_description'] ?? '—' }}</p>
                </div>
                <div class="kpi"><div class="label">{{ __('Canonical') }}</div><p style="word-break: break-all;">{{ $meta['canonical'] ?? '—' }}</p><p class="{{ ($meta['canonical_matches'] ?? false) ? 'ok' : 'warn' }}">{{ ($meta['canonical_matches'] ?? false) ? __('Matches') : __('Does not match') }}</p></div>
                <div class="kpi"><div class="label">{{ __('OpenGraph tags') }}</div><div class="value">{{ $meta['og_tag_count'] ?? 0 }}</div></div>
                <div class="kpi"><div class="label">{{ __('Twitter tags') }}</div><div class="value">{{ $meta['twitter_tag_count'] ?? 0 }}</div></div>
            </div>
        </div>

        {{-- 2. Content & Structure --}}
        <div class="card">
            <h2>{{ __('2. Content & Structure') }}</h2>
            <div class="row">
                <div class="kpi"><div class="label">{{ __('H1 count') }}</div><div class="value {{ ($content['h1_count'] ?? 0) === 1 ? 'ok' : 'warn' }}">{{ $content['h1_count'] ?? 0 }}</div></div>
                <div class="kpi"><div class="label">{{ __('Heading order') }}</div><div class="value {{ ($content['heading_order_ok'] ?? false) ? 'ok' : 'warn' }}" style="font-size: 14px;">{{ ($content['heading_order_ok'] ?? false) ? __('Logical') : __('Skipped levels') }}</div></div>
                <div class="kpi"><div class="label">{{ __('Word count') }}</div><div class="value">{{ number_format($content['word_count'] ?? 0) }}</div></div>
                <div class="kpi"><div class="label">{{ __('Headings total') }}</div><div class="value">{{ count($content['headings'] ?? []) }}</div></div>
            </div>
            @if (! empty($content['first_150_words']))
                <div class="section">
                    <h3>{{ __('Answer readiness (first 150 words)') }}</h3>
                    <p>{{ $content['first_150_words'] }}</p>
                </div>
            @endif
            @if (! empty($content['keyword_density']))
                <div class="section">
                    <h3>{{ __('Keyword density (top 20)') }}</h3>
                    <div>
                        @foreach ($content['keyword_density'] as $kw)
                            <span class="tag"><strong>{{ $kw['term'] }}</strong> ×{{ $kw['count'] }} · {{ $kw['density'] }}%</span>
                        @endforeach
                    </div>
                </div>
            @endif
            @if (! empty($content['headings']))
                <div class="section">
                    <h3>{{ __('Heading outline') }} ({{ count($content['headings']) }})</h3>
                    <ul class="list">
                        @foreach ($content['headings'] as $h)
                            <li style="padding-inline-start: {{ ($h['level'] - 1) * 14 }}px;"><span class="muted">H{{ $h['level'] }}</span> {{ $h['text'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- 3. Images & Links --}}
        <div class="card">
            <h2>{{ __('3. Image & Link Analysis') }}</h2>
            <div class="row">
                <div class="kpi"><div class="label">{{ __('Images total') }}</div><div class="value">{{ $images['total'] ?? 0 }}</div></div>
                <div class="kpi"><div class="label">{{ __('Missing alt') }}</div><div class="value {{ ($images['missing_alt_count'] ?? 0) > 0 ? 'bad' : 'ok' }}">{{ $images['missing_alt_count'] ?? 0 }}</div></div>
                <div class="kpi"><div class="label">{{ __('Modern formats (webp/avif)') }}</div><div class="value">{{ $images['modern_format_count'] ?? 0 }}</div></div>
                <div class="kpi"><div class="label">{{ __('Broken links') }}</div><div class="value {{ count($links['broken'] ?? []) > 0 ? 'bad' : 'ok' }}">{{ count($links['broken'] ?? []) }}</div></div>
                <div class="kpi"><div class="label">{{ __('Internal links') }}</div><div class="value">{{ $links['internal_count'] ?? 0 }}</div></div>
                <div class="kpi"><div class="label">{{ __('External links') }}</div><div class="value">{{ $links['external_count'] ?? 0 }}</div></div>
            </div>
            @if (! empty($images['missing_alt']))
                <div class="section">
                    <h3>{{ __('Images missing alt') }}</h3>
                    <ul class="list">
                        @foreach ($images['missing_alt'] as $src)
                            <li>{{ $src }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if (! empty($links['broken']))
                <div class="section">
                    <h3 class="bad">{{ __('Broken links') }} ({{ count($links['broken']) }})</h3>
                    <ul class="list">
                        @foreach ($links['broken'] as $b)
                            <li><span class="badge bad">{{ $b['status'] ?? 'ERR' }}</span> <a href="{{ $b['href'] }}">{{ $b['href'] }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- 4. Technical --}}
        @php
            $stackExp = $technical['stack'] ?? null;
            $stackLabelExp = $stackExp['label'] ?? 'Unknown';
            $stackTypeExp = $stackExp['type'] ?? 'unknown';
            $stackToneClassExp = match ($stackTypeExp) {
                'modern' => 'ok',
                'cms' => 'warn',
                default => '',
            };
            $stackGapRowExp = null;
            foreach (data_get($result, 'benchmark.gap_table.rows', []) as $gapRowExp) {
                if (($gapRowExp['key'] ?? null) === 'stack') { $stackGapRowExp = $gapRowExp; break; }
            }
            $deltaKindExp = $stackGapRowExp['delta_kind'] ?? null;
            $gapChipColorExp = match ($deltaKindExp) {
                'moat' => ['bg' => '#d1fae5', 'fg' => '#047857', 'label' => __('Moat vs Top 3')],
                'disadvantage' => ['bg' => '#fee2e2', 'fg' => '#b91c1c', 'label' => __('Gap vs Top 3')],
                'parity' => ['bg' => '#f1f5f9', 'fg' => '#334155', 'label' => __('Parity vs Top 3')],
                default => null,
            };
        @endphp
        <div class="card">
            <h2>{{ __('4. Technical Performance') }}</h2>
            <div class="row">
                <div class="kpi"><div class="label">{{ __('HTTP status') }}</div><div class="value {{ ($technical['http_status'] ?? 0) < 400 ? 'ok' : 'bad' }}">{{ $technical['http_status'] ?? '—' }}</div></div>
                <div class="kpi"><div class="label">{{ __('Response time (TTFB)') }}</div><div class="value">{{ isset($technical['ttfb_ms']) ? $technical['ttfb_ms'].' ms' : '—' }}</div></div>
                <div class="kpi"><div class="label">{{ __('Page size') }}</div><div class="value">{{ isset($technical['page_size_bytes']) ? number_format($technical['page_size_bytes'] / 1024, 1).' KB' : '—' }}</div></div>
                <div class="kpi"><div class="label">{{ __('Compression') }}</div><div class="value" style="font-size: 14px;">{{ $technical['compression'] ?? __('none') }}</div></div>
                <div class="kpi"><div class="label">{{ __('HTTPS') }}</div><div class="value {{ ($technical['is_https'] ?? false) ? 'ok' : 'warn' }}" style="font-size: 14px;">{{ ($technical['is_https'] ?? false) ? __('Yes') : __('No') }}</div></div>
                <div class="kpi">
                    <div class="label">{{ __('Tech stack') }}</div>
                    <div class="value {{ $stackToneClassExp }}" style="font-size: 14px;">{{ $stackLabelExp }}</div>
                    @if ($gapChipColorExp)
                        <span class="badge" style="margin-top: 4px; background: {{ $gapChipColorExp['bg'] }}; color: {{ $gapChipColorExp['fg'] }};">{{ $gapChipColorExp['label'] }}</span>
                    @endif
                </div>
            </div>
        </div>

        {{-- 5. Advanced --}}
        <div class="card">
            <h2>{{ __('5. Advanced Data') }}</h2>
            <div class="row">
                <div class="kpi"><div class="label">{{ __('Schema (JSON-LD)') }}</div><div class="value">{{ $advanced['schema_blocks'] ?? 0 }}</div></div>
                @php
                    $fleschExp = data_get($advanced, 'readability.flesch');
                    $fleschExp = is_numeric($fleschExp) ? (float) $fleschExp : null;
                @endphp
                <div class="kpi">
                    <div class="label">{{ __('Readability (Flesch)') }}</div>
                    <div class="value">{{ $fleschExp !== null ? $fleschExp : '—' }}</div>
                    <p class="muted">{{ data_get($advanced, 'readability.grade') ?? '' }}</p>
                </div>
                <div class="kpi"><div class="label">{{ __('Favicon') }}</div><div class="value {{ ($advanced['has_favicon'] ?? false) ? 'ok' : 'warn' }}" style="font-size: 14px;">{{ ($advanced['has_favicon'] ?? false) ? __('Present') : __('Missing') }}</div></div>
            </div>
        </div>

        @if (is_array($benchmark))
            <div class="card">
                <h2>{{ __('6. SERP readability benchmark') }}</h2>
                @if (! empty($benchmark['your_serp']) && is_array($benchmark['your_serp']))
                    @php
                        $ys = $benchmark['your_serp'];
                        $ysFound = ! empty($ys['found']);
                        $ysPos = $ys['position'] ?? null;
                        $ysFirst = $ys['on_first_page'] ?? null;
                        $ysN = (int) ($ys['organic_sample_size'] ?? 0);
                        $ysBoxBg = $ysFound && is_numeric($ysPos)
                            ? ($ysFirst === true ? 'linear-gradient(135deg, #ecfdf5 0%, #ffffff 100%)' : 'linear-gradient(135deg, #fffbeb 0%, #ffffff 100%)')
                            : '#f8fafc';
                        $ysBoxBorder = $ysFound && is_numeric($ysPos)
                            ? ($ysFirst === true ? '#34d399' : '#fbbf24')
                            : '#cbd5e1';
                    @endphp
                    <div style="margin: 0 0 16px; padding: 16px 20px; border-radius: 14px; border: 2px solid {{ $ysBoxBorder }}; background: {{ $ysBoxBg }};">
                        @if ($ysFound && is_numeric($ysPos))
                            <table style="width: 100%; border-collapse: collapse;">
                                <tr>
                                    <td style="width: 160px; vertical-align: middle; text-align: center; padding: 8px 16px 8px 0;">
                                        <p style="margin: 0; font-size: 9px; font-weight: 800; letter-spacing: 0.15em; color: #64748b; text-transform: uppercase;">{{ __('Organic rank') }}</p>
                                        <p style="margin: 6px 0 0; font-size: 42px; font-weight: 900; line-height: 1; letter-spacing: -0.02em; color: {{ $ysFirst === true ? '#059669' : '#d97706' }};">#{{ $ysPos }}</p>
                                        <p style="margin: 8px 0 0;">
                                            <span style="display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 9px; font-weight: 800; letter-spacing: 0.06em; text-transform: uppercase; color: #fff; background: {{ $ysFirst === true ? '#059669' : '#d97706' }};">{{ $ysFirst === true ? __('First page') : __('Page 2+') }}</span>
                                        </p>
                                    </td>
                                    <td style="vertical-align: middle; padding-inline-start: 12px; border-inline-start: 1px solid rgba(148,163,184,0.5); font-size: 12px; line-height: 1.55; color: #334155;">
                                        <strong style="color: #0f172a;">{{ __('Search position (live SERP snapshot)') }}</strong><br>
                                        @if ($ysFirst === true)
                                            {{ __('Your site (same domain in this sample) appears in the') }} <strong>{{ __('top 10') }}</strong> {{ __('organic results — the listing URL may differ from the page you audited. Use Search Console for official average position.') }}
                                        @else
                                            {{ __('Your site at position') }} <strong>#{{ $ysPos }}</strong> {{ __('in this sample —') }} <strong>{{ __('outside the first page') }}</strong> {{ __('(positions 1–10).') }}
                                        @endif
                                        <br><span class="muted" style="font-size: 10px;">{{ $ysN }} {{ __('organic listings in sample.') }}</span>
                                    </td>
                                </tr>
                            </table>
                        @elseif ($ysN === 0)
                            <p style="margin: 0; font-size: 13px; font-weight: 700; color: #0f172a;">{{ __('Search position') }}</p>
                            <p style="margin: 6px 0 0; font-size: 12px; color: #475569;">{{ __('Rank could not be checked — no organic links in the SERP response.') }}</p>
                        @else
                            <p style="margin: 0; font-size: 13px; font-weight: 700; color: #0f172a;">{{ __('Not in top') }} {{ $ysN }} {{ __('of this sample') }}</p>
                            <p style="margin: 6px 0 0; font-size: 12px; color: #475569;">{{ __('Your site’s domain did not match any of the') }} {{ $ysN }} {{ __('organic results in this snapshot (domain match, not full URL path). Check Search Console for your real average position.') }}</p>
                        @endif
                        @include('partials.serp-vs-audited-snippet-export', ['ys' => $ys])
                    </div>
                @endif
                <p class="muted" style="margin-bottom: 10px;">
                    {{ __('Organic URLs pulled live from Google at audit time. Flesch scores are from HTML we fetched ourselves; rankings are approximate and not identical to live Google.') }}
                    @if (\App\Support\Audit\PageLocalePresentation::shouldShowSerpLocationNote($benchmark['serp_locale'] ?? null))
                        @php $exportSerpLoc = \App\Support\Audit\PageLocalePresentation::serpParamsLine($benchmark['serp_locale'] ?? null); @endphp
                        @if ($exportSerpLoc)
                            <br><span style="display: inline-block; margin-top: 4px;">{{ __('SERP sample region:') }} <span style="font-family: ui-monospace, monospace;">{{ $exportSerpLoc }}</span></span>
                        @endif
                    @endif
                </p>
                @if (! empty($benchmark['keyword']))
                    <p>
                        <strong>{{ __('SERP keyword:') }}</strong>
                        <span style="font-family: ui-monospace, monospace;">{{ $benchmark['keyword'] }}</span>
                        @if (($benchmark['keyword_source'] ?? null) === 'manual')
                            <span style="margin-inline-start: 6px; font-size: 9px; font-weight: 800; text-transform: uppercase; color: #5b21b6;">({{ __('custom audit') }})</span>
                        @elseif (($benchmark['keyword_source'] ?? null) === 'gsc_primary')
                            <span class="muted" style="margin-inline-start: 6px; font-size: 10px;">({{ __('Search Console') }})</span>
                        @endif
                    </p>
                @endif
                @php $skippedLabel = $skippedReasonLabel($benchmark['skipped_reason'] ?? null); @endphp
                @if ($skippedLabel && empty($benchmark['competitors']))
                    <p class="warn">{{ $skippedLabel }}</p>
                @endif
                @if (! empty($benchmark['competitors']))
                    <table style="width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 10px;">
                        <thead>
                            <tr style="background: #f1f5f9;">
                                <th style="text-align: start; padding: 4px 6px;">{{ __('Page') }}</th>
                                <th style="text-align: end; padding: 4px 6px;">{{ __('Flesch') }}</th>
                                <th style="text-align: start; padding: 4px 6px;">{{ __('Grade') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr style="border-top: 1px solid #e2e8f0; background: #FEF0E7;">
                                <td style="padding: 4px 6px; font-weight: 700;">{{ __('This page (audited)') }}</td>
                                <td style="padding: 4px 6px; text-align: end; font-weight: 700;">{{ is_numeric($benchmark['your_flesch'] ?? null) ? $benchmark['your_flesch'] : '—' }}</td>
                                <td style="padding: 4px 6px;">{{ data_get($advanced, 'readability.grade') ?? '—' }}</td>
                            </tr>
                            @foreach ($benchmark['competitors'] as $row)
                                <tr style="border-top: 1px solid #e2e8f0;">
                                    <td style="padding: 4px 6px; max-width: 360px;">
                                        <a href="{{ $row['url'] }}">{{ \Illuminate\Support\Str::limit($row['title'] ?: $row['url'], 64) }}</a>
                                        @if (isset($row['position']))
                                            <span class="muted"> #{{ $row['position'] }}</span>
                                        @endif
                                    </td>
                                    <td style="padding: 4px 6px; text-align: end;">{{ is_numeric($row['flesch'] ?? null) ? $row['flesch'] : '—' }}</td>
                                    <td style="padding: 4px 6px;">{{ $row['grade'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                @if (! empty($benchmark['gap_table']['rows']))
                    <h3 style="margin: 18px 0 6px; font-size: 13px;">{{ __('You vs. market average') }}</h3>
                    <p class="muted" style="margin: 0 0 10px; font-size: 11px;">{{ __('Mean of competitor pages fetched for this benchmark. Image counts reflect visual engagement gaps that word count misses.') }}</p>
                    <table style="width: 100%; border-collapse: collapse; font-size: 11px; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
                        <thead>
                            <tr style="background: #f1f5f9;">
                                <th style="text-align: start; padding: 6px 8px;">{{ __('Metric') }}</th>
                                <th style="text-align: end; padding: 6px 8px;">{{ __('Your page') }}</th>
                                <th style="text-align: end; padding: 6px 8px;">{{ __('Market avg') }}</th>
                                <th style="text-align: start; padding: 6px 8px;">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($benchmark['gap_table']['rows'] as $g)
                                @php
                                    $gk = $g['key'] ?? '';
                                    $hasYours = isset($g['yours']) && $g['yours'] !== null;
                                    $hasAvg = isset($g['market_avg']) && $g['market_avg'] !== null;
                                    $hasDelta = isset($g['delta']) && is_numeric($g['delta']);
                                    $yoursFmt = $hasYours
                                        ? ($gk === 'word_count'
                                            ? number_format((int) $g['yours'])
                                            : (($gk === 'flesch') ? number_format((float) $g['yours'], 1) : (is_numeric($g['yours']) ? number_format((int) $g['yours']) : (string) $g['yours'])))
                                        : '—';
                                    $avgFmt = $hasAvg
                                        ? ($gk === 'word_count'
                                            ? number_format((int) round((float) $g['market_avg']))
                                            : (($gk === 'flesch') ? number_format((float) $g['market_avg'], 1) : (is_numeric($g['market_avg']) ? number_format((float) $g['market_avg'], 1) : (string) $g['market_avg'])))
                                        : '—';
                                    $d = $hasDelta ? (float) $g['delta'] : 0.0;
                                    $sign = $d > 0 ? '+' : '';
                                    $deltaFmt = $hasDelta
                                        ? ($gk === 'word_count'
                                            ? $sign.number_format((int) round($d), 0, '.', ',')
                                            : $sign.number_format($d, 1))
                                        : '—';
                                @endphp
                                <tr style="border-top: 1px solid #e2e8f0;">
                                    <td style="padding: 6px 8px; font-weight: 600;">{{ $g['metric'] ?? '' }}</td>
                                    <td style="padding: 6px 8px; text-align: end;">{{ $yoursFmt }}</td>
                                    <td style="padding: 6px 8px; text-align: end;">{{ $avgFmt }}</td>
                                    <td style="padding: 6px 8px;">{{ $deltaFmt }} <span class="muted">({{ $g['status'] ?? '' }})</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @php
                        $gapHasFleschOutOfRangeExp = false;
                        foreach ($benchmark['gap_table']['rows'] as $gRowExp) {
                            if (($gRowExp['sample_note'] ?? null) === 'flesch_out_of_range') { $gapHasFleschOutOfRangeExp = true; break; }
                        }
                    @endphp
                    @if ($gapHasFleschOutOfRangeExp)
                        <p class="muted" style="margin-top: 8px; font-size: 11px; font-style: italic;">
                            {{ __('Some competitor pages in the SERP sample were not long-form articles (Flesch outside the 10–95 prose range) and were excluded from the readability average.') }}
                        </p>
                    @endif
                @endif
            </div>
        @endif
    @endif

    <p class="muted" style="text-align: center; margin-top: 24px;">{{ __('Generated by Serfix') }} · {{ format_user_now('M j, Y g:i A', $timezoneContextUser ?? null) }}</p>
</div>
</body>
</html>
