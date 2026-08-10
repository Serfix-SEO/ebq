<?php

namespace Tests\Feature\Lifecycle;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class UnsubscribeTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_get_renders_confirm_page_without_mutating(): void
    {
        $user = User::factory()->create();
        $url = URL::signedRoute('email.unsubscribe', ['user' => $user->id]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Unsubscribe');

        $this->assertNull($user->fresh()->marketing_emails_opted_out_at);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $user = User::factory()->create();
        $url = URL::signedRoute('email.unsubscribe', ['user' => $user->id]);

        $this->get($url.'tampered')->assertForbidden();
    }

    public function test_signed_post_stamps_opt_out_idempotently(): void
    {
        $user = User::factory()->create();
        $url = URL::signedRoute('email.unsubscribe.store', ['user' => $user->id]);

        $this->post($url)->assertOk();
        $first = $user->fresh()->marketing_emails_opted_out_at;
        $this->assertNotNull($first);

        $this->travel(1)->hours();
        $this->post($url)->assertOk();
        // Second POST must not move the stamp.
        $this->assertTrue($first->equalTo($user->fresh()->marketing_emails_opted_out_at));
    }

    public function test_opted_out_user_gets_no_lifecycle_mail(): void
    {
        Mail::fake();
        User::factory()->create([
            'created_at' => now()->subDays(30),
            'email' => 'opted-out@lifecycle-test.dev', // deliverable domain: opt-out is the only exclusion
            'marketing_emails_opted_out_at' => now(),
        ]);

        $this->artisan('ebq:send-lifecycle-emails')->assertSuccessful();

        Mail::assertNothingSent();
    }
}
