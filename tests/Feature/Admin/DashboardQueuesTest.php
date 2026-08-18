<?php

namespace Tests\Feature\Admin;

use App\Models\ContentArticleFeedback;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin home's two "waiting on us" queues: unreplied tickets, and client
 * article verdicts nobody has looked at.
 */
class DashboardQueuesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function ticket(string $status, string $subject): SupportTicket
    {
        $user = User::factory()->create(['is_admin' => false]);

        return SupportTicket::query()->create([
            'user_id' => $user->id,
            'subject' => $subject,
            'status' => $status,
            'last_reply_at' => now()->subHour(),
        ]);
    }

    private function feedback(string $rating, string $comment = '', ?string $seenAt = null): ContentArticleFeedback
    {
        $user = User::factory()->create(['is_admin' => false]);
        $website = Website::factory()->create(['user_id' => $user->id, 'domain' => 'example.org']);
        $plan = ContentPlan::query()->create(['website_id' => $website->id, 'status' => 'active']);
        $topic = ContentTopic::query()->create([
            'plan_id' => $plan->id, 'website_id' => $website->id,
            'title' => 'A topic '.$rating, 'target_keyword' => 'k', 'status' => 'ready',
        ]);

        return ContentArticleFeedback::query()->create([
            'topic_id' => $topic->id, 'website_id' => $website->id, 'user_id' => $user->id,
            'rating' => $rating, 'comment' => $comment, 'seen_at' => $seenAt,
        ]);
    }

    public function test_only_unreplied_tickets_are_listed(): void
    {
        $this->ticket(SupportTicket::STATUS_OPEN, 'Client is waiting');
        $this->ticket(SupportTicket::STATUS_ANSWERED, 'We already replied');
        $this->ticket(SupportTicket::STATUS_CLOSED, 'Done and dusted');

        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Client is waiting')
            ->assertDontSee('We already replied')
            ->assertDontSee('Done and dusted');
    }

    public function test_the_longest_waiting_ticket_comes_first(): void
    {
        $fresh = $this->ticket(SupportTicket::STATUS_OPEN, 'Just arrived');
        $fresh->forceFill(['last_reply_at' => now()->subMinutes(5)])->save();
        $stale = $this->ticket(SupportTicket::STATUS_OPEN, 'Waiting for days');
        $stale->forceFill(['last_reply_at' => now()->subDays(3)])->save();

        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->getContent();

        $this->assertLessThan(
            strpos($html, 'Just arrived'),
            strpos($html, 'Waiting for days'),
            'the oldest wait is the SLA risk and must sort first',
        );
    }

    public function test_unseen_feedback_shows_and_seen_feedback_does_not(): void
    {
        $this->feedback(ContentArticleFeedback::RATING_WRONG, 'This is not our business at all');
        $this->feedback(ContentArticleFeedback::RATING_LOVE, 'Already dealt with', seenAt: now()->toDateTimeString());

        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('This is not our business at all')
            ->assertDontSee('Already dealt with');
    }

    public function test_unhappy_feedback_sorts_above_praise(): void
    {
        $this->feedback(ContentArticleFeedback::RATING_LOVE, 'Loved this one');
        $this->feedback(ContentArticleFeedback::RATING_WRONG, 'Completely off base');

        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->getContent();

        $this->assertLessThan(
            strpos($html, 'Loved this one'),
            strpos($html, 'Completely off base'),
            'an unread complaint matters more than an unread compliment',
        );
    }

    public function test_marking_seen_removes_it_from_the_dashboard_without_deleting_it(): void
    {
        $f = $this->feedback(ContentArticleFeedback::RATING_REWRITES, 'Needs a tweak');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.content-feedback.seen', $f))
            ->assertRedirect(route('admin.dashboard'));

        $f->refresh();
        $this->assertNotNull($f->seen_at);
        $this->assertSame($admin->email, $f->seen_by);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertDontSee('Needs a tweak');
        // Still present for trend-reading on the full list.
        $this->assertSame(1, ContentArticleFeedback::query()->count());
        $this->actingAs($admin)->get(route('admin.content-feedback.index'))->assertSee('Needs a tweak');
    }

    public function test_marking_seen_twice_keeps_the_first_timestamp(): void
    {
        $f = $this->feedback(ContentArticleFeedback::RATING_LOVE);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.content-feedback.seen', $f));
        $first = $f->refresh()->seen_at;

        $this->travel(2)->minutes();
        $this->actingAs($admin)->post(route('admin.content-feedback.seen', $f));

        $this->assertEquals($first, $f->refresh()->seen_at);
    }

    public function test_a_non_admin_cannot_mark_feedback_seen(): void
    {
        $f = $this->feedback(ContentArticleFeedback::RATING_WRONG);

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->post(route('admin.content-feedback.seen', $f))
            ->assertForbidden();

        $this->assertNull($f->refresh()->seen_at);
    }

    public function test_empty_states_read_as_done_not_broken(): void
    {
        $this->actingAs($this->admin())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('every ticket has been answered')
            ->assertSee('all caught up');
    }

    public function test_each_row_links_to_something_an_admin_can_actually_open(): void
    {
        $ticket = $this->ticket(SupportTicket::STATUS_OPEN, 'Client is waiting');
        $f = $this->feedback(ContentArticleFeedback::RATING_WRONG, 'Competitor in the image');

        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->getContent();

        // Ticket → its thread.
        $this->assertStringContainsString(route('admin.support.show', $ticket), $html);
        // Feedback → the CLIENT's admin page. The article itself lives behind
        // the client's own route, which accessibleWebsitesQuery denies to
        // admins — linking there would 404.
        $this->assertStringContainsString(route('admin.clients.show', $f->user_id), $html);
    }

    public function test_the_mark_seen_form_is_not_nested_inside_the_row_link(): void
    {
        // A <form> inside an <a> is invalid HTML and the button stops
        // submitting — the row link and the form must stay siblings.
        $this->feedback(ContentArticleFeedback::RATING_LOVE);

        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->getContent();

        $this->assertDoesNotMatchRegularExpression('#<a\b[^>]*>(?:(?!</a>).)*<form#s', $html);
    }
}
