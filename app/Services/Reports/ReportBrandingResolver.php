<?php

namespace App\Services\Reports;

use App\Models\ReportBranding;
use App\Models\User;
use App\Models\Website;

/**
 * Decides which {@see ReportBranding} a given (user, website) pair gets.
 *
 * Resolution order (first hit wins):
 *   1. If plan disables `report_whitelabel` → EBQ default.
 *   2. Per-website override row (website_id = $website->id).
 *   3. Per-user default row (user_id = $user->id, website_id null).
 *   4. EBQ default.
 *
 * Saved rows are preserved when the plan flag is off, so a downgrade
 * doesn't lose configuration — re-enabling lights them up again.
 */
class ReportBrandingResolver
{
    public function for(User $user, Website $website): ReportBranding
    {
        $flags = $user->effectivePlanFeatures();
        if (($flags['report_whitelabel'] ?? false) !== true) {
            return ReportBranding::ebqDefault();
        }

        $override = ReportBranding::query()
            ->where('website_id', $website->id)
            ->first();
        if ($override !== null) {
            return $override;
        }

        $default = ReportBranding::query()
            ->where('user_id', $user->id)
            ->whereNull('website_id')
            ->first();
        if ($default !== null) {
            return $default;
        }

        return ReportBranding::ebqDefault();
    }
}
