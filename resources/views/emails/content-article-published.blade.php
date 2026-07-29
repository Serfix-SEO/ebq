@php
    $accent = $branding->accent_color ?? '#F26419';
    $score = $f['seo_score'];
    $scoreColor = $score === null ? '#94a3b8' : ($score >= 80 ? '#16a34a' : ($score >= 60 ? '#d97706' : '#dc2626'));
    $scoreLabel = $score === null ? __('Not scored') : ($score >= 80 ? __('Excellent') : ($score >= 60 ? __('Good') : __('Needs work')));
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1e293b; line-height: 1.6; margin: 0; padding: 0; background: #f1f5f9; }
        .container { max-width: 640px; margin: 0 auto; padding: 28px 14px; }
        .card { background: #ffffff; border-radius: 14px; overflow: hidden; margin-bottom: 14px; }
        .pad { padding: 28px; }
        .brand-header { text-align: left; padding: 20px 28px 0; }
        .brand-header img { max-height: 40px; max-width: 200px; display: block; }
        .brand-header .brand-name { font-size: 14px; font-weight: 700; color: #475569; margin: 0; }

        .live-pill { display: inline-block; background: #dcfce7; color: #15803d; font-size: 11px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; padding: 5px 12px; border-radius: 999px; }
        h1 { font-size: 23px; line-height: 1.35; margin: 14px 0 8px; font-weight: 800; color: #0f172a; }
        .sub { color: #64748b; font-size: 13px; margin: 0 0 20px; }
        .greeting { color: #475569; font-size: 14px; margin: 0 0 22px; }

        .hero img { width: 100%; max-width: 100%; display: block; }

        .btn { display: inline-block; background: {{ $accent }}; color: #ffffff !important; text-decoration: none; border-radius: 10px; padding: 13px 26px; font-size: 14px; font-weight: 700; }
        .btn-ghost { display: inline-block; background: #ffffff; color: #334155 !important; text-decoration: none; border: 1px solid #cbd5e1; border-radius: 10px; padding: 12px 22px; font-size: 14px; font-weight: 600; }

        .stat-grid { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 0 -6px 4px; table-layout: fixed; }
        .stat-grid td { background: #f8fafc; border: 1px solid #eef2f7; border-radius: 10px; padding: 12px 8px; text-align: center; vertical-align: top; }
        .stat-value { font-size: 19px; font-weight: 800; color: #0f172a; display: block; line-height: 1.2; }
        .stat-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.07em; color: #94a3b8; display: block; margin-top: 4px; }

        .section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #94a3b8; margin: 0 0 12px; }
        .divider { border: none; border-top: 1px solid #eef2f7; margin: 26px 0; }

        .serp { border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px 16px; }
        .serp-url { font-size: 12px; color: #0f766e; margin: 0 0 3px; word-break: break-all; }
        .serp-title { font-size: 16px; color: #1a0dab; margin: 0 0 4px; font-weight: 600; line-height: 1.35; }
        .serp-desc { font-size: 13px; color: #4d5156; margin: 0; }

        .kw { display: inline-block; background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; font-size: 12px; font-weight: 600; padding: 5px 11px; border-radius: 999px; margin: 0 5px 6px 0; }
        .kw-sec { background: #f8fafc; border-color: #e2e8f0; color: #475569; font-weight: 500; }

        .next-list { margin: 0; padding: 0; list-style: none; }
        .next-list li { font-size: 13px; color: #475569; padding: 9px 0 9px 24px; border-bottom: 1px solid #f1f5f9; position: relative; }
        .next-list li:last-child { border-bottom: none; }
        .next-list .tick { color: {{ $accent }}; font-weight: 800; }

        .footer { text-align: center; color: #94a3b8; font-size: 12px; margin-top: 18px; line-height: 1.7; }
        .footer a { color: #94a3b8; }
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

        @if ($f['featured_image'])
            <div class="hero"><img src="{{ $f['featured_image'] }}" alt="{{ $f['featured_alt'] }}"></div>
        @endif

        <div class="pad">
            <span class="live-pill">{{ __('Published') }}</span>
            <h1>{{ $f['h1'] }}</h1>
            <p class="sub">
                <strong>{{ $website->domain }}</strong>
                &nbsp;•&nbsp; {{ $publishedAt }}
                @if (! empty($f['platforms']))
                    &nbsp;•&nbsp; {{ implode(', ', $f['platforms']) }}
                @endif
            </p>

            <p class="greeting">
                {{ __('Hi :name, a new article just went live on your website. Here is everything that shipped with it.', ['name' => $user->name]) }}
            </p>

            @if ($f['live_url'])
                <p style="margin: 0 0 6px;">
                    <a href="{{ $f['live_url'] }}" class="btn">{{ __('Read the live article') }}</a>
                </p>
                <p style="margin: 12px 0 0;">
                    <a href="{{ $f['review_url'] }}" class="btn-ghost">{{ __('Open in :app', ['app' => $branding->company_name]) }}</a>
                </p>
            @else
                <p style="margin: 0;">
                    <a href="{{ $f['review_url'] }}" class="btn">{{ __('Open in :app', ['app' => $branding->company_name]) }}</a>
                </p>
            @endif
        </div>
    </div>

    {{-- ============ AT A GLANCE ============ --}}
    <div class="card">
        <div class="pad">
            <p class="section-title">{{ __('At a glance') }}</p>
            <table class="stat-grid" role="presentation">
                <tr>
                    <td>
                        <span class="stat-value" style="color: {{ $scoreColor }};">{{ $score ?? '—' }}</span>
                        <span class="stat-label">{{ __('SEO score') }}</span>
                    </td>
                    <td>
                        <span class="stat-value">{{ number_format($f['word_count']) }}</span>
                        <span class="stat-label">{{ __('Words') }}</span>
                    </td>
                    <td>
                        <span class="stat-value">{{ $f['read_minutes'] }}</span>
                        <span class="stat-label">{{ __('Min read') }}</span>
                    </td>
                    <td>
                        <span class="stat-value">{{ $f['section_count'] }}</span>
                        <span class="stat-label">{{ __('Sections') }}</span>
                    </td>
                    <td>
                        <span class="stat-value">{{ $f['image_count'] }}</span>
                        <span class="stat-label">{{ __('Images') }}</span>
                    </td>
                </tr>
            </table>
            @if ($score !== null)
                <p style="margin: 10px 0 0; font-size: 12px; color: #64748b;">
                    {{ __('On-page optimisation:') }}
                    <strong style="color: {{ $scoreColor }};">{{ $scoreLabel }}</strong>
                    ({{ $score }}/100)
                </p>
            @endif
        </div>
    </div>

    {{-- ============ TARGETING + SEARCH PREVIEW ============ --}}
    <div class="card">
        <div class="pad">
            <p class="section-title">{{ __('What it targets') }}</p>
            <p style="margin: 0 0 10px;">
                <span class="kw">{{ $f['target_keyword'] }}</span>
                @foreach ($f['secondary_keywords'] as $kw)
                    <span class="kw kw-sec">{{ $kw }}</span>
                @endforeach
            </p>
            @if ($f['keyword_volume'])
                <p style="margin: 0; font-size: 12px; color: #64748b;">
                    {{ __(':count monthly searches for the main keyword.', ['count' => number_format($f['keyword_volume'])]) }}
                </p>
            @endif

            @if ($f['live_url'] || $f['meta_description'])
                <hr class="divider">
                <p class="section-title">{{ __('How it looks in Google') }}</p>
                <div class="serp">
                    <p class="serp-url">{{ $f['display_url'] ?? $website->domain }}</p>
                    <p class="serp-title">{{ \Illuminate\Support\Str::limit($f['title'], 65) }}</p>
                    <p class="serp-desc">{{ \Illuminate\Support\Str::limit($f['meta_description'], 160) }}</p>
                </div>
            @endif
        </div>
    </div>

    {{-- ============ WHAT HAPPENS NEXT ============ --}}
    <div class="card">
        <div class="pad">
            <p class="section-title">{{ __('What happens next') }}</p>
            <ul class="next-list">
                @if ($f['indexing_submitted'])
                    <li><span class="tick">✓</span>&nbsp; {{ __('The URL was submitted to Google for indexing.') }}</li>
                @else
                    <li><span class="tick">•</span>&nbsp; {{ __('Connect Google Search Console to have new URLs submitted for indexing automatically.') }}</li>
                @endif
                <li><span class="tick">✓</span>&nbsp; {{ __('Its keywords were added to your tracker — rankings start appearing within a few days.') }}</li>
                <li><span class="tick">✓</span>&nbsp; {{ __('Clicks and impressions for this page will show up on the article once Search Console reports them.') }}</li>
                <li><span class="tick">•</span>&nbsp; {{ __('Your next article is already scheduled on the calendar.') }}</li>
            </ul>
        </div>
    </div>

    <p class="footer">
        {{ __('You are receiving this because automatic publishing is on for :domain.', ['domain' => $website->domain]) }}<br>
        {{ $branding->company_name }}
    </p>
</div>
</body>
</html>
