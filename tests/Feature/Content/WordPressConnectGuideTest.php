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
        // The real WordPress button reads "Add Application Password".
        $this->assertStringContainsString('Add Application Password', $html);
        // The username trips people up: it is often an email, never the display name.
        $this->assertStringContainsString('Find your WordPress username', $html);
        // Shown once: the single most common way this goes wrong.
        $this->assertStringContainsString('It appears once', $html);
        // The wrong button one section up resets their real login password.
        $this->assertStringContainsString('Do NOT use “Set New Password”', $html);
        // The username is often an email and is never the display name.
        $this->assertStringContainsString('it is NOT the same as your display name', $html);
    }

    /**
     * The failures that actually generate support requests: no HTTPS, old WP,
     * or a security plugin killing the REST API. Losing these turns a
     * self-serve connect into a support ticket.
     */
    public function test_it_answers_why_the_section_might_be_missing(): void
    {
        $html = $this->connectPanel()->html();

        $this->assertStringContainsString('Don’t see “Application Passwords”, or it won’t connect?', $html);
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

    /**
     * A failed connection must not collapse the instructions — the steps are
     * the answer to nearly every cause, so the guide reopens with a lead-in
     * instead of leaving the raw API error as the last word.
     */
    public function test_a_failed_connection_reopens_the_guide(): void
    {
        $panel = $this->connectPanel()
            ->set('wpSiteUrl', 'https://not-a-real-wordpress-site.invalid')
            ->set('wpUsername', 'someone')
            ->set('wpAppPassword', 'abcd EFGH 1234 wxyz')
            ->call('connect');

        $panel->assertHasErrors('connect');
        $html = $panel->html();

        $this->assertStringContainsString('The connection didn’t go through', $html);
        // Keyed on the error state so Livewire replaces the node and the
        // `open` attribute applies again even if the user had collapsed it.
        $this->assertStringContainsString('wire:key="wp-guide-error"', $html);
    }

    /**
     * Screenshots are optional: a missing file falls back to a drawn SVG of
     * the same screen, so a lost asset never leaves a step unillustrated.
     */
    public function test_every_screen_is_illustrated_with_a_screenshot_or_a_fallback(): void
    {
        $html = $this->connectPanel()->html();

        foreach ([
            '01-application-passwords.png' => '<svg viewBox="0 0 420 190"',
            '02-password-revealed.png' => '<svg viewBox="0 0 420 96"',
        ] as $file => $fallback) {
            if (is_file(public_path('guide/wordpress/'.$file))) {
                $this->assertStringContainsString($file, $html, "the {$file} screenshot renders");
            } else {
                $this->assertStringContainsString($fallback, $html, "the drawn fallback for {$file} renders");
            }
        }
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
