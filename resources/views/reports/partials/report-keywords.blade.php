{{-- Keyword sections of the report — included from web-body at ONE of two
     slots: above the competitors table for new sites (partial reports, where
     keywords are the headline value) and below it for established sites.
     Inherits web-body scope: $p, $sources, $opportunitySource, $fmt, $dash,
     plus optional $gapUrl (keyword-gap tool link, own-site context only). --}}
@php
    $kwSource = $sources['keywords'] ?? null;
    $kwIsGsc = $kwSource === 'gsc';
    $gapUrl = $gapUrl ?? null;
@endphp

@if (! empty($p['keywords']))
    @include('reports.partials.web-table', [
        'title' => $kwIsGsc ? 'Keywords this site ranks for' : 'Keywords this site can rank for',
        'badge' => $kwIsGsc ? 'From Google Search Console' : ($kwSource === 'estimated' ? 'Estimated' : null),
        'head' => $kwIsGsc
            ? ['Keyword', 'Clicks (30d)', 'Impressions', 'Avg position']
            : ['Keyword', 'Monthly searches', 'CPC', 'Competition'],
        'scroll' => true,
        'rows' => collect($p['keywords'])->map(fn ($row) => $kwIsGsc
            ? [
                ['text' => $row['keyword'] ?? ''],
                ['text' => $fmt($row['clicks'] ?? null), 'right' => true],
                ['text' => $fmt($row['impressions'] ?? null), 'right' => true],
                ['text' => $dash($row['position'] ?? null), 'right' => true],
            ]
            : [
                ['text' => $row['keyword'] ?? ''],
                ['text' => $fmt($row['volume'] ?? null), 'right' => true],
                ['text' => ($row['cpc'] ?? null) !== null ? '$'.number_format((float) $row['cpc'], 2) : '—', 'right' => true],
                ['text' => $dash($row['competition'] ?? null), 'right' => true],
            ])->all(),
    ])
    @include('reports.partials.gap-cta', ['gapUrl' => $gapUrl, 'gapText' => 'Compare these keywords against competitors — run a Keyword Gap analysis'])
@endif

@if (! empty($p['keyword_opportunities']))
    @include('reports.partials.web-table', [
        'title' => 'Keyword opportunities',
        'badge' => $opportunitySource ? 'From a similar site: '.$opportunitySource : 'From similar sites',
        'head' => ['Keyword', 'Monthly searches', 'CPC', 'Competition'],
        'scroll' => true,
        'rows' => collect($p['keyword_opportunities'])->map(fn ($row) => [
            ['text' => $row['keyword'] ?? ''],
            ['text' => $fmt($row['volume'] ?? null), 'right' => true],
            ['text' => ($row['cpc'] ?? null) !== null ? '$'.number_format((float) $row['cpc'], 2) : '—', 'right' => true],
            ['text' => $dash($row['competition'] ?? null), 'right' => true],
        ])->all(),
    ])
    @include('reports.partials.gap-cta', ['gapUrl' => $gapUrl, 'gapText' => 'See the full missing / weak / strength breakdown in the Keyword Gap tool'])
@endif
