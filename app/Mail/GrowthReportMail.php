<?php

namespace App\Mail;

use App\Models\ReportBranding;
use App\Models\User;
use App\Models\Website;
use App\Services\ReportDataService;
use App\Services\Reports\ReportBrandingResolver;
use App\Services\Reports\ReportPdfRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class GrowthReportMail extends Mailable
{
    use Queueable, SerializesModels {
        getSerializedPropertyValue as traitGetSerializedPropertyValue;
    }

    /**
     * SerializesModels turns every Eloquent property into a
     * ModelIdentifier and re-fetches it (`firstOrFail`) on the queue
     * worker. `ReportBranding::ebqDefault()` — the branding every
     * NON-whitelabel recipient gets — is an in-memory, never-persisted
     * model (`exists = false`, id null), so that re-fetch threw
     * `ModelNotFoundException` and every default-branded growth report
     * email silently failed from 2026-06-18 until this fix. Serialize
     * the unsaved default whole (it's a plain attribute bag); persisted
     * branding rows keep the normal identifier round-trip.
     */
    protected function getSerializedPropertyValue($value, $withRelations = true)
    {
        if ($value instanceof ReportBranding && ! $value->exists) {
            return $value;
        }

        return $this->traitGetSerializedPropertyValue($value, $withRelations);
    }

    public string $startDate;

    public string $endDate;

    public string $reportType;

    public array $report = [];

    public array $insights = [];

    public ReportBranding $branding;

    public function __construct(
        public User $user,
        public Website $website,
        string $startDate,
        string $endDate,
        string $reportType = 'daily',
        ?ReportBranding $branding = null,
    ) {
        // Callers must pre-resolve the report window via
        // ReportDataService::lastSafeReportDate(). The Mailable
        // does not retry that lookup here: throwing from a queued
        // mailable's constructor aborts the surrounding chunkById
        // batch on the dispatcher, and silently substituting a
        // fallback date would mask sync failures.
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->reportType = $reportType;

        // Branding is normally resolved by ReportMailDispatcher and
        // passed in. We accept null + auto-resolve here too so legacy
        // call sites (`new GrowthReportMail(...)` without going through
        // the dispatcher) still work — they just get the right branding
        // applied based on the report owner's plan.
        $this->branding = $branding
            ?? app(ReportBrandingResolver::class)->for($website->owner ?? $user, $website);

        $this->locale($user->locale ?? app()->getLocale());

        $service = app(ReportDataService::class);
        $this->report = $service->generate(
            $this->website->id,
            $this->startDate,
            $this->endDate,
        );

        $this->insights = [
            'cannibalization' => array_slice($service->cannibalizationReport($this->website->id), 0, 5),
            'striking_distance' => array_slice($service->strikingDistance($this->website->id), 0, 5),
            'indexing_fails_with_traffic' => array_slice($service->indexingFailsWithTraffic($this->website->id), 0, 5),
        ];
    }

    public function envelope(): Envelope
    {
        $typeLabel = ucfirst($this->reportType);
        $tz = $this->user->timezoneForDisplay();
        $start = Carbon::parse($this->startDate, $tz);
        $end = Carbon::parse($this->endDate, $tz);

        $dateStr = $start->eq($end)
            ? $start->format('M j, Y')
            : $start->format('M j').' - '.$end->format('M j, Y');

        return new Envelope(
            // Branding's company_name swaps in for the hardcoded "Serfix" so
            // recipients see the agency's brand in their inbox preview.
            // ReportBranding::ebqDefault() returns "Serfix" so the default
            // path is byte-identical to the pre-whitelabel behavior.
            subject: "{$this->branding->company_name} {$typeLabel} Report — {$this->website->domain} ({$dateStr})",
            replyTo: $this->branding->reply_to_email
                ? [new \Illuminate\Mail\Mailables\Address($this->branding->reply_to_email)]
                : [],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-EBQ-Growth-Report-User-Id' => (string) $this->user->id,
                'X-EBQ-Growth-Report-Website-Id' => (string) $this->website->id,
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.growth-report',
            with: [
                'branding' => $this->branding,
            ],
        );
    }

    /**
     * PDF attachment — always included on report emails, branded or not.
     * When the plan disables whitelabel, the PDF renders with the Serfix
     * default branding (no logo, Serfix accent color) so the recipient
     * still gets a saveable / shareable artifact.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $renderer = app(ReportPdfRenderer::class);
        $bytes = $renderer->render(
            user: $this->user,
            website: $this->website,
            branding: $this->branding,
            startDate: $this->startDate,
            endDate: $this->endDate,
            reportType: $this->reportType,
            report: $this->report,
            insights: $this->insights,
        );
        $filename = $renderer->filenameFor($this->website, $this->branding, $this->endDate);

        return [
            Attachment::fromData(fn () => $bytes, $filename)->withMime('application/pdf'),
        ];
    }
}
