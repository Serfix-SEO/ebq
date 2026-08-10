<?php

namespace App\Console\Commands;

use App\Mail\LifecycleMail;
use App\Models\LifecycleEmailSend;
use App\Models\User;
use App\Models\Website;
use App\Services\Lifecycle\LifecycleSegmentResolver;
use App\Support\LifecycleEmailConfig;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/**
 * Daily lifecycle (onboarding-funnel) emails, segment-matched:
 *   1 articles flowing → feedback ask     2 no website → activation
 *   3 strategy unfinished → resume nudge  4 not connected → connect nudge
 *
 * Three passes, in this order:
 *   conversions — sent+unconverted rows whose user has since left that
 *     segment get converted_at stamped. Runs even when sending is disabled;
 *     it's the report's honesty, not a send.
 *   follow-ups  — initial sent ≥ N days ago (seg 1: 3, rest: 2), user STILL
 *     in the same segment, no follow-up yet. Runs before initials so the
 *     daily cap can't starve it. Reply detection is impossible (no Postal
 *     inbound), so seg 1's follow-up sends even if the user replied — its
 *     copy reads fine either way.
 *   initials    — eligible users oldest-first (chunkById on the ULID pk),
 *     each resolved to at most one segment.
 *
 * The daily cap (admin-tunable, launch ramp for the existing backlog) counts
 * successful sends across follow-ups + initials. Idempotency lives in the DB:
 * unique (user_id, segment, stage), updateOrCreate on that key, status
 * flipped to `sent` only AFTER Mail::send returns — a `failed` row is
 * retried on the next run.
 */
class SendLifecycleEmails extends Command
{
    protected $signature = 'ebq:send-lifecycle-emails
        {--dry-run : List would-send/would-stamp actions with zero DB writes}
        {--limit= : Override the daily send cap for this run}';

    protected $description = 'Send segment-matched lifecycle emails (initial + follow-up) and stamp conversions.';

    private bool $dryRun = false;

    private int $budget = 0;

    public function handle(LifecycleSegmentResolver $resolver): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->budget = $this->option('limit') !== null
            ? max(0, (int) $this->option('limit'))
            : LifecycleEmailConfig::dailyCap();

        if (! LifecycleEmailConfig::enabled()) {
            // Conversions still get stamped — the admin report must stay
            // truthful while sending is paused.
            $converted = $this->stampConversions($resolver);
            $this->info("Lifecycle emails disabled (lifecycle.enabled). Conversions stamped: {$converted}.");

            return self::SUCCESS;
        }

        if (! Route::has('content.get-started')) {
            $this->warn('Content Autopilot UI routes are not registered — CTA links would 500. Nothing sent.');

            return self::SUCCESS;
        }

        $converted = $this->stampConversions($resolver);
        $followups = $this->sendFollowups($resolver);
        $initials = $this->sendInitials($resolver);

        $prefix = $this->dryRun ? '[dry-run] ' : '';
        $this->info("{$prefix}lifecycle: {$initials} initial, {$followups} follow-up, {$converted} converted, budget left {$this->budget}.");

