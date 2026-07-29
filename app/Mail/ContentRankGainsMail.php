<?php

namespace App\Mail;

use App\Models\ReportBranding;
use App\Models\User;
use App\Models\Website;
use App\Services\Reports\ReportBrandingResolver;
use App\Support\LocaleConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * "Your rankings moved up" — one digest per website per rank-check run, never
 * one email per keyword (a weekly check moves many keywords at once).
 *
 * The movements are passed in already filtered by
 * ContentSerpChecker::notableGain(), so this class only presents them: biggest
 * mover first, milestones (page 1 / top 3 / #1 / first time ranking) called out.
 */
class ContentRankGainsMail extends Mailable
{
    use Queueable, SerializesModels {
        getSerializedPropertyValue as traitGetSerializedPropertyValue;
    }

    /**
     * ReportBranding::ebqDefault() is an unsaved model — the SerializesModels
     * identifier round-trip would throw ModelNotFound on the worker (the bug
     * that silently killed default-branded growth reports).
     */
    protected function getSerializedPropertyValue($value, $withRelations = true)
    {
        if ($value instanceof ReportBranding && ! $value->exists) {
            return $value;
        }

        return $this->traitGetSerializedPropertyValue($value, $withRelations);
    }

    public ReportBranding $branding;

    /** @var list<array<string,mixed>> */
    public array $movements;

    /**
     * @param  list<array{keyword_id:string,keyword:string,previous:?int,current:int,gain:?int,milestone:?string}>  $movements
     */
    public function __construct(
        public User $user,
        public Website $website,
        array $movements,
        ?ReportBranding $branding = null,
    ) {
        $this->branding = $branding
            ?? app(ReportBrandingResolver::class)->for($website->owner ?? $user, $website);

        $this->locale(LocaleConfig::resolve($user->locale));

        // Biggest win first: milestones outrank a plain climb, then places gained.
        usort($movements, function (array $a, array $b) {
            $rank = fn (array $m) => match ($m['milestone'] ?? null) {
                'number_one' => 0, 'top_3' => 1, 'page_1' => 2, 'now_ranking' => 3, default => 4,
            };

            return [$rank($a), -($a['gain'] ?? 0)] <=> [$rank($b), -($b['gain'] ?? 0)];
        });

        $this->movements = array_values($movements);
    }

    /** The headline move — drives both the subject line and the hero card. */
    public function headline(): array
    {
        return $this->movements[0] ?? [];
    }

    public function envelope(): Envelope
    {
        $top = $this->headline();
        $count = count($this->movements);

        $subject = $count > 1
            ? __(':count keywords moved up on :domain — ":keyword" is now #:position', [
                'count' => $count,
                'domain' => $this->website->domain,
                'keyword' => Str::limit((string) ($top['keyword'] ?? ''), 40),
                'position' => $top['current'] ?? '',
            ])
            : __('":keyword" moved up to #:position on :domain', [
                'keyword' => Str::limit((string) ($top['keyword'] ?? ''), 45),
                'position' => $top['current'] ?? '',
                'domain' => $this->website->domain,
            ]);

        return new Envelope(
            subject: $subject,
            replyTo: $this->branding->reply_to_email
                ? [new Address($this->branding->reply_to_email)]
                : [],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'X-EBQ-Rank-Gains-Website-Id' => (string) $this->website->id,
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.content-rank-gains',
            with: [
                'movements' => $this->movements,
                'top' => $this->headline(),
                'trackerUrl' => route('content.tracker'),
            ],
        );
    }
}
