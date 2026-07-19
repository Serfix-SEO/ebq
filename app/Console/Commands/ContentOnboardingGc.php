<?php

namespace App\Console\Commands;

use App\Models\ContentOnboardingSession;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Garbage-collect abandoned anonymous content-onboarding runs: delete
 * unconverted sessions older than a few days and their provisional websites
 * (owned by the content-leads system user). The model-path delete fires the
 * Website::deleted wiring, which GCs the shared crawl_site only when its last
 * subscriber leaves — same guarantee TrialCleanup relies on.
 */
class ContentOnboardingGc extends Command
{
    protected $signature = 'ebq:content-onboarding-gc {--days=7}';

    protected $description = 'Delete abandoned anonymous content-onboarding sessions and their provisional websites';

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(max(1, (int) $this->option('days')));

        $stale = ContentOnboardingSession::query()
            ->whereNull('converted_at')
            ->where('created_at', '<=', $cutoff)
            ->with('website')
            ->get();

        $sites = 0;
        $users = 0;
        foreach ($stale as $session) {
            $website = $session->website; // provisional site under a throwaway lead user
            $leadUser = $website?->user;
            $session->delete();
            if ($website !== null && $leadUser?->is_system) {
                $website->delete();
                $sites++;
                // Remove the now-orphaned per-session lead user too.
                if ($leadUser->websites()->count() === 0) {
                    $leadUser->delete();
                    $users++;
                }
            }
        }

        $this->info("content-onboarding GC: removed {$stale->count()} sessions, {$sites} provisional sites, {$users} lead users.");

        return self::SUCCESS;
    }
}
