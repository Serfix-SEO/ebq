<?php

namespace Tests\Feature\Lifecycle;

use App\Mail\LifecycleMail;
use App\Models\ContentPlan;
use App\Models\LifecycleEmailSend;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendLifecycleEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function segment2User(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'created_at' => now()->subDays(30),
            // Factory safeEmail() yields @example.* — excluded as demo data.
            'email' => fake()->unique()->userName().'@lifecycle-test.dev',
        ]);
    }

    public function test_initial_email_is_sent_and_logged(): void
    {
        $user = $this->segment2User();

        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        Mail::assertSent(LifecycleMail::class, fn (LifecycleMail $m) => $m->segment === 2
            && $m->stage === 'initial'
            && $m->user->is($user)
            && $m->unsubscribeUrl !== null);

        $this->assertDatabaseHas('lifecycle_email_sends', [
            'user_id' => $user->id,
            'segment' => 2,
            'stage' => 'initial',
            'status' => 'sent',
            'subject' => 'Ready to see what SERFIX fixes?',
        ]);
    }

    public function test_rerun_is_idempotent(): void
    {
        $this->segment2User();

        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();
        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        Mail::assertSentCount(1);
        $this->assertSame(1, LifecycleEmailSend::query()->count());
    }

    public function test_followup_sends_after_delay_not_before(): void
    {
        $this->segment2User();
        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        // Day +1: too early for the seg-2 follow-up (2 days).
        $this->travel(1)->days();
        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();
        Mail::assertSentCount(1);

        // Day +2: follow-up goes out.
        $this->travel(1)->days();
        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        Mail::assertSentCount(2);
        $this->assertDatabaseHas('lifecycle_email_sends', [
            'segment' => 2,
            'stage' => 'followup',
            'status' => 'sent',
        ]);
    }

    public function test_progressed_user_gets_conversion_stamp_not_followup(): void
    {
        $user = $this->segment2User();
        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        // User adds a website → leaves segment 2 (now segment 3).
        Website::factory()->create(['user_id' => $user->id]);

        $this->travel(3)->days();
        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        $initial = LifecycleEmailSend::query()
            ->where('user_id', $user->id)->where('segment', 2)->where('stage', 'initial')->first();
        $this->assertNotNull($initial->converted_at);
        $this->assertDatabaseMissing('lifecycle_email_sends', ['user_id' => $user->id, 'segment' => 2, 'stage' => 'followup']);

        // …and the NEW journey's initial (segment 3) went out on the same run.
        $this->assertDatabaseHas('lifecycle_email_sends', [
            'user_id' => $user->id, 'segment' => 3, 'stage' => 'initial', 'status' => 'sent',
        ]);
    }

    public function test_daily_cap_and_oldest_first(): void
    {
        $older = $this->segment2User(['created_at' => now()->subDays(60)]);
        $newer = $this->segment2User(['created_at' => now()->subDays(10)]);
        Setting::set('lifecycle.daily_cap', '1');

        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        Mail::assertSentCount(1);
        $this->assertDatabaseHas('lifecycle_email_sends', ['user_id' => $older->id]);
        $this->assertDatabaseMissing('lifecycle_email_sends', ['user_id' => $newer->id]);
    }

    public function test_limit_option_overrides_cap(): void
    {
        $this->segment2User();
        $this->segment2User();
        Setting::set('lifecycle.daily_cap', '50');

        $this->artisan('ebq:send-lifecycle-emails', ['--limit' => 1])->assertSuccessful();

        Mail::assertSentCount(1);
    }

    public function test_master_switch_off_sends_nothing_but_still_stamps_conversions(): void
    {
        $user = $this->segment2User();
        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();
        Website::factory()->create(['user_id' => $user->id]);

        Setting::set('lifecycle.enabled', '0');
        $this->travel(3)->days();
        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        Mail::assertSentCount(1); // only the original initial
        $this->assertNotNull(LifecycleEmailSend::query()->first()->converted_at);
    }

    public function test_segment_toggle_off_skips_that_segment(): void
    {
        $noSite = $this->segment2User();
        $withSite = $this->segment2User();
        Website::factory()->create(['user_id' => $withSite->id]); // segment 3

        Setting::set('lifecycle.segment.2.enabled', '0');
        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        $this->assertDatabaseMissing('lifecycle_email_sends', ['user_id' => $noSite->id]);
        $this->assertDatabaseHas('lifecycle_email_sends', ['user_id' => $withSite->id, 'segment' => 3]);
    }

    public function test_failed_row_is_retried_next_run(): void
    {
        $user = $this->segment2User();
        LifecycleEmailSend::create([
            'user_id' => $user->id,
            'segment' => 2,
            'stage' => 'initial',
            'to_email' => $user->email,
            'subject' => 'Ready to see what SERFIX fixes?',
            'status' => LifecycleEmailSend::STATUS_FAILED,
            'meta' => ['error' => 'boom'],
        ]);

        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        Mail::assertSentCount(1);
        $this->assertSame(1, LifecycleEmailSend::query()->count());
        $this->assertSame('sent', LifecycleEmailSend::query()->first()->status);
    }

    public function test_dry_run_writes_and_sends_nothing(): void
    {
        $this->segment2User();

        $this->artisan('ebq:send-lifecycle-emails', ['--dry-run' => true])->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, LifecycleEmailSend::query()->count());
    }

    public function test_draft_plan_user_gets_segment_3_email(): void
    {
        $user = $this->segment2User();
        $site = Website::factory()->create(['user_id' => $user->id]);
        ContentPlan::factory()->create(['website_id' => $site->id, 'status' => ContentPlan::STATUS_DRAFT]);

        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        $this->assertDatabaseHas('lifecycle_email_sends', [
            'user_id' => $user->id,
            'segment' => 3,
            'stage' => 'initial',
            'subject' => 'Your content plan is almost ready',
        ]);
    }
}
