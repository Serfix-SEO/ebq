<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One-shot promo for ACTIVE trial users: the same straight discount the
 * winback flow gives (services.stripe.winback_promo_*), offered mid-trial.
 * Sent once per user by `ebq:send-trial-discount-emails` (tracked via
 * users.trial_discount_email_sent_at). Expired users are NOT in this
 * audience — their countdown emails (TrialExpiryMail h24) carry the offer.
 */
class TrialDiscountMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
    ) {
        $this->locale(\App\Support\LocaleConfig::resolve($user->locale));
    }

    public function envelope(): Envelope
    {
        $percent = (int) config('services.stripe.winback_promo_percent');

        return new Envelope(subject: "{$percent}% off any Serfix plan — locked in while you're on the free trial");
    }

    public function content(): Content
    {
        $promo = (string) config('services.stripe.winback_promo_code');
        $percent = (int) config('services.stripe.winback_promo_percent');
        $name = e($this->user->name ?: 'there');
        // /billing?promo=… parks the code in the session so checkout
        // auto-applies it (BillingController::show) — same link the
        // h24 expiry email uses.
        $upgradeUrl = rtrim(config('app.public_url', config('app.url')), '/').'/billing?promo='.rawurlencode($promo);

        $html = <<<HTML
<div style="font-family: Inter, Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #111111;">
    <h2 style="color:#111111;">Hi {$name},</h2>
    <p style="line-height:1.6;">You're on the Serfix free trial — and while you are, <strong>{$percent}% off any plan</strong> is yours, monthly or yearly.</p>
    <div style="background:#FFF3EA;border:1px solid #F26419;border-radius:8px;padding:16px 20px;margin:20px 0;">
        <p style="margin:0;line-height:1.6;"><strong>{$percent}% off your first payment.</strong><br>
        Code <strong style="color:#C44E0E;">{$promo}</strong> — applied automatically via the button below, no typing needed.</p>
    </div>
    <p style="line-height:1.6;">Subscribing keeps everything you've set up — rankings, audits, Search Console history — running without interruption after the trial.</p>
    <p style="margin:24px 0;">
        <a href="{$upgradeUrl}" style="background:#F26419;color:#ffffff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:600;">Claim {$percent}% off</a>
    </p>
    <p style="font-size:12px;color:#5A5A5A;line-height:1.6;">The discount applies to your first payment on any plan. You can keep using the free trial until it ends either way.</p>
</div>
HTML;

        return new Content(htmlString: $html);
    }
}
