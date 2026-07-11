<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Trial-expiry countdown for `ebq:trial-cleanup`. Four stages:
 * expired (buffer starts) → 48h → 24h → 12h before data deletion.
 */
class TrialExpiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public string $stage,          // expired | h48 | h24 | h12
        public Carbon $deletionAt,
    ) {
        $this->locale(\App\Support\LocaleConfig::resolve($user->locale));
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->stage) {
            'expired' => 'Your Serfix trial has ended — your data will be deleted in 3 days',
            'h48' => 'Reminder: your Serfix data will be deleted in 2 days',
            'h24' => $this->promoCode() !== null
                ? 'Last day: '.config('services.stripe.winback_promo_percent').'% off any plan — your Serfix data is deleted tomorrow'
                : 'Last day: your Serfix data will be deleted tomorrow',
            default => 'Final notice: your Serfix data will be deleted in 12 hours',
        };

        return new Envelope(subject: $subject);
    }

    /** Winback promo code for the h24 (second-to-last) email; null = no offer. */
    private function promoCode(): ?string
    {
        if ($this->stage !== 'h24') {
            return null;
        }
        $code = (string) config('services.stripe.winback_promo_code');

        return $code !== '' ? $code : null;
    }

    public function content(): Content
    {
        $when = $this->deletionAt->toDayDateTimeString().' UTC';
        $upgradeUrl = rtrim(config('app.public_url', config('app.url')), '/').'/billing';
        $name = e($this->user->name ?: 'there');

        $promo = $this->promoCode();
        $percent = (int) config('services.stripe.winback_promo_percent');
        $offerHtml = '';
        if ($promo !== null) {
            // Link lands on /billing?promo=… so checkout auto-applies the
            // discount (BillingController::show parks it in the session).
            $upgradeUrl .= '?promo='.rawurlencode($promo);
            $offerHtml = <<<HTML
    <div style="background:#FFF3EA;border:1px solid #F26419;border-radius:8px;padding:16px 20px;margin:20px 0;">
        <p style="margin:0;line-height:1.6;"><strong>Before you go — take {$percent}% off any plan.</strong><br>
        Use code <strong style="color:#C44E0E;">{$promo}</strong> at checkout (applied automatically via the button below). Valid on every plan, monthly or yearly.</p>
    </div>
HTML;
        }

        $lead = match ($this->stage) {
            'expired' => 'Your free trial has ended. Your websites and all collected SEO data (rankings, audits, Search Console history) will be permanently deleted on '.$when.'.',
            'h48' => 'Two days left: your websites and all collected SEO data will be permanently deleted on '.$when.'.',
            'h24' => 'Tomorrow is the last day: your websites and all collected SEO data will be permanently deleted on '.$when.'.',
            default => 'This is the final notice — in about 12 hours ('.$when.') your websites and all collected SEO data will be permanently deleted.',
        };

        $html = <<<HTML
<div style="font-family: Inter, Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #111111;">
    <h2 style="color:#111111;">Hi {$name},</h2>
    <p style="line-height:1.6;">{$lead}</p>
    <p style="line-height:1.6;">Upgrading to any paid plan keeps everything exactly as it is — nothing is lost, and tracking continues uninterrupted.</p>
{$offerHtml}
    <p style="margin:24px 0;">
        <a href="{$upgradeUrl}" style="background:#F26419;color:#ffffff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;">Keep my data — upgrade now</a>
    </p>
    <p style="font-size:12px;color:#5A5A5A;line-height:1.6;">Deletion is permanent and cannot be undone. Your login remains valid either way — you can subscribe any time, but data collected during the trial cannot be restored after deletion.</p>
</div>
HTML;

        return new Content(htmlString: $html);
    }
}
