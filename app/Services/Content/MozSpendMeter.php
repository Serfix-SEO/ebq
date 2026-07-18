<?php

namespace App\Services\Content;

use App\Services\Spend\MonthlySpendMeter;

/**
 * Monthly circuit-breaker for Moz Links API `url_metrics` calls. The account
 * is free-tier (50 rows/month, shared with the client-report gauges — see
 * {@see \App\Services\MozLinksClient}), so the wizard's per-competitor DA/PA
 * lookups must not exhaust it. Reuses {@see MonthlySpendMeter}'s dollar
 * counter to track ROW COUNT instead (1 row = "$1"); this is not real money.
 */
class MozSpendMeter extends MonthlySpendMeter
{
    protected function prefix(): string
    {
        return 'content:moz:rows:';
    }

    protected function capConfigKey(): string
    {
        return 'services.moz.monthly_row_cap';
    }
}
