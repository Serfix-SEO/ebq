<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentCalendar;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Wizard step 1 gained a target-country selector (2026-07-22) so keyword
 * research, competitor discovery, and topic ideation localize to the right
 * market instead of silently defaulting to 'global'. These tests cover the
 * UI/validation/detection slice; ContentTopicPlannerGuardrailsTest covers the
 * ideation prompt, ContentKeywordInsightsTest/ClassifyPlanKeywordsJobTest
 * cover keyword research, and the CompetitorMentionGuardTest suite covers the
 * competitor-discovery geo param.
 */
class ContentWizardCountryTest extends TestCase
{
    use RefreshDatabase;

    private function userWithWebsite(string $domain = 'example.com'): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create(['normalized_domain' => $domain, 'domain' => $domain]);

        return [$user, $website];
    }

    public function test_step_one_shows_a_country_selector_seeded_from_keyword_finder_locations(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->assertSee('Target country')
            ->assertSee('United Arab Emirates')
            ->assertSee('All countries (Worldwide)');
    }

    public function test_step_one_defaults_country_to_global_for_a_generic_tld(): void
    {
        [$user, $website] = $this->userWithWebsite('example.com');
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        $component = Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('analyzeSite');

        $this->assertSame('global', $component->get('country'));
    }

    public function test_step_one_detects_country_from_a_real_cctld(): void
    {
        [$user, $website] = $this->userWithWebsite('mkccleaningservices.ae');
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        $component = Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('analyzeSite');

        $this->assertSame('ae', $component->get('country'));
    }

    /** .co/.io/.me etc. are squatted worldwide for branding — never guessed. */
    public function test_step_one_does_not_guess_from_a_generic_use_tld(): void
    {
        [$user, $website] = $this->userWithWebsite('mkccleaningservices.co');
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        $component = Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->call('analyzeSite');

        $this->assertSame('global', $component->get('country'));
    }

    public function test_step_one_to_two_rejects_an_unknown_country_code(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('businessDescription', 'A residential cleaning service serving the whole metro area.')
            ->set('country', 'not-a-real-code')
            ->call('toOfferings')
            ->assertHasErrors(['country']);
    }

    public function test_step_one_to_two_accepts_a_valid_country_and_advances(): void
    {
        [$user, $website] = $this->userWithWebsite();
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('businessDescription', 'A residential cleaning service serving the whole metro area.')
            ->set('country', 'ae')
            ->call('toOfferings')
            ->assertHasNoErrors()
            ->assertSet('wizardStep', 2);
    }

    /** A previously-saved plan's country reloads into step 1 on revisit. */
    public function test_existing_plan_country_reloads_into_the_wizard(): void
    {
        [$user, $website] = $this->userWithWebsite();
        ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_DRAFT,
            'country' => 'sa',
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->assertSet('country', 'sa');
    }
}
