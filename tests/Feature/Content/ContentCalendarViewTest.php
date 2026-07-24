<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
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
}
