<?php

namespace Tests\Feature\Support;

use App\Mail\SupportTicketReplied;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Admin-initiated support threads. Tickets could previously only start with
 * the customer, so anything we initiated happened over email — outside the
 * thread the client can see, reply to and find again.
 */
class AdminOpensTicketTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function client(): User
    {
        return User::factory()->create(['is_admin' => false, 'email' => 'client@example.com', 'name' => 'Real Client']);
    }

    public function test_the_compose_page_lists_clients_and_excludes_lead_placeholders(): void
    {
        $this->client();
        // Funnel leads carry a synthetic address nobody reads.
        User::factory()->create(['is_admin' => false, 'email' => 'lead+01ABC@leads.serfix.internal']);

        $this->actingAs($this->admin())
            ->get(route('admin.support.create'))
            ->assertOk()
            ->assertSee('client@example.com')
            ->assertDontSee('leads.serfix.internal');
    }

    public function test_opening_a_ticket_creates_the_thread_and_emails_the_client(): void
    {
        Mail::fake();
        $client = $this->client();

        $this->actingAs($this->admin())
            ->post(route('admin.support.store'), [
                'user_id' => $client->id,
                'subject' => 'About your publishing setup',
                'body' => '<p>We noticed your site is not connected yet.</p>',
            ])
            ->assertRedirect();

        $ticket = SupportTicket::query()->firstOrFail();
        $this->assertSame($client->id, $ticket->user_id);
        $this->assertSame('About your publishing setup', $ticket->subject);
        // ANSWERED, not OPEN: we spoke last, so it must not sit in the
        // "customer is waiting on us" queue.
        $this->assertSame(SupportTicket::STATUS_ANSWERED, $ticket->status);

        $first = $ticket->messages()->first();
        $this->assertTrue((bool) $first->is_admin);
        $this->assertStringContainsString('not connected yet', $first->body);

        Mail::assertSent(SupportTicketReplied::class, fn ($m) => $m->isNew === true && $m->hasTo('client@example.com'));
    }

    public function test_the_client_sees_it_in_their_own_support_list_and_can_reply(): void
    {
        Mail::fake();
        $client = $this->client();

        $this->actingAs($this->admin())->post(route('admin.support.store'), [
            'user_id' => $client->id,
            'subject' => 'About your publishing setup',
            'body' => 'Quick question for you.',
        ]);

        $ticket = SupportTicket::query()->firstOrFail();

        $this->actingAs($client)
            ->get(route('support.index'))
            ->assertOk()
            ->assertSee('About your publishing setup');

        $this->actingAs($client)
            ->get(route('support.show', $ticket->id))
            ->assertOk()
            ->assertSee('Quick question for you.');
    }

    public function test_the_website_is_attached_only_when_the_client_has_exactly_one(): void
    {
        Mail::fake();
        $solo = $this->client();
        $site = Website::factory()->create(['user_id' => $solo->id, 'domain' => 'solo.example']);

        $this->actingAs($this->admin())->post(route('admin.support.store'), [
            'user_id' => $solo->id, 'subject' => 'S', 'body' => 'Body here',
        ]);
        $this->assertSame($site->id, SupportTicket::query()->firstOrFail()->website_id);

        // Two sites: guessing one would put the wrong context on the thread.
        $multi = User::factory()->create(['is_admin' => false, 'email' => 'multi@example.com']);
        Website::factory()->create(['user_id' => $multi->id, 'domain' => 'a.example']);
        Website::factory()->create(['user_id' => $multi->id, 'domain' => 'b.example']);

        $this->actingAs($this->admin())->post(route('admin.support.store'), [
            'user_id' => $multi->id, 'subject' => 'S2', 'body' => 'Body here',
        ]);
        $this->assertNull(SupportTicket::query()->where('subject', 'S2')->firstOrFail()->website_id);
    }

    public function test_markup_only_message_is_rejected(): void
    {
        Mail::fake();
        $client = $this->client();

        $this->actingAs($this->admin())
            ->post(route('admin.support.store'), [
                'user_id' => $client->id, 'subject' => 'S', 'body' => '<p><br></p>',
            ])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, SupportTicket::query()->count());
        Mail::assertNothingSent();
    }

    public function test_the_message_is_sanitized(): void
    {
        Mail::fake();
        $client = $this->client();

        $this->actingAs($this->admin())->post(route('admin.support.store'), [
            'user_id' => $client->id,
            'subject' => 'S',
            'body' => '<p>Hello <strong>there</strong></p><script>alert(1)</script><a href="javascript:evil()">x</a>',
        ]);

        $body = SupportTicket::query()->firstOrFail()->messages()->first()->body;
        $this->assertStringContainsString('<strong>there</strong>', $body);
        $this->assertStringNotContainsString('script', $body);
        $this->assertStringNotContainsString('javascript:', $body);
    }

    public function test_a_non_admin_cannot_open_a_ticket_for_someone_else(): void
    {
        Mail::fake();
        $victim = $this->client();
        $attacker = User::factory()->create(['is_admin' => false, 'email' => 'attacker@example.com']);

        $this->actingAs($attacker)
            ->post(route('admin.support.store'), [
                'user_id' => $victim->id, 'subject' => 'S', 'body' => 'Body here',
            ])
            ->assertForbidden();

        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_the_new_route_is_not_swallowed_by_the_ticket_route(): void
    {
        // /admin/support/new must resolve to the compose page, not be read as
        // a ticket id — route order is load-bearing here.
        $this->actingAs($this->admin())
            ->get('/admin/support/new')
            ->assertOk()
            ->assertSee('Open a ticket with a client');
    }
}
