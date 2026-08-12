<?php

namespace Tests\Feature\Support;

use App\Mail\SupportTicketReplied;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminSupportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function ticket(string $status = SupportTicket::STATUS_OPEN): SupportTicket
    {
        $user = User::factory()->create();
        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'Webhook not delivering',
            'status' => $status,
            'last_reply_at' => now(),
        ]);
        $ticket->messages()->create(['user_id' => $user->id, 'is_admin' => false, 'body' => 'My article never appeared.']);

        return $ticket;
    }

    public function test_non_admin_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.support.index'))
            ->assertForbidden();
    }

    public function test_index_lists_tickets_with_counts_and_filter(): void
    {
        $this->ticket();
        $this->ticket(SupportTicket::STATUS_CLOSED);

        $this->actingAs($this->admin())
            ->get(route('admin.support.index'))
            ->assertOk()
            ->assertSee('Webhook not delivering')
            ->assertSee('1 awaiting reply');

        $this->actingAs($this->admin())
            ->get(route('admin.support.index', ['status' => 'closed']))
            ->assertOk()
            ->assertSee('Closed (1)');
    }

    public function test_show_renders_the_thread(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->admin())
            ->get(route('admin.support.show', $ticket))
            ->assertOk()
            ->assertSee('My article never appeared.');
    }

    public function test_reply_marks_answered_and_emails_the_customer(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->admin())
            ->post(route('admin.support.reply', $ticket), ['body' => 'We found the issue — your webhook needs to store the article payload.'])
            ->assertRedirect(route('admin.support.show', $ticket));

        $ticket->refresh();
        $this->assertSame(SupportTicket::STATUS_ANSWERED, $ticket->status);
        $this->assertSame(2, $ticket->messages()->count());
        $this->assertSame(1, $ticket->messages()->where('is_admin', true)->count());

        Mail::assertSent(SupportTicketReplied::class, fn ($mail) => $mail->hasTo($ticket->user->email));
    }

    public function test_admin_rich_reply_is_sanitized_and_emailed_with_formatting(): void
    {
        $ticket = $this->ticket();

        $this->actingAs($this->admin())
            ->post(route('admin.support.reply', $ticket), [
                'body' => '<p>Fix is <b>live</b></p><script>alert(1)</script><ul><li>step one</li></ul>',
            ])
            ->assertRedirect();

        $body = $ticket->messages()->where('is_admin', true)->first()->body;
        $this->assertStringContainsString('<b>live</b>', $body);
        $this->assertStringContainsString('<li>step one</li>', $body);
        $this->assertStringNotContainsString('script', $body);

        Mail::assertSent(SupportTicketReplied::class, function ($mail) {
            $html = $mail->content()->htmlString;

            return str_contains($html, '<b>live</b>') && ! str_contains($html, '<script');
        });
    }

    public function test_status_can_be_closed_and_reopened(): void
    {
        $ticket = $this->ticket();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.support.status', $ticket), ['status' => 'closed']);
        $this->assertSame(SupportTicket::STATUS_CLOSED, $ticket->refresh()->status);

        $this->actingAs($admin)->post(route('admin.support.status', $ticket), ['status' => 'open']);
        $this->assertSame(SupportTicket::STATUS_OPEN, $ticket->refresh()->status);
    }
}
