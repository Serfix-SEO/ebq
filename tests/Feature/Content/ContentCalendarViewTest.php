<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Support\ContentAutopilotConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContentCalendarViewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression (prod 2026-07-23): the calendar view's "What your audience is
     * searching for" card iterated `$audience` — but the component's public
     * string $audience (wizard step-1 "Who is this for?", offer-spine) wins
     * Livewire's view-data merge and shadowed the array the calendar passed,
     * so any user with a non-empty audience text got a foreach-on-string 500
     * on /content. The card now binds `$audienceSearches`.
     */
    public function test_calendar_renders_with_a_non_empty_audience_profile_string(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create([
            'normalized_domain' => 'example.com', 'domain' => 'example.com',
        ]);
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'audience' => 'busy homeowners in Dubai who want a spotless house',
        ]);
        ContentTopic::factory()->create([
            'plan_id' => $plan->id,
            'status' => ContentTopic::STATUS_SUGGESTED,
            'target_keyword' => 'deep cleaning dubai',
            'keyword_volume' => 900,
            'scheduled_for' => now(),
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->assertOk();
    }

    /**
     * "Write now" without content access sends the user to the CONTENT plans,
     * not the SEO ones. The two products are bought separately, so an upsell
     * that lands on the dashboard billing page asks for money that would not
     * unlock the button they just pressed (reported 2026-08-06).
     */
    public function test_write_now_without_content_access_offers_the_content_plans(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create([
            'normalized_domain' => 'example.com', 'domain' => 'example.com',
        ]);
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
        ]);
        $topic = ContentTopic::factory()->create([
            'plan_id' => $plan->id,
            'status' => ContentTopic::STATUS_SUGGESTED,
            'scheduled_for' => now(),
        ]);

        // No subscription, no trial, no comp: blockReason() -> 'no_access'.
        $this->assertFalse($user->hasContentAccess());

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->call('writeNow', $topic->id)
            ->assertRedirect(route('content.get-started'));

        $this->assertNotSame(
            ContentTopic::STATUS_APPROVED,
            $topic->fresh()->status,
            'a blocked topic must not be queued for generation',
        );
    }

    /**
     * The whole calendar card is clickable, not just the 2-line title —
     * users rage-clicked the image and card body (2026-08-08). Every card
     * carries the role=link click handler targeting its topic page, plus the
     * opening spinner that makes the slow navigation visible.
     */
    public function test_calendar_cards_are_fully_clickable_with_opening_feedback(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create([
            'normalized_domain' => 'example.com', 'domain' => 'example.com',
        ]);
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
        ]);
        $topic = ContentTopic::factory()->create([
            'plan_id' => $plan->id,
            'status' => ContentTopic::STATUS_SUGGESTED,
            'scheduled_for' => now(),
        ]);

        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);
        $html = Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])->html();

        $this->assertStringContainsString('role="link"', $html, 'cards are announced as links');
        $this->assertStringContainsString(route('content.review', $topic->id), $html, 'card targets the topic page');
        $this->assertStringContainsString('opening = true', $html, 'click sets the opening spinner');
    }

    /**
     * The monthly cap marks articles the plan will NOT generate. A month that
     * lands exactly on the allowance is fully covered, so nothing is flagged —
     * the ranking used >= and painted the last covered article red with a
     * "won't be generated" banner (2026-07-30).
     */
    public function test_a_month_exactly_on_the_article_cap_is_not_flagged_as_over_limit(): void
    {
        $cap = ContentAutopilotConfig::monthlyArticlesPerWebsite();
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
        ]);
        // Hours, not days: a 30-day month would push the (cap+1)-th topic into
        // NEXT month, where the ranking correctly ignores it.
        for ($i = 0; $i < $cap; $i++) {
            ContentTopic::factory()->create([
                'plan_id' => $plan->id,
                'website_id' => $website->id,
                'status' => ContentTopic::STATUS_APPROVED,
                'scheduled_for' => now()->startOfMonth()->addHours($i),
            ]);
        }
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->assertOk()
            ->assertViewHas('overCapIds', []);

        // One more, and only that one is flagged.
        $extra = ContentTopic::factory()->create([
            'plan_id' => $plan->id,
            'website_id' => $website->id,
            'status' => ContentTopic::STATUS_APPROVED,
            'scheduled_for' => now()->startOfMonth()->addHours($cap),
        ]);

        Livewire::test(ContentCalendar::class, ['mode' => 'calendar'])
            ->assertOk()
            ->assertViewHas('overCapIds', [$extra->id]);
    }
}
