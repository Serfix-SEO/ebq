@php
    $host = parse_url($audit->url, PHP_URL_HOST) ?: $audit->url;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your free SEO audit</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:#ffffff; border:1px solid #e2e8f0; border-radius:14px; overflow:hidden;">
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 4px; font-size:11px; font-weight:700; letter-spacing:0.12em; text-transform:uppercase; color:#F26419;">Free SEO audit</p>
                            <h1 style="margin:0; font-size:22px; line-height:1.3; color:#0f172a;">Your audit for {{ $host }} is ready</h1>
                            <p style="margin:14px 0 0; font-size:14px; line-height:1.6; color:#475569;">
                                We analyzed <strong style="color:#0f172a;">{{ $audit->url }}</strong> for the keyword
                                <strong style="color:#0f172a;">“{{ $audit->keyword }}”</strong> — on-page SEO, content, and how you stack up against the top-ranking competitors.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 8px;">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="border-radius:10px; background:#F26419;">
                                        <a href="{{ $resultsUrl }}" style="display:inline-block; padding:13px 26px; font-size:14px; font-weight:600; color:#ffffff; text-decoration:none; border-radius:10px;">View your audit report →</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:12px 0 0; font-size:12px; color:#94a3b8;">Or paste this link into your browser:<br>
                                <a href="{{ $resultsUrl }}" style="color:#F26419; word-break:break-all;">{{ $resultsUrl }}</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px 28px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FEF0E7; border:1px solid #FDE8DD; border-radius:12px;">
                                <tr>
                                    <td style="padding:18px 20px;">
                                        <p style="margin:0; font-size:14px; font-weight:700; color:#9F3D09;">Want the full picture — free?</p>
                                        <p style="margin:8px 0 14px; font-size:13px; line-height:1.6; color:#475569;">
                                            Connect Search Console + Analytics and Serfix turns this snapshot into continuous, site-wide tracking: your real keyword positions, click data, Core Web Vitals, and ranked fixes. The free plan covers one website — no credit card.
                                        </p>
                                        <a href="{{ $registerUrl }}" style="display:inline-block; padding:10px 20px; font-size:13px; font-weight:600; color:#ffffff; background:#0f172a; text-decoration:none; border-radius:8px;">Start free</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                <p style="margin:16px 0 0; font-size:11px; color:#94a3b8;">You’re receiving this because you requested a free SEO audit at Serfix.</p>
            </td>
        </tr>
    </table>
</body>
</html>
