<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Website;
use App\Support\LifecycleEmailConfig;
use App\Support\LocaleConfig;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * One parameterized mailable for all 8 lifecycle emails (4 segments ×
 * initial/follow-up) — they share one shape: greeting, a few short
 * paragraphs, at most one CTA button, "Fuaad from SERFIX" sign-off.
 * The view branches on segment/stage; copy lives in lang keys.
 *
 * Deliberately NOT queued: the daily command sends synchronously so its
 * stamp-after-send bookkeeping (lifecycle_email_sends) stays truthful.
 */
class LifecycleMail extends Mailable
{
    public function __construct(
        public User $user,
        public int $segment,
        public string $stage,          // LifecycleEmailSend::STAGE_*
        public ?Website $website = null,
        public ?string $unsubscribeUrl = null,
    ) {
        $this->locale(LocaleConfig::resolve($user->locale));
    }

    public function subjectLine(): string
    {
        return match ([$this->segment, $this->stage]) {
            [1, 'initial'] => __('Quick question about your SERFIX experience'),
            [1, 'followup'] => __('If SERFIX could handle one thing for you…'),
            [2, 'initial'] => __('Ready to see what SERFIX fixes?'),
            [2, 'followup'] => __('Quick question, and no survey attached 😅'),
            [3, 'initial'] => __('Your content plan is almost ready'),
            [3, 'followup'] => __('Did something get in the way?'),
            [4, 'initial'] => __('Ready to connect SERFIX to your website?'),
            [4, 'followup'] => __('What are you using to manage your website?'),
            default => throw new \InvalidArgumentException("Unknown lifecycle email {$this->segment}/{$this->stage}"),
        };
    }

    /**
     * CTA deep-link. `ebq_site` pins the session to the right website via the
     * ApplyWebsiteHint middleware and survives the login redirect. Null when
     * the email is a pure reply-ask (segment 1, all follow-ups) or when the
     * content routes aren't registered (CONTENT_AUTOPILOT_UI off).
     */
    public function ctaUrl(): ?string
    {
        if ($this->stage !== 'initial' || ! Route::has('content.get-started')) {
            return null;
        }

        $pin = $this->website?->normalized_domain
            ? '?ebq_site='.urlencode($this->website->normalized_domain)
            : '';

        return match ($this->segment) {
            2 => route('content.get-started'),
            3 => route('content.index').$pin,
            4 => route('content.integrations').$pin,
            default => null,
        };
    }

    public function ctaLabel(): ?string
    {
        return match ([$this->segment, $this->stage]) {
            [2, 'initial'] => __('Add My Website'),
            [3, 'initial'] => __('Continue My Strategy'),
            [4, 'initial'] => __('Connect My Website'),
            default => null,
        };
    }

    public function firstName(): string
    {
        return Str::of((string) $this->user->name)->trim()->explode(' ')->first() ?: __('there');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine(),
            replyTo: [new Address(LifecycleEmailConfig::REPLY_TO_ADDRESS, LifecycleEmailConfig::REPLY_TO_NAME)],
        );
    }

    public function headers(): Headers
    {
        $text = [
            'X-EBQ-Lifecycle-Segment' => (string) $this->segment,
            'X-EBQ-Lifecycle-Stage' => $this->stage,
        ];

        if ($this->unsubscribeUrl !== null) {
            // RFC 8058 one-click: providers POST to the URL with no cookies,
            // so the route is signed + CSRF-exempt.
            $text['List-Unsubscribe'] = '<'.$this->unsubscribeUrl.'>';
            $text['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return new Headers(text: $text);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.lifecycle',
            with: [
                'segment' => $this->segment,
                'stage' => $this->stage,
                'firstName' => $this->firstName(),
                'ctaUrl' => $this->ctaUrl(),
                'ctaLabel' => $this->ctaLabel(),
                'unsubscribeUrl' => $this->unsubscribeUrl,
            ],
        );
    }
}
