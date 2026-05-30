<?php

namespace App\Services\Reports;

use App\Models\ReportBranding;
use App\Models\User;
use App\Models\Website;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Builds the branded PDF attachment for a growth report email.
 *
 * Uses the {@see Pdf} facade from barryvdh/laravel-dompdf — a required
 * dependency in composer.json. Note: Cashier only pulls in the core
 * dompdf/dompdf lib (for invoices); the Laravel facade wrapper is NOT
 * transitive, so barryvdh/laravel-dompdf must be present in composer.lock
 * and installed, or this class throws "Class ...Facade\Pdf not found".
 * The print template at `emails/growth-report-pdf.blade.php` mirrors the
 * HTML email's content but with print-friendly CSS (no flex, no @media).
 *
 * Output is raw PDF bytes — the caller attaches via
 * `Attachment::fromData(fn () => $pdfBytes, $filename)`.
 */
class ReportPdfRenderer
{
    /**
     * @param  array<string, mixed>  $report     ReportDataService payload
     * @param  array<string, mixed>  $insights   sliced insight arrays
     */
    public function render(
        User $user,
        Website $website,
        ReportBranding $branding,
        string $startDate,
        string $endDate,
        string $reportType,
        array $report,
        array $insights,
    ): string {
        $pdf = Pdf::loadView('emails.growth-report-pdf', [
            'user' => $user,
            'website' => $website,
            'branding' => $branding,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'reportType' => $reportType,
            'report' => $report,
            'insights' => $insights,
        ]);

        // A4 portrait matches the layout's column widths; landscape would
        // require a different stylesheet. Set the chroot to the public
        // disk so the logo <img src="..."> can resolve.
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOption(['isRemoteEnabled' => true]);

        return (string) $pdf->output();
    }

    /**
     * Filename to attach the PDF as. Reads from branding for the
     * company-name prefix; falls back to "EBQ" when the default
     * branding is in effect.
     */
    public function filenameFor(Website $website, ReportBranding $branding, string $endDate): string
    {
        $brand = preg_replace('/[^a-z0-9]+/i', '-', $branding->company_name) ?: 'EBQ';
        $domain = preg_replace('/[^a-z0-9]+/i', '-', $website->domain ?? 'site') ?: 'site';
        return strtolower(trim($brand, '-')) . '-report-' . $domain . '-' . $endDate . '.pdf';
    }
}
