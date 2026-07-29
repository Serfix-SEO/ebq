@php
    $accent = $branding->accent_color ?? '#F26419';
    $labels = [
        'number_one' => __('Now ranking #1'),
        'top_3' => __('Broke into the top 3'),
        'page_1' => __('Reached page 1'),
        'now_ranking' => __('Ranking for the first time'),
    ];
    $count = count($movements);
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1e293b; line-height: 1.6; margin: 0; padding: 0; background: #f1f5f9; }
        .container { max-width: 620px; margin: 0 auto; padding: 28px 14px; }
        .card { background: #ffffff; border-radius: 14px; margin-bottom: 14px; }
        .pad { padding: 28px; }
        .brand-header { text-align: left; padding: 20px 28px 0; }
        .brand-header img { max-height: 40px; max-width: 200px; display: block; }
        .brand-header .brand-name { font-size: 14px; font-weight: 700; color: #475569; margin: 0; }

        .up-pill { display: inline-block; background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; padding: 5px 12px; border-radius: 999px; }
        h1 { font-size: 22px; line-height: 1.35; margin: 14px 0 6px; font-weight: 800; color: #0f172a; }
        .sub { color: #64748b; font-size: 13px; margin: 0 0 22px; }

        /* hero move */
        .hero { border: 1px solid #d1fae5; background: #f0fdf4; border-radius: 12px; padding: 18px 20px; }
        .hero-kw { font-size: 15px; font-weight: 700; color: #0f172a; margin: 0 0 12px; }
        .jump { width: 100%; border-collapse: collapse; }
        .jump td { vertical-align: middle; }
        .pos-old { font-size: 26px; font-weight: 800; color: #94a3b8; text-decoration: line-through; }
        .pos-arrow { font-size: 20px; color: #16a34a; padding: 0 12px; }
        .pos-new { font-size: 34px; font-weight: 800; color: #15803d; }
        .gain { font-size: 13px; font-weight: 700; color: #15803d; }
        .milestone { display: inline-block; margin-top: 10px; background: #15803d; color: #fff; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; padding: 4px 10px; border-radius: 999px; }

        .row { width: 100%; border-collapse: collapse; }
        .row td { padding: 12px 0; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .row tr:last-child td { border-bottom: none; }
        .kw { font-size: 14px; font-weight: 600; color: #0f172a; margin: 0; }
        .kw a { color: #0f172a; text-decoration: none; }
        .tag { display: inline-block; margin-top: 3px; background: #f0fdf4; color: #15803d; border: 1px solid #d1fae5; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; padding: 2px 7px; border-radius: 999px; }
        .move { text-align: right; white-space: nowrap; font-size: 14px; }
        .move .from { color: #94a3b8; }
        .move .to { font-weight: 800; color: #0f172a; }
        .move .delta { display: block; font-size: 11px; font-weight: 700; color: #16a34a; }

        .section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #94a3b8; margin: 0 0 6px; }
        .btn { display: inline-block; background: {{ $accent }}; color: #ffffff !important; text-decoration: none; border-radius: 10px; padding: 13px 26px; font-size: 14px; font-weight: 700; }
        .note { font-size: 12px; color: #94a3b8; margin: 18px 0 0; }
        .footer { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 18px; line-height: 1.7; }
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

        <div class="pad">
            <span class="up-pill">{{ __('Ranking up') }}</span>
            <h1>
                {{ trans_choice('{1}A keyword just moved up on :domain|[2,*]:count keywords just moved up on :domain', $count, ['count' => $count, 'domain' => $website->domain]) }}
            </h1>
            <p class="sub">{{ __('Hi :name, here is what changed in Google since the last check.', ['name' => $user->name]) }}</p>

            {{-- Hero: the biggest win --}}
            @if (! empty($top))
                <div class="hero">
                    <p class="hero-kw">{{ $top['keyword'] }}</p>
                    <table class="jump" role="presentation">
                        <tr>
                            {{-- nowrap: the "#9 → #2" jump must stay on one line --}}
                            <td style="white-space: nowrap;">
                                @if ($top['previous'] !== null)
                                    <span class="pos-old">#{{ $top['previous'] }}</span><span class="pos-arrow">&rarr;</span>
                                @endif
                                <span class="pos-new">#{{ $top['current'] }}</span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                @if (! empty($top['gain']))
                                    <span class="gain">{{ trans_choice('{1}Up :count place|[2,*]Up :count places', $top['gain'], ['count' => $top['gain']]) }}</span>
                                @endif
                            </td>
                        </tr>
                    </table>
                    @if (! empty($top['milestone']) && isset($labels[$top['milestone']]))
                        <span class="milestone">{{ $labels[$top['milestone']] }}</span>
                    @endif
                </div>
            @endif

            {{-- The rest --}}
            @if ($count > 1)
                <p class="section-title" style="margin-top: 26px;">{{ __('Also moved up') }}</p>
                <table class="row" role="presentation">
                    @foreach (array_slice($movements, 1, 12) as $m)
                        <tr>
                            <td>
                                <p class="kw"><a href="{{ route('content.keyword-history', $m['keyword_id']) }}">{{ $m['keyword'] }}</a></p>
                                @if (! empty($m['milestone']) && isset($labels[$m['milestone']]))
                                    <span class="tag">{{ $labels[$m['milestone']] }}</span>
                                @endif
                            </td>
                            <td class="move">
                                @if ($m['previous'] !== null)
                                    <span class="from">#{{ $m['previous'] }} &rarr;</span>
                                @endif
                                <span class="to">#{{ $m['current'] }}</span>
                                @if (! empty($m['gain']))
                                    <span class="delta">&#9650; {{ $m['gain'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
                @if ($count > 13)
                    <p class="note">{{ __('+ :count more in your tracker.', ['count' => $count - 13]) }}</p>
                @endif
            @endif

            <p style="margin: 26px 0 0;">
                <a href="{{ $trackerUrl }}" class="btn">{{ __('See the full tracker') }}</a>
            </p>
            <p class="note">
                {{ __('Positions come from a live Google check run every week. Open any keyword to see its full history.') }}
            </p>
        </div>
    </div>

    <p class="footer">
        {{ __('You are receiving this because :domain is tracking keywords with :app.', ['domain' => $website->domain, 'app' => $branding->company_name]) }}
    </p>
</div>
</body>
</html>
