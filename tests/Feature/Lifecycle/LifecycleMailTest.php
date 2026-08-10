<?php

namespace Tests\Feature\Lifecycle;

use App\Mail\LifecycleMail;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LifecycleMailTest extends TestCase
{
    use RefreshDatabase;

    private function mailable(int $segment, string $stage, ?Website $website = null): LifecycleMail
    {
        $user = User::factory()->create(['name' => 'Jamie Founder']);

        return new LifecycleMail($user, $segment, $stage, $website, 'https://serfix.io/email/unsubscribe/x?signature=y');
    }

    public function test_subjects_match_the_owner_copy(): void
    {
        $expected = [
            [1, 'initial', 'Quick question about your SERFIX experience'],
            [1, 'followup', 'If SERFIX could handle one thing for you…'],
            [2, 'initial', 'Ready to see what SERFIX fixes?'],
            [2, 'followup', 'Quick question, and no survey attached 😅'],
            [3, 'initial', 'Your content plan is almost ready'],
            [3, 'followup', 'Did something get in the way?'],
            [4, 'initial', 'Ready to connect SERFIX to your website?'],
            [4, 'followup', 'What are you using to manage your website?'],
        ];

        foreach ($expected as [$segment, $stage, $subject]) {
            $this->assertSame($subject, $this->mailable($segment, $stage)->subjectLine());
        }
    }

    public function test_reply_to_is_fuaad(): void
    {
        $envelope = $this->mailable(2, 'initial')->envelope();

        $this->assertSame('fuaad@serfix.io', $envelope->replyTo[0]->address);
        $this->assertSame('Fuaad from SERFIX', $envelope->replyTo[0]->name);
    }

    public function test_headers_carry_segment_and_unsubscribe(): void
    {
        $headers = $this->mailable(3, 'initial')->headers();

        $this->assertSame('3', $headers->text['X-EBQ-Lifecycle-Segment']);
        $this->assertSame('initial', $headers->text['X-EBQ-Lifecycle-Stage']);
        $this->assertStringContainsString('unsubscribe', $headers->text['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers->text['List-Unsubscribe-Post']);
    }

    public function test_cta_urls_deep_link_with_site_pin(): void
    {
        $site = Website::factory()->for(User::factory())->create(['domain' => 'client-site.com']);

        $this->assertSame(route('content.get-started'), $this->mailable(2, 'initial')->ctaUrl());
        $this->assertSame(
            route('content.index').'?ebq_site='.urlencode($site->normalized_domain),
            $this->mailable(3, 'initial', $site)->ctaUrl(),
        );
        $this->assertSame(
            route('content.integrations').'?ebq_site='.urlencode($site->normalized_domain),
            $this->mailable(4, 'initial', $site)->ctaUrl(),
        );
    }

    public function test_segment_1_and_followups_have_no_cta(): void
    {
        $this->assertNull($this->mailable(1, 'initial')->ctaUrl());
        $this->assertNull($this->mailable(2, 'followup')->ctaUrl());
        $this->assertNull($this->mailable(3, 'followup')->ctaUrl());
        $this->assertNull($this->mailable(4, 'followup')->ctaUrl());
    }

    public function test_body_renders_greeting_copy_cta_and_unsubscribe(): void
    {
        $site = Website::factory()->for(User::factory())->create();
        $html = $this->mailable(4, 'initial', $site)->render();

        $this->assertStringContainsString('Hi Jamie,', $html);
        $this->assertStringContainsString('Connect My Website', $html);
        $this->assertStringContainsString('WordPress plugin', $html);
        $this->assertStringContainsString('Fuaad from SERFIX', $html);
        $this->assertStringContainsString('unsubscribe', strtolower($html));
    }

    public function test_renders_under_arabic_locale_without_errors(): void
    {
        $user = User::factory()->create(['name' => 'Jamie', 'locale' => 'ar']);
        $html = (new LifecycleMail($user, 2, 'initial', null, null))->render();

        $this->assertNotSame('', $html);
        $this->assertStringContainsString('SERFIX', $html);
    }
}
