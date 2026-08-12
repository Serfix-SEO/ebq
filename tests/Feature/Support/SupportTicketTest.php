<?php

namespace Tests\Feature\Support;

use App\Livewire\Support\Tickets;
use App\Livewire\Support\TicketThread;
use App\Mail\SupportTicketActivity;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function user(): User
    {
        $user = User::factory()->create();
        Website::factory()->for($user)->create();
        RateLimiter::clear('support-ticket:'.$user->id);

        return $user;
    }

    private function ticketFor(User $user, string $status = SupportTicket::STATUS_OPEN): SupportTicket
    {
        $ticket = SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => 'My article did not publish',
            'status' => $status,
            'last_reply_at' => now(),
        ]);
        $ticket->messages()->create(['user_id' => $user->id, 'is_admin' => false, 'body' => 'It never appeared on my site.']);

        return $ticket;
    }

    public function test_support_page_renders(): void
    {
        $this->actingAs($this->user())
            ->get(route('support.index'))
            ->assertOk()
            ->assertSee(__('Support'));
    }

    public function test_support_is_reachable_for_a_user_with_no_website(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('support.index'))
            ->assertOk();
    }

    public function test_creating_a_ticket_stores_thread_and_notifies_admins(): void
    {
        User::factory()->create(['is_admin' => true, 'email' => 'admin@serfix.io']);
        $user = $this->user();

        Livewire::actingAs($user)
            ->test(Tickets::class)
            ->set('subject', 'Publishing question')
            ->set('message', 'How do I connect my Shopify store to auto-publish?')
            ->call('create')
            ->assertHasNoErrors();

        $ticket = SupportTicket::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(SupportTicket::STATUS_OPEN, $ticket->status);
        $this->assertSame('Publishing question', $ticket->subject);
        $this->assertSame(1, $ticket->messages()->count());
        $this->assertFalse((bool) $ticket->messages()->first()->is_admin);

        Mail::assertSent(SupportTicketActivity::class, fn ($mail) => $mail->isNew === true
            && $mail->hasTo('admin@serfix.io'));
    }

    public function test_short_message_is_rejected(): void
    {
        Livewire::actingAs($this->user())
            ->test(Tickets::class)
            ->set('subject', 'Hi')
            ->set('message', 'help')
            ->call('create')
            ->assertHasErrors(['message']);

        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_list_shows_only_own_tickets(): void
    {
        $mine = $this->user();
        $other = $this->user();
        $this->ticketFor($mine);
        $foreign = $this->ticketFor($other);
        $foreign->forceFill(['subject' => 'Someone elses problem'])->save();

        Livewire::actingAs($mine)
            ->test(Tickets::class)
            ->assertSee('My article did not publish')
            ->assertDontSee('Someone elses problem');
    }

    public function test_cannot_open_another_users_ticket(): void
    {
        $foreign = $this->ticketFor($this->user());

        $this->actingAs($this->user())
            ->get(route('support.show', $foreign->id))
            ->assertNotFound();
    }

    public function test_reply_reopens_a_closed_ticket_and_notifies_admins(): void
    {
        User::factory()->create(['is_admin' => true, 'email' => 'admin@serfix.io']);
        $user = $this->user();
        $ticket = $this->ticketFor($user, SupportTicket::STATUS_CLOSED);

        Livewire::actingAs($user)
            ->test(TicketThread::class, ['ticketId' => $ticket->id])
            ->set('reply', 'Still broken after your fix, please check again.')
            ->call('send')
            ->assertHasNoErrors();

        $ticket->refresh();
        $this->assertSame(SupportTicket::STATUS_OPEN, $ticket->status);
        $this->assertSame(2, $ticket->messages()->count());

        Mail::assertSent(SupportTicketActivity::class, fn ($mail) => $mail->isNew === false);
    }

    public function test_client_can_close_their_ticket(): void
    {
        $user = $this->user();
        $ticket = $this->ticketFor($user);

        Livewire::actingAs($user)
            ->test(TicketThread::class, ['ticketId' => $ticket->id])
            ->call('close');

        $this->assertSame(SupportTicket::STATUS_CLOSED, $ticket->refresh()->status);
    }
}
