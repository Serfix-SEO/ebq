<?php

namespace App\Console\Commands;

use App\Mail\LifecycleMail;
use App\Models\LifecycleEmailSend;
use App\Models\User;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Preview any of the 8 lifecycle emails by sending it to an arbitrary
 * address. READ-ONLY with respect to the funnel: writes no
 * lifecycle_email_sends row and checks no eligibility, so previewing never
 * affects what a real user will receive. (Same philosophy as
 * ebq:content-published-test-mail / ebq:rank-gains-test-mail.)
 */
class SendLifecycleTestMail extends Command
{
    protected $signature = 'ebq:lifecycle-test-mail
        {email : Where to send the preview}
        {--segment=2 : Segment 1-4}
        {--stage=initial : initial | followup}
        {--user= : Render for a specific user id/email (defaults to an in-memory demo user)}';

    protected $description = 'Send a lifecycle email preview to an arbitrary address (no log row, no stamps).';

    public function handle(): int
    {
        $to = (string) $this->argument('email');
        $segment = (int) $this->option('segment');
        $stage = (string) $this->option('stage');

        if ($segment < 1 || $segment > 4) {
            $this->error('Segment must be 1-4.');

            return self::FAILURE;
        }
        if (! in_array($stage, [LifecycleEmailSend::STAGE_INITIAL, LifecycleEmailSend::STAGE_FOLLOWUP], true)) {
            $this->error('Stage must be initial or followup.');

            return self::FAILURE;
        }

        $user = $this->resolveUser();
        if ($user === null) {
            return self::FAILURE;
        }

        // A CTA target for segments 3/4 so the ebq_site deep-link renders:
        // the user's first site, or an in-memory demo one.
        $website = $user->exists ? $user->websites()->first() : null;
        if ($website === null && in_array($segment, [3, 4], true)) {
            $website = new Website(['domain' => 'example.com']);
            $website->normalized_domain = 'example.com';
        }

        $mailable = new LifecycleMail($user, $segment, $stage, $website, 'https://example.com/unsubscribe-preview');
        Mail::to($to)->send($mailable);

        $this->info("Sent seg {$segment}/{$stage} (\"{$mailable->subjectLine()}\") to {$to}. No log row written.");

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $ref = $this->option('user');

        if ($ref === null) {
            $demo = new User(['name' => 'Alex Founder', 'email' => 'demo@example.com']);
            $demo->locale = null;

            return $demo;
        }

        $user = User::query()->whereKey($ref)->orWhere('email', $ref)->first();
        if ($user === null) {
            $this->error("No user found for '{$ref}'.");
        }

        return $user;
    }
}
