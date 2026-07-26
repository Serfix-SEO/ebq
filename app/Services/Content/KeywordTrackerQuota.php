<?php

namespace App\Services\Content;

use App\Models\ContentTrackedKeyword;
use App\Models\Website;
use App\Support\ContentAutopilotConfig;

/**
 * The Keyword Tracker's OWN usage meter — deliberately isolated from the Redis
 * MonthlySpendMeter family (LLM / image / Moz). It is a standing CAPACITY, not a
 * monthly window: the tracked-keyword row count per website IS the meter, so
 * there is no drift and "delete one to add one" is exact.
 *
 * Capacity per website: paid (or admin-comped) = ContentAutopilotConfig::trackerKeywords()
 * (default 500); trial-only = trialTrackerKeywords() (default 3). Quota is
 * per-website (confirmed with the user), so a multi-site account gets the cap on
 * each of its content-entitled sites.
 */
class KeywordTrackerQuota
{
    public function __construct(private ContentEntitlements $entitlements) {}

    /** How many keywords this website may track in total. */
    public function limitFor(Website $website): int
    {
        $user = $website->user;
        if ($user === null) {
            return 0;
        }

        // Paying subscriber OR admin-comped → full capacity (comp is treated like
        // paid across Content Autopilot, e.g. ContentEntitlements::blockReason).
        if ($this->entitlements->hasContentSubscription($user) || $this->entitlements->compSites($user) > 0) {
            return ContentAutopilotConfig::trackerKeywords();
        }

        if ($this->entitlements->onContentTrial($user)) {
            return ContentAutopilotConfig::trialTrackerKeywords();
        }

        return 0;
    }

    /** Keywords currently tracked for the website (the meter's numerator). */
    public function used(Website $website): int
    {
        return ContentTrackedKeyword::query()->where('website_id', $website->id)->count();
    }

    /** Remaining capacity (never negative). */
    public function remaining(Website $website): int
    {
        return max(0, $this->limitFor($website) - $this->used($website));
    }

    public function exhausted(Website $website): bool
    {
        return $this->remaining($website) <= 0;
    }

    /** True at ≥80% of capacity (the amber warning threshold). */
    public function nearCap(Website $website): bool
    {
        $limit = $this->limitFor($website);

        return $limit > 0 && $this->used($website) >= (int) floor($limit * 0.8);
    }
}
