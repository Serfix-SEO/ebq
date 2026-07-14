<?php

namespace App\Services\Reports;

use App\Models\ReportBranding;
use App\Models\Website;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Renders the client backlink/authority report to a branded PDF from the same
 * payload + SVG partials the web share page uses (identical output). Mirrors
 * {@see ReportPdfRenderer}. dompdf's SVG support covers the <path> arc rings
 * and <rect> bars used by the report charts.
 */
class ClientReportPdfRenderer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function render(Website $website, ReportBranding $branding, array $payload, ?string $generatedAt = null): string
    {
        $pdf = Pdf::loadView('pdf.client-report', [
            'website' => $website,
            'branding' => $branding,
            'payload' => $payload,
            'generatedAt' => $generatedAt,
        ]);

        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption(['isRemoteEnabled' => true]);

        return (string) $pdf->output();
    }

    public function filenameFor(Website $website, ReportBranding $branding): string
    {
        $brand = preg_replace('/[^a-z0-9]+/i', '-', $branding->company_name) ?: 'Serfix';
        $domain = preg_replace('/[^a-z0-9]+/i', '-', $website->domain ?? 'site') ?: 'site';

        return strtolower(trim($brand, '-')).'-backlink-report-'.$domain.'.pdf';
    }
}
