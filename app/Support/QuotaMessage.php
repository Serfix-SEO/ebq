<?php

namespace App\Support;

/**
 * Recognizes the user-facing "plan cap reached" messages produced by
 * {@see \App\Services\Usage\UsageMeter::messageFor()} (and surfaced through
 * failed keyword requests / gap errors), so views can render the prominent
 * <x-quota-alert> instead of a generic small error line.
 */
final class QuotaMessage
{
    public static function isQuota(?string $message): bool
    {
        // Every UsageMeter cap message ends with an "Upgrade your plan …" CTA.
        return $message !== null && str_contains($message, 'Upgrade your plan');
    }
}
