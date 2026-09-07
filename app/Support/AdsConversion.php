<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Google Ads conversions, queued server-side and rendered once by
 * resources/views/partials/ads-conversion.blade.php.
 *
 * Why not the click handler Google's snippets assume: a click happens BEFORE
 * the thing it claims to measure. Clicking "Subscribe" precedes payment, and
 * clicking "Start free trial" precedes the account existing — click-firing
 * would report every abandoned checkout and every bounced signup as a win. We
 * have a backend that knows when each actually happened, so both fire from the
 * page the customer lands on afterwards.
 *
 * The payload is FLASH data: consumed by the request that renders it, so a
 * refresh or a bookmark reports nothing.
 */
final class AdsConversion
{
    /** Fired when Stripe confirms a Content Autopilot subscription is live. */
    public const SUBSCRIPTION = 'AW-18374890122/U8gBCNCfkt0cEIql6rlE';

    /** Fired when a content trial actually starts (once per user, ever). */
    public const TRIAL = 'AW-18374890122/n_X8CJ2elN0cEIql6rlE';

    public const SESSION_KEY = 'ads_conversion';

    /**
     * Queue a conversion for the next rendered page.
     *
     * Silently does nothing without a session — startTrial() is reachable from
     * console and queue contexts, and an untracked trial is a far better
     * outcome than a 500 in the middle of one.
     */
    public static function queue(string $sendTo, float $value, string $currency, string $transactionId = ''): void
    {
        try {
            if (! app()->bound('session') || ! app('session')->isStarted()) {
                return;
            }

            session()->flash(self::SESSION_KEY, [
                'send_to' => $sendTo,
                'value' => $value,
                'currency' => $currency,
                'transaction_id' => $transactionId,
            ] + self::userData());
        } catch (\Throwable $e) {
            Log::warning('AdsConversion: could not queue '.$sendTo.': '.$e->getMessage());
        }
    }

    /**
     * Enhanced-conversions user_data (Google Ads flagged the setup 2026-09-07:
     * the feature was ON for the conversion actions but the tag sent no
     * first-party data). Values are SHA-256 hashes of Google's normalized
     * forms — the page source never carries raw PII; Google matches on the
     * same hashes. Phone only when it is already E.164-shaped (Google requires
     * E.164 pre-normalization; a mangled hash can never match anyway).
     *
     * @return array{sha256_email?: string, sha256_phone?: string}
     */
    private static function userData(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return [];
        }
        $out = [];
        $email = mb_strtolower(trim((string) $user->email));
        if ($email !== '') {
            $out['sha256_email'] = hash('sha256', $email);
        }
        $phone = preg_replace('/[\s().-]/', '', (string) $user->phone);
        if (preg_match('/^\+[0-9]{8,15}$/', (string) $phone)) {
            $out['sha256_phone'] = hash('sha256', $phone);
        }

        return $out;
    }

    /**
     * What a started trial is worth for bidding.
     *
     * $1 by default, matching two things deliberately: the $1 first month a
     * monthly signup actually pays, and the default value set on the trial
     * conversion action in Google Ads on 2026-08-06. Keeping the two in step
     * matters — if that action is set to "use different values", whatever we
     * send here overrides the $1 in the UI, and a number nobody chose would
     * quietly start steering bids.
     *
     * A trial is really worth the subscription price times the share of trials
     * that go on to pay, which is a guess until there is history to measure it.
     * That is why the number lives in a setting rather than in code: set
     * `content.ads.trial_value_usd` once the real rate is known (and raise the
     * Ads-side default to match) and bidding follows immediately.
     */
    public static function trialValueUsd(): float
    {
        $configured = Setting::get('content.ads.trial_value_usd');
        if (is_numeric($configured)) {
            return round((float) $configured, 2);
        }

        return 1.0;
    }
}
