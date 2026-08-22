<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to the REFERRER when a referral matures (the referred account paid
 * its first full bill and the 50% credit landed on the referrer's Stripe
 * balance). Snapshots the credit amount as a scalar so a queued render can't
 * drift; never names the referred person (privacy).
 */
class ReferralRewardEarned extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $referrer,
        public int $creditCents,
        public string $currency = 'usd',
    ) {
        $this->locale(\App\Support\LocaleConfig::resolve($referrer->locale));
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('You earned a referral reward — 50% off your next bill'));
    }

    public function content(): Content
    {
        $amount = '$'.number_format($this->creditCents / 100, 2);
        $appUrl = rtrim(config('app.public_url', config('app.url')), '/');
        $referralsUrl = $appUrl.'/referrals';

        $line1 = e(__('Someone you referred just made their first payment — thank you for spreading the word!'));
        $line2 = e(__('We\'ve added a :amount credit to your account — 50% off your subscription. It will be applied automatically to your next invoice.', ['amount' => $amount]));
        $cta = e(__('View your referrals'));
        $footer = e(__('Keep sharing your referral link — every friend who subscribes earns you another 50% off.'));
        $hi = e(__('Hi :name,', ['name' => $this->referrer->name ?: __('there')]));

        $html = <<<HTML
<div style="font-family: Inter, Arial, sans-serif; max-width: 560px; margin: 0 auto; color: #111111;">
    <h2 style="color:#111111;">{$hi}</h2>
    <p style="line-height:1.6;">{$line1}</p>
    <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:14px 18px;line-height:1.6;font-size:15px;">{$line2}</div>
    <p style="margin:24px 0;">
        <a href="{$referralsUrl}" style="background:#F26419;color:#ffffff;padding:10px 24px;border-radius:8px;text-decoration:none;font-weight:600;">{$cta}</a>
    </p>
    <p style="font-size:12px;color:#5A5A5A;line-height:1.6;">{$footer}</p>
</div>
HTML;

        return new Content(htmlString: $html);
    }
}
