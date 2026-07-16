<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\ClientActivityLogger;
use App\Services\Usage\UsageMeter;
use Illuminate\Support\Facades\Cache;

/**
 * Monthly backlink-row consumption (Ahrefs-style): a plan grants N backlink
 * rows per monthly window (api_limits.report.monthly_backlink_rows, windows
 * anchored to the subscription day via UsageMeter). Viewing a domain's
 * backlinks charges the rows shown ONCE per (user, domain, window) — repeat
 * views are free and always show the same rows.
 *
 * Interplay with the per-view render cap (report.max_backlink_rows): rows
 * shown = min(available, per-view cap, monthly remaining), with a small free
 * teaser floor so an exhausted quota degrades to a preview + upgrade banner
 * instead of an empty table.
 */
class BacklinkRowQuota
{
    /** Rows always visible even when the monthly quota is exhausted. */
    public const TEASER_ROWS = 25;

    public function __construct(
        private UsageMeter $meter,
        private ClientActivityLogger $activity,
    ) {
    }

    /**
     * Decide + charge, then stash the outcome into the payload for the views.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(User $user, array $payload): array
    {
        $domain = strtolower(trim((string) ($payload['domain'] ?? '')));
        $available = count($payload['backlinks'] ?? []);
        if ($domain === '' || $available === 0) {
            return $payload;
        }

        $perView = $user->effectivePlan()?->apiLimit('report.max_backlink_rows') ?? 1000;
        $limit = $this->meter->limit($user, 'backlink_rows');

        // Unlimited plan (no monthly key) → only the per-view cap applies.
        if ($limit === null) {
            $payload['_backlink_view'] = [
                'shown' => min($available, $perView),
                'available' => $available,
                'monthly_used' => null,
                'monthly_limit' => null,
                'exhausted' => false,
            ];

            return $payload;
        }

        $windowStart = $this->meter->currentWindowStart($user);
        $dedupeKey = 'blrows:'.$user->id.':'.$domain.':'.$windowStart->format('Ymd');

        $charged = Cache::get($dedupeKey);
        if ($charged === null) {
            $remaining = max(0, $this->meter->remaining($user, 'backlink_rows') ?? 0);
            $charged = min($available, $perView, $remaining);
            if ($charged > 0) {
                // Same reserve→log(release) pair every metered provider uses,
                // so pending reservations and ClientActivity stay symmetric.
                $this->meter->reserve((string) $user->id, 'backlink_rows', $charged);
                $this->activity->log(
                    type: 'backlink_rows_view',
                    userId: (string) $user->id,
                    provider: 'backlink_rows',
                    meta: ['domain' => $domain],
                    unitsConsumed: $charged,
                );
            }
            Cache::put($dedupeKey, $charged, $windowStart->copy()->addMonthNoOverflow()->addDays(2));
        }

        $used = $this->meter->consumedInWindow($user, 'backlink_rows');
        $shown = max((int) $charged, min(self::TEASER_ROWS, $available, $perView));

        $payload['_backlink_view'] = [
            'shown' => $shown,
            'available' => $available,
            'monthly_used' => min($used, $limit),
            'monthly_limit' => $limit,
            'exhausted' => $shown < min($available, $perView) && $used >= $limit,
        ];

        return $payload;
    }
}
