<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\PublishingSettings;
use App\Models\ContentIntegration;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The illustrated "how to connect WordPress" walkthrough.
 *
 * Connecting a site is the step between paying for the product and getting
 * anything out of it, and it hinges on an Application Password — a WordPress
 * feature most customers have never heard of. The guide used to be one
 * sentence.
 */
class WordPressConnectGuideTest extends TestCase
{
    use RefreshDatabase;

    private function connectPanel(): Testable
    {
        $this->seed(PlanSeeder::class);
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        app(ContentEntitlements::class)->startTrial($user, $website);
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);

        return Livewire::test(PublishingSettings::class)->set('showConnect', true);
    }

    public function test_the_walkthrough_covers_every_step_of_getting_the_password(): void
    {
        $html = $this->connectPanel()->html();

        $this->assertStringContainsString('How to get your WordPress application password', $html);
        // The menu path in WORDS — that is what people search for, and the
        // illustrations alone would not survive a different WP version.
        $this->assertStringContainsString('Users', $html);
        $this->assertStringContainsString('Application Passwords', $html);
        $this->assertStringContainsString('Add New Application Password', $html);
        // Shown once: the single most common way this goes wrong.
        $this->assertStringContainsString('WordPress shows it ONCE', $html);
        $this->assertStringContainsString('Use your login username', $html);
    }

    /**
     * The failures that actually generate support requests: no HTTPS, old WP,
     * or a security plugin killing the REST API. Losing these turns a
     * self-serve connect into a support ticket.
     */
    public function test_it_answers_why_the_section_might_be_missing(): void
    {
        $html = $this->connectPanel()->html();

        $this->assertStringContainsString('Don’t see “Application Passwords”?', $html);
        $this->assertStringContainsString('HTTPS', $html);
        $this->assertStringContainsString('5.6', $html);
        $this->assertStringContainsString('Wordfence', $html);
    }

    /** People hesitate to hand over a password; say plainly what it can't do. */
    public function test_it_explains_the_password_cannot_be_used_to_log_in(): void
    {
        $this->assertStringContainsString(
            'it cannot be used to sign in to wp-admin',
            $this->connectPanel()->html(),
        );
    }

    /** The illustrations are inline SVG — no external asset can 404 here. */
    public function test_the_screens_are_illustrated_inline(): void
    {
        $html = $this->connectPanel()->html();

        $this->assertStringContainsString('<svg viewBox="0 0 420 190"', $html, 'the profile screen');
        $this->assertStringContainsString('<svg viewBox="0 0 420 96"', $html, 'the password reveal');
        $this->assertStringContainsString('role="img"', $html, 'illustrations are announced to screen readers');
        $this->assertStringNotContainsString('<img', $html, 'no external screenshot to break');
    }

    /** The guide belongs to the WordPress tab, not the webhook/Laravel ones. */
    public function test_the_guide_is_scoped_to_the_wordpress_tab(): void
    {
        $webhook = $this->connectPanel()
            ->call('selectPlatform', ContentIntegration::PLATFORM_WEBHOOK)
            ->html();

        $this->assertStringNotContainsString('How to get your WordPress application password', $webhook);
    }
}
