<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\ContentCalendar;
use App\Models\ContentAuthor;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The author entity: the one thing a client supplies that an answer engine can
 * verify. The rule throughout — we never invent a person.
 */
class AeoAuthorAndSchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
    }

    /** @return array{User, Website, ContentPlan} */
    private function fixture(): array
    {
        $user = User::factory()->create(['content_comp_sites' => 1]);
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'status' => ContentPlan::STATUS_ACTIVE,
            'business_description' => 'A UAE fragrance retailer selling oud and modern perfume.',
        ]);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        return [$user, $website, $plan];
    }

    public function test_a_client_can_publish_under_a_named_expert(): void
    {
        [, $website] = $this->fixture();

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('authorName', 'Sara Malik')
            ->set('authorRole', 'Head Perfumer')
            ->set('authorBio', 'Twelve years blending oud.')
            ->set('authorCredentials', 'IFRA certified')
            ->set('authorSameAs', "https://www.linkedin.com/in/saramalik\nnonsense\nhttps://x.com/saramalik")
            ->set('orgLegalName', 'Bellavest Trading LLC')
            ->set('orgSameAs', 'https://instagram.com/bellavest')
            ->set('authorBoxEnabled', true)
            ->call('saveSettings');

        $author = ContentAuthor::where('website_id', $website->id)->first();
        $this->assertNotNull($author);
        $this->assertSame('Sara Malik', $author->name);
        $this->assertSame('Sara Malik, Head Perfumer', $author->byline());
        // Junk lines are dropped rather than published as a profile link.
        $this->assertSame(
            ['https://www.linkedin.com/in/saramalik', 'https://x.com/saramalik'],
            $author->sameAsUrls()
        );

        $plan = ContentPlan::where('website_id', $website->id)->first();
        $this->assertSame('Bellavest Trading LLC', $plan->org_legal_name);
        $this->assertSame(['https://instagram.com/bellavest'], $plan->org_same_as);
        $this->assertTrue($plan->toggle('author_box'));
    }

    public function test_clearing_the_name_removes_the_author_rather_than_leaving_a_ghost(): void
    {
        [, $website] = $this->fixture();
        ContentAuthor::create(['website_id' => $website->id, 'name' => 'Sara Malik']);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('authorName', '')
            ->call('saveSettings');

        $this->assertSame(0, ContentAuthor::where('website_id', $website->id)->count());
    }

    public function test_the_author_box_cannot_be_switched_on_without_an_author(): void
    {
        [, $website] = $this->fixture();

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->set('authorName', '')
            ->set('authorBoxEnabled', true)
            ->call('saveSettings');

        // An author box with nobody in it would print an empty byline, so the
        // toggle only takes effect once there is a real person.
        $this->assertFalse(ContentPlan::where('website_id', $website->id)->first()->toggle('author_box'));
    }

    public function test_the_settings_form_is_reachable_and_shows_saved_values(): void
    {
        [, $website] = $this->fixture();
        ContentAuthor::create([
            'website_id' => $website->id, 'name' => 'Sara Malik', 'role' => 'Head Perfumer',
        ]);

        Livewire::test(ContentCalendar::class, ['mode' => 'settings'])
            ->assertSet('authorName', 'Sara Malik')
            ->assertSet('authorRole', 'Head Perfumer')
            ->assertSee(__('Author & business'))
            ->assertSee(__('Profile links'));
    }
}
