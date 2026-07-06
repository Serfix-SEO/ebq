{{-- PDF / print export: live SERP organic row vs audited HTML title + meta. --}}
@php
    $hasSerpListing = ! empty($ys['matched_listing_url']);
    $hasAudited = ! empty($ys['audited_page_url'] ?? null);
@endphp
@if ($hasSerpListing || $hasAudited)
    <table style="width: 100%; margin-top: 14px; border-collapse: separate; border-spacing: 12px 0;">
        <tr>
            <td style="width: 50%; vertical-align: top; padding: 12px 14px; border-radius: 10px; border: 1px solid #e2e8f0; background: #ffffff;">
                <p style="margin: 0; font-size: 9px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: #64748b;">SERP sample (organic listing)</p>
                <p style="margin: 6px 0 0; font-size: 10px; line-height: 1.45; color: #64748b;">From live search results for the benchmark keyword.</p>
                @if ($hasSerpListing)
                    @if (! empty($ys['matched_listing_display']))
                        <p style="margin: 10px 0 0; font-size: 12px; line-height: 1.4; color: #065f46;">{{ $ys['matched_listing_display'] }}</p>
                    @endif
                    <p style="margin: 8px 0 0;">
                        <a href="{{ $ys['matched_listing_url'] }}" style="font-size: 15px; font-weight: 600; color: #1d4ed8; text-decoration: none;">{{ $ys['matched_listing_title'] ?? \Illuminate\Support\Str::limit($ys['matched_listing_url'], 72) }}</a>
                    </p>
                    @if (! empty($ys['matched_listing_snippet']))
                        <p style="margin: 8px 0 0; font-size: 13px; line-height: 1.5; color: #475569;">{{ $ys['matched_listing_snippet'] }}</p>
                    @endif
                    <p style="margin: 10px 0 0; font-family: ui-monospace, monospace; font-size: 11px; word-break: break-all; color: #64748b;">{{ $ys['matched_listing_url'] }}</p>
                @else
                    <p style="margin: 12px 0 0; font-size: 12px; color: #64748b;">No organic row matched your site’s domain in this snapshot.</p>
                @endif
            </td>
            <td style="width: 50%; vertical-align: top; padding: 12px 14px; border-radius: 10px; border: 1px solid #FBD1BB; background: #FEF0E7;">
                <p style="margin: 0; font-size: 9px; font-weight: 800; letter-spacing: 0.12em; text-transform: uppercase; color: #9F3D09;">Your page (audit snapshot)</p>
                <p style="margin: 6px 0 0; font-size: 10px; line-height: 1.45; color: #475569;">Title and meta description from fetched HTML.</p>
                @if ($hasAudited)
                    @if (! empty($ys['audited_page_display']))
                        <p style="margin: 10px 0 0; font-size: 12px; line-height: 1.4; color: #14532d;">{{ $ys['audited_page_display'] }}</p>
                    @endif
                    <p style="margin: 8px 0 0; font-size: 15px; font-weight: 600; line-height: 1.35; color: #0f172a;">{{ $ys['audited_page_title'] ?? \Illuminate\Support\Str::limit($ys['audited_page_url'], 72) }}</p>
                    @if (! empty($ys['audited_page_snippet']))
                        <p style="margin: 8px 0 0; font-size: 13px; line-height: 1.5; color: #475569;">{{ $ys['audited_page_snippet'] }}</p>
                    @else
                        <p style="margin: 8px 0 0; font-size: 13px; font-style: italic; color: #64748b;">No meta description in HTML.</p>
                    @endif
                    <p style="margin: 10px 0 0; font-family: ui-monospace, monospace; font-size: 11px; word-break: break-all; color: #64748b;">{{ $ys['audited_page_url'] }}</p>
                @else
                    <p style="margin: 12px 0 0; font-size: 12px; color: #64748b;">No on-page snapshot stored (re-run the audit).</p>
                @endif
            </td>
        </tr>
    </table>
@endif
