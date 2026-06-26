<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename the 4 old plan slugs to legacy_* and deactivate them, making
 * room for the new 5-tier set (trial/solo/pro/agency/enterprise) seeded
 * by PlanSeeder after this migration runs.
 *
 * Modeled on 2026_05_17_000100_rename_plan_slugs.php — same pattern:
 * transactional two-step to avoid unique collision on plans.slug, plus
 * users.current_plan_slug snapshot rewrite + defensive settings scan.
 *
 * Affected tables:
 *   plans                  — slug + is_active
 *   users.current_plan_slug — fallback snapshot kept in sync
 *   settings               — defensive scan for bare slug values
 *
 * NOT touched: Cashier subscriptions — keyed by Stripe price IDs, not
 * slugs. Existing subscribers keep resolving to the legacy rows via
 * stripe_price_id_yearly; their entitlements are unchanged.
 */
return new class extends Migration {
    private const RENAMES = [
        'free'    => 'legacy_free',
        'pro'     => 'legacy_pro',
        'startup' => 'legacy_startup',
        'agency'  => 'legacy_agency',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::RENAMES as $old => $new) {
                DB::table('plans')
                    ->where('slug', $old)
                    ->update(['slug' => $new, 'is_active' => false]);
            }

            if (Schema::hasColumn('users', 'current_plan_slug')) {
                foreach (self::RENAMES as $old => $new) {
                    DB::table('users')
                        ->where('current_plan_slug', $old)
                        ->update(['current_plan_slug' => $new]);
                }
            }

            if (Schema::hasTable('settings')) {
                $rows = DB::table('settings')->get(['key', 'value']);
                foreach ($rows as $row) {
                    $decoded = is_string($row->value) ? json_decode($row->value, true) : null;
                    if (is_string($decoded) && array_key_exists($decoded, self::RENAMES)) {
                        DB::table('settings')
                            ->where('key', $row->key)
                            ->update(['value' => json_encode(self::RENAMES[$decoded])]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (array_flip(self::RENAMES) as $legacy => $original) {
                DB::table('plans')
                    ->where('slug', $legacy)
                    ->update(['slug' => $original, 'is_active' => true]);
            }

            if (Schema::hasColumn('users', 'current_plan_slug')) {
                foreach (array_flip(self::RENAMES) as $legacy => $original) {
                    DB::table('users')
                        ->where('current_plan_slug', $legacy)
                        ->update(['current_plan_slug' => $original]);
                }
            }
        });
    }
};
