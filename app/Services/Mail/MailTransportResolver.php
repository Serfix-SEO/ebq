<?php

namespace App\Services\Mail;

use App\Models\MailTransport;
use App\Models\User;
use App\Models\Website;

/**
 * Picks the outbound mail transport for a tenant's report send.
 *
 * Returns null when:
 *   - Plan disables `report_whitelabel` (use Laravel's default mailer).
 *   - No transport configured for this user/website (same fallback).
 *
 * Returns a {@see MailTransport} row when a per-website override exists,
 * else when a per-user default exists. The mailable + ConfigureMailerOnSend
 * listener handle the actual transport swap at send time.
 */
class MailTransportResolver
{
    public function for(User $user, Website $website): ?MailTransport
    {
        $flags = $user->effectivePlanFeatures();
        if (($flags['report_whitelabel'] ?? false) !== true) {
            return null;
        }

        $override = MailTransport::query()
            ->where('user_id', $user->id)
            ->where('website_id', $website->id)
            ->first();
        if ($override !== null) {
            return $override;
        }

        return MailTransport::query()
            ->where('user_id', $user->id)
            ->whereNull('website_id')
            ->first();
    }
}
