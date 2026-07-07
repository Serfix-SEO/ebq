<?php

namespace App\Console\Commands;

use App\Mail\TrialExpiryMail;
use App\Models\User;
use App\Support\TrialStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Trial-expiry pipeline (2026-07-07): after the Trial plan's `trial_days`,
 * a user's data enters a 3-day deletion buffer with countdown emails at
 * expiry → 48h → 24h → 12h, then their WEBSITES (and via the existing
 * Website::deleted wiring, all their tenant data) are deleted. The login
 * survives — expiry is derived from created_at, so a returning user never
 * gets a fresh trial (and the billing-lockout middleware confines them to
 * /billing until they subscribe).
 *
 * SHARED-CRAWL SAFETY is inherited, not reimplemented: Website::deleted
 * only purges that user's tenant rows and GCs the shared crawl_site ONLY
 * when its last subscriber leaves — another client on the same domain is
 * untouched (see Website.php boot).
 *
 * Eligibility (TrialStatus::isExpired): never admins, never active
 * subscribers, never comped (force-applied) plans. `trial_data_deleted_at`
 * makes deletion one-shot per user.
 */
class TrialCleanup extends Command
{
    protected $signature = 'ebq:trial-cleanup {--dry-run : Report without emailing or deleting}';

    protected $description = 'Send trial-expiry countdown emails and delete data 3 days after trial expiry.';

    /** stage key => hours before deletion at/under which it fires */
    private const STAGES = [
        'expired' => 72,
        'h48' => 48,
        'h24' => 24,
        'h12' => 12,
    ];

    public function handle(): int
    {
        if (TrialStatus::trialDays() <= 0) {
            $this->info('Trial expiry disabled (trial plan trial_days = 0).');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays(TrialStatus::trialDays());

        $candidates = User::query()
            ->where('is_admin', false)
            ->whereNull('trial_data_deleted_at')
            ->where('created_at', '<=', $cutoff)
            ->where(fn ($q) => $q->whereNull('current_plan_slug')->orWhere('current_plan_slug', User::TIER_TRIAL))
            ->orderBy('created_at')
            ->get();

        $noticed = 0;
        $deleted = 0;

        foreach ($candidates as $user) {
            // Re-check the full rule (includes the live subscription check).
            if (! TrialStatus::isExpired($user)) {
                continue;
            }

            // FAIRNESS ANCHOR: the 3-day countdown starts at the FIRST notice
            // actually sent, not at the theoretical created_at+trial schedule.
            // Without this, accounts predating the feature (or spanning any
            // command downtime) would get their first-ever email already deep
            // in the buffer — or deleted with almost no warning.
            $sent = (array) ($user->trial_deletion_notices ?? []); // stage => iso timestamp

            if (! isset($sent['expired'])) {
                $deletionAt = Carbon::now()->addHours(72);
                if ($dryRun) {
                    $this->line("would send [expired] to {$user->email} (countdown starts; deletion {$deletionAt})");
                } else {
                    try {
                        Mail::to($user->email)->send(new TrialExpiryMail($user, 'expired', $deletionAt));
                        $sent['expired'] = Carbon::now()->toIso8601String();
                        $user->forceFill(['trial_deletion_notices' => $sent])->save();
                    } catch (\Throwable $e) {
                        Log::warning("TrialCleanup: mail [expired] to {$user->email} failed: {$e->getMessage()}");
                    }
                }
                $noticed++;

                continue; // one email per run per user
            }

            $deletionAt = Carbon::parse($sent['expired'])->addHours(72);
            $hoursLeft = Carbon::now()->diffInHours($deletionAt, false);

            if ($hoursLeft <= 0) {
                $this->deleteData($user, $dryRun);
                $deleted++;

                continue;
            }

            foreach (self::STAGES as $stage => $threshold) {
                if ($stage === 'expired' || $hoursLeft > $threshold || isset($sent[$stage])) {
                    continue;
                }
                if ($dryRun) {
                    $this->line("would send [{$stage}] to {$user->email} ({$hoursLeft}h left)");
                } else {
                    try {
                        Mail::to($user->email)->send(new TrialExpiryMail($user, $stage, $deletionAt));
                        $sent[$stage] = Carbon::now()->toIso8601String();
                        $user->forceFill(['trial_deletion_notices' => $sent])->save();
                    } catch (\Throwable $e) {
                        Log::warning("TrialCleanup: mail [{$stage}] to {$user->email} failed: {$e->getMessage()}");

                        break; // retry next run
                    }
                }
                $noticed++;
                // One email per run per user — even when several thresholds
                // are newly crossed (e.g. after downtime).
                break;
            }
        }

        $this->info(($dryRun ? '[dry-run] ' : '')."notices: {$noticed}, deletions: {$deleted}");

        return self::SUCCESS;
    }

    private function deleteData(User $user, bool $dryRun): void
    {
        $domains = $user->websites()->pluck('domain')->implode(', ');
        if ($dryRun) {
            $this->line("would DELETE data for {$user->email}: [{$domains}]");

            return;
        }

        Log::info("TrialCleanup: deleting trial data for {$user->email}", ['websites' => $domains]);

        // Model-path delete per website so ALL the existing wiring fires:
        // tenant-row purge on the right shard node + shared-crawl GC only
        // when the last subscriber leaves.
        foreach ($user->websites()->get() as $website) {
            $website->delete();
        }

        $user->forceFill(['trial_data_deleted_at' => now()])->save();
    }
}
