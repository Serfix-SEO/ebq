@php
    $counts = $report['counts'] ?? [];
    $breakdown = $report['breakdown'] ?? [];
    $health = $report['health_score'] ?? null;
    $catColor = ['critical' => '#dc2626', 'high' => '#ea580c', 'growth' => '#0ea5e9'];
    $traffic = $report['traffic'] ?? null;
    $dashboardUrl = $report['dashboard_url'] ?? url('/dashboard');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('SEO crawl report for :domain', ['domain' => $website->domain]) }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 4px; font-size:11px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:#F26419;">{{ __('SEO crawl report') }}</p>
                            <h1 style="margin:0; font-size:22px; line-height:1.3; color:#0f172a;">{{ $website->domain }}</h1>
                            <p style="margin:14px 0 0; font-size:14px; line-height:1.6; color:#475569;">
                                @if ($recipientName){{ __('Hi :name,', ['name' => $recipientName]) }}@endif
                                {{ __('We crawled') }} <strong style="color:#0f172a;">{{ $website->domain }}</strong> {{ __('and found') }}
                                <strong style="color:#0f172a;">{{ (int) ($counts['total'] ?? 0) }}</strong> {{ __('issues worth your attention.') }}
                            </p>
                        </td>
                    </tr>

                    {{-- Traffic (last 28 days) — only when GSC/GA is connected --}}
                    @if ($traffic)
                    <tr>
                        <td style="padding:18px 32px 0;">
                            <p style="margin:0 0 8px; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:#64748b;">{{ __('Traffic · last :period', ['period' => $traffic['period_label'] ?? __('28 days')]) }}</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                                <tr>
                                    @isset($traffic['gsc'])
                                        @php $g = $traffic['gsc']; $cp = $g['clicks_change_percent'] ?? null; $dir = $g['clicks_direction'] ?? 'flat'; @endphp
                                        <td align="center" style="padding:16px 8px; border-right:1px solid #e2e8f0;">
                                            <div style="font-size:22px; font-weight:700; color:#0f172a;">{{ number_format((int) $g['clicks']) }}</div>
                                            <div style="font-size:11px; color:#64748b;">{{ __('Clicks') }} @if ($cp !== null)<span style="color:{{ $dir === 'down' ? '#dc2626' : '#16a34a' }};">{{ $dir === 'down' ? '▼' : '▲' }} {{ abs($cp) }}%</span>@endif</div>
                                        </td>
                                        <td align="center" style="padding:16px 8px; border-right:1px solid #e2e8f0;">
                                            <div style="font-size:22px; font-weight:700; color:#0f172a;">{{ number_format((int) $g['impressions']) }}</div>
                                            <div style="font-size:11px; color:#64748b;">{{ __('Impressions') }}</div>
                                        </td>
                                        <td align="center" style="padding:16px 8px; @isset($traffic['ga'])border-right:1px solid #e2e8f0;@endisset">
                                            <div style="font-size:22px; font-weight:700; color:#0f172a;">{{ $g['position'] ? number_format((float) $g['position'], 1) : '—' }}</div>
                                            <div style="font-size:11px; color:#64748b;">{{ __('Avg position') }}</div>
                                        </td>
                                    @endisset
                                    @isset($traffic['ga'])
                                        @php $a = $traffic['ga']; @endphp
                                        <td align="center" style="padding:16px 8px; border-right:1px solid #e2e8f0;">
                                            <div style="font-size:22px; font-weight:700; color:#0f172a;">{{ number_format((int) $a['users']) }}</div>
                                            <div style="font-size:11px; color:#64748b;">{{ __('Users') }}</div>
                                        </td>
                                        <td align="center" style="padding:16px 8px;">
                                            <div style="font-size:22px; font-weight:700; color:#0f172a;">{{ number_format((int) $a['sessions']) }}</div>
                                            <div style="font-size:11px; color:#64748b;">{{ __('Sessions') }}</div>
                                        </td>
                                    @endisset
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif

                    {{-- Headline crawl numbers --}}
                    <tr>
                        <td style="padding:18px 32px 4px;">
                            <p style="margin:0 0 8px; font-size:11px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; color:#64748b;">{{ __('Site health') }}</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                                <tr>
                                    @if ($health !== null)
                                    <td align="center" style="padding:16px 8px; border-right:1px solid #e2e8f0;">
                                        <div style="font-size:24px; font-weight:700; color:#0f172a;">{{ $health }}</div>
                                        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.06em;">{{ __('Health') }}</div>
                                    </td>
                                    @endif
                                    <td align="center" style="padding:16px 8px; border-right:1px solid #e2e8f0;">
                                        <div style="font-size:24px; font-weight:700; color:#dc2626;">{{ (int) ($counts['critical'] ?? 0) }}</div>
                                        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.06em;">{{ __('Critical') }}</div>
                                    </td>
                                    <td align="center" style="padding:16px 8px; border-right:1px solid #e2e8f0;">
                                        <div style="font-size:24px; font-weight:700; color:#ea580c;">{{ (int) ($counts['high'] ?? 0) }}</div>
                                        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.06em;">{{ __('High') }}</div>
                                    </td>
                                    <td align="center" style="padding:16px 8px; border-right:1px solid #e2e8f0;">
                                        <div style="font-size:24px; font-weight:700; color:#d97706;">{{ (int) ($counts['medium'] ?? 0) }}</div>
                                        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.06em;">{{ __('Medium') }}</div>
                                    </td>
                                    <td align="center" style="padding:16px 8px;">
                                        <div style="font-size:24px; font-weight:700; color:#64748b;">{{ (int) ($counts['low'] ?? 0) }}</div>
                                        <div style="font-size:11px; color:#64748b; text-transform:uppercase; letter-spacing:0.06em;">{{ __('Low') }}</div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- All issues, grouped by category (incl. growth-tier groups) --}}
                    @if ($breakdown !== [])
                    <tr>
                        <td style="padding:20px 32px 4px;">
                            <p style="margin:0 0 12px; font-size:13px; font-weight:700; color:#0f172a;">{{ __('All issues found') }}</p>
                            @foreach ($breakdown as $cat)
                                @php $accent = $catColor[$cat['severity']] ?? '#64748b'; $shown = count($cat['examples']); @endphp
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 12px; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden;">
                                    <tr>
                                        <td style="padding:9px 12px; background:#f8fafc; border-bottom:1px solid #eef2f7;">
                                            <span style="display:inline-block; width:8px; height:8px; border-radius:999px; background:{{ $accent }};"></span>
                                            <span style="font-size:13px; font-weight:700; color:#0f172a;">{{ $cat['title'] }}</span>
                                            <span style="float:right; font-size:12px; font-weight:700; color:{{ $accent }};">{{ number_format((int) $cat['count']) }}</span>
                                        </td>
                                    </tr>
                                    @foreach ($cat['examples'] as $ex)
                                        <tr>
                                            <td style="padding:8px 12px; border-bottom:1px solid #f1f5f9;">
                                                <div style="font-size:12px; color:#0f172a;">{{ $ex['description'] ?? '' }}</div>
                                                <div style="font-size:12px; color:#64748b; word-break:break-all;">{{ $ex['url'] ?? $ex['label'] ?? '' }}</div>
                                            </td>
                                        </tr>
                                    @endforeach
                                    @if ((int) $cat['count'] > $shown)
                                        <tr>
                                            <td style="padding:7px 12px; font-size:11px; color:#94a3b8;">{{ __('+ more like this — see all in your dashboard') }}</td>
                                        </tr>
                                    @endif
                                </table>
                            @endforeach
                        </td>
                    </tr>
                    @endif

                    {{-- CTA --}}
                    <tr>
                        <td style="padding:20px 32px 28px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius:10px; background:#F26419;">
                                        <a href="{{ $dashboardUrl }}" style="display:inline-block; padding:13px 26px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:10px;">{{ __('Open your dashboard →') }}</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:14px 0 0; font-size:12px; color:#94a3b8;">{{ __('See every issue, where it is, and how to fix it inside :app.', ['app' => config('app.name')]) }}</p>
                        </td>
                    </tr>
                </table>
                <p style="margin:16px 0 0; font-size:11px; color:#94a3b8;">{{ __('You’re receiving this because you have an SEO account for :domain with :app.', ['domain' => $website->domain, 'app' => config('app.name')]) }}</p>
            </td>
        </tr>
    </table>
</body>
</html>