        return self::SUCCESS;
    }

    /** Stamp converted_at on rows whose user has since left the emailed segment. */
    private function stampConversions(LifecycleSegmentResolver $resolver): int
    {
        $stamped = 0;

        LifecycleEmailSend::query()
            ->where('status', LifecycleEmailSend::STATUS_SENT)
            ->whereNull('converted_at')
            ->with('user')
            ->chunkById(200, function (Collection $rows) use ($resolver, &$stamped): void {
                foreach ($rows as $row) {
                    if ($row->user === null) {
                        continue;
                    }

                    $current = $resolver->resolve($row->user);
                    if ($current !== null && $current['segment'] === $row->segment) {
                        continue; // still stuck — not converted
                    }

                    if ($this->dryRun) {
                        $this->line("would stamp converted: {$row->to_email} seg {$row->segment}/{$row->stage}");
                    } else {
                        $row->forceFill(['converted_at' => now()])->save();
                    }
                    $stamped++;
                }
            });

        return $stamped;
    }

    private function sendFollowups(LifecycleSegmentResolver $resolver): int
    {
        $sent = 0;

        // Initial rows old enough for ANY segment's delay; the exact
        // per-segment delay is re-checked per row below.
        $candidates = LifecycleEmailSend::query()
            ->where('stage', LifecycleEmailSend::STAGE_INITIAL)
            ->where('status', LifecycleEmailSend::STATUS_SENT)
            ->whereNull('converted_at')
            ->where('created_at', '<=', now()->subDays(2))
            ->orderBy('created_at')
            ->with('user')
            ->get();

        foreach ($candidates as $row) {
            if ($this->budget <= 0) {
                break;
            }

            $user = $row->user;
            if ($user === null
                || ! LifecycleEmailConfig::segmentEnabled($row->segment)
                || $row->created_at->gt(now()->subDays(LifecycleEmailConfig::followupDelayDays($row->segment)))) {
                continue;
            }

            // A follow-up row that already SENT means done; a failed one is
            // retried below via updateOrCreate on the same key.
            $existing = LifecycleEmailSend::query()
                ->where('user_id', $user->id)
                ->where('segment', $row->segment)
                ->where('stage', LifecycleEmailSend::STAGE_FOLLOWUP)
                ->first();
            if ($existing !== null && $existing->status === LifecycleEmailSend::STATUS_SENT) {
                continue;
            }

            // Live re-checks: still eligible at all, and still in the SAME
            // segment (conversion stamping above handles the "left" case, but
            // eligibility can change independently — opt-out, disabled, …).
            if (! $resolver->eligibleUsersQuery()->whereKey($user->id)->exists()) {
                continue;
            }
            $current = $resolver->resolve($user);
            if ($current === null || $current['segment'] !== $row->segment) {
                continue;
            }

            if ($this->send($user, $row->segment, LifecycleEmailSend::STAGE_FOLLOWUP, $current['website'])) {
                $sent++;
            }
        }

        return $sent;
    }

    private function sendInitials(LifecycleSegmentResolver $resolver): int
    {
        $sent = 0;

        $resolver->eligibleUsersQuery()
            ->chunkById(200, function (Collection $users) use ($resolver, &$sent): bool {
                foreach ($users as $user) {
                    if ($this->budget <= 0) {
                        return false; // stop chunking
                    }

                    $resolved = $resolver->resolve($user);
                    if ($resolved === null || ! LifecycleEmailConfig::segmentEnabled($resolved['segment'])) {
                        continue;
                    }

                    $existing = LifecycleEmailSend::query()
                        ->where('user_id', $user->id)
                        ->where('segment', $resolved['segment'])
                        ->where('stage', LifecycleEmailSend::STAGE_INITIAL)
                        ->first();
                    if ($existing !== null && $existing->status === LifecycleEmailSend::STATUS_SENT) {
                        continue;
                    }

                    if ($this->send($user, $resolved['segment'], LifecycleEmailSend::STAGE_INITIAL, $resolved['website'])) {
                        $sent++;
                    }
                }

                return true;
            });

        return $sent;
    }

    /** Send one email + upsert its log row. Returns true on success. */
    private function send(User $user, int $segment, string $stage, ?Website $website): bool
    {
        if ($this->dryRun) {
            $this->line("would send seg {$segment}/{$stage} to {$user->email}".($website ? " (site {$website->domain})" : ''));
            $this->budget--;

            return true;
        }

        $unsubscribe = URL::signedRoute('email.unsubscribe', ['user' => $user->id]);
        $mailable = new LifecycleMail($user, $segment, $stage, $website, $unsubscribe);
        $key = ['user_id' => $user->id, 'segment' => $segment, 'stage' => $stage];

        try {
            Mail::to($user->email)->send($mailable);

            LifecycleEmailSend::query()->updateOrCreate($key, [
                'website_id' => $website?->id,
                'to_email' => $user->email,
                'subject' => $mailable->subjectLine(),
                'status' => LifecycleEmailSend::STATUS_SENT,
                'meta' => [
                    'locale' => $user->locale,
                    'cta_url' => $mailable->ctaUrl(),
                    'website_domain' => $website?->domain,
                ],
            ]);
            $this->budget--;

            return true;
        } catch (\Throwable $e) {
            Log::warning("SendLifecycleEmails: seg {$segment}/{$stage} to {$user->email} failed: {$e->getMessage()}");

            LifecycleEmailSend::query()->updateOrCreate($key, [
                'website_id' => $website?->id,
                'to_email' => $user->email,
                'subject' => $mailable->subjectLine(),
                'status' => LifecycleEmailSend::STATUS_FAILED,
                'meta' => ['error' => \Illuminate\Support\Str::limit($e->getMessage(), 240)],
            ]);

            return false;
        }
    }
}
