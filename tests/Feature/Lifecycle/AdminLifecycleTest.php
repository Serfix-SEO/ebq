<?php

namespace Tests\Feature\Lifecycle;

use App\Mail\LifecycleMail;
use App\Models\LifecycleEmailSend;
use App\Models\User;
use App\Support\LifecycleEmailConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_non_admin_gets_403(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.lifecycle.index'))
            ->assertForbidden();
    }

    public function test_index_renders_tiles_and_log(): void
    {
        $user = User::factory()->create(['created_at' => now()->subDays(30)]);
        LifecycleEmailSend::create([
            'user_id' => $user->id,
            'segment' => 2,
            'stage' => 'initial',
            'to_email' => $user->email,
            'subject' => 'Ready to see what SERFIX fixes?',
            'status' => 'sent',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.lifecycle.index'))
            ->assertOk()
            ->assertSee('Lifecycle emails')
            ->assertSee('No website yet')
            ->assertSee('Ready to see what SERFIX fixes?');
    }

    public function test_segment_filter_narrows_log(): void
    {
        $user = User::factory()->create();
        foreach ([2 => 'Subject two', 3 => 'Subject three'] as $segment => $subject) {
            LifecycleEmailSend::create([
                'user_id' => $user->id,
                'segment' => $segment,
                'stage' => 'initial',
                'to_email' => $user->email,
                'subject' => $subject,
                'status' => 'sent',
            ]);
        }

        $this->actingAs($this->admin())
            ->get(route('admin.lifecycle.index', ['segment' => 2]))
            ->assertOk()
            ->assertSee('Subject two')
            ->assertDontSee('Subject three');
    }

    public function test_settings_persist(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.lifecycle.settings'), [
                'enabled' => '1',
                'segment_1' => '1',
                'segment_3' => '1',
                'segment_4' => '1',
                // segment_2 unchecked
                'daily_cap' => 25,
                'min_account_age_days' => 5,
            ])
            ->assertRedirect(route('admin.lifecycle.index'));

        $this->assertTrue(LifecycleEmailConfig::enabled());
        $this->assertFalse(LifecycleEmailConfig::segmentEnabled(2));
        $this->assertTrue(LifecycleEmailConfig::segmentEnabled(3));
        $this->assertSame(25, LifecycleEmailConfig::dailyCap());
        $this->assertSame(5, LifecycleEmailConfig::minAccountAgeDays());
    }

    public function test_test_send_mails_without_log_row(): void
    {
        Mail::fake();

        $this->actingAs($this->admin())
            ->post(route('admin.lifecycle.test-send'), [
                'email' => 'preview@example.com',
                'segment' => 4,
                'stage' => 'initial',
            ])
            ->assertRedirect(route('admin.lifecycle.index'));

        Mail::assertSent(LifecycleMail::class, fn (LifecycleMail $m) => $m->segment === 4 && $m->stage === 'initial');
        $this->assertSame(0, LifecycleEmailSend::query()->count());
    }
}
