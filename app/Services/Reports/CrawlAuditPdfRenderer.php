<?php

namespace App\Services\Reports;

use App\Models\ReportBranding;
use App\Models\Website;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Builds the branded, exportable Site Audit PDF — the crawler's equivalent
 * of Semrush's "Site Audit: Issues" export, with the same Errors/Warnings/
 * Notices framing plus a "Start here" priority section and real affected-URL
 * samples Semrush's own static export doesn't include.
 *
 * Mirrors {@see ReportPdfRenderer} exactly (same dompdf facade, same branding
 * model, same A4-portrait setup) so the two PDF types stay visually
 * consistent and share the same dependency footprint.
 */
class CrawlAuditPdfRenderer
{
    /**
     * @param  array<string,mixed>  $audit  CrawlReportService::auditExport() payload
     */
    public function render(Website $website, ReportBranding $branding, array $audit): string
    {
        $pdf = Pdf::loadView('pdf.site-audit', [
            'website' => $website,
            'branding' => $branding,
            'audit' => $audit,
        ]);

        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption(['isRemoteEnabled' => true]);

        return (string) $pdf->output();
    }

    public function filenameFor(Website $website, ReportBranding $branding): string
    {
        $brand = preg_replace('/[^a-z0-9]+/i', '-', $branding->company_name) ?: 'Serfix';
        $domain = preg_replace('/[^a-z0-9]+/i', '-', $website->domain ?? 'site') ?: 'site';

        return strtolower(trim($brand, '-')).'-site-audit-'.$domain.'-'.now()->format('Ymd').'.pdf';
    }
}
