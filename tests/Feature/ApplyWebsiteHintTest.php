<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the `?ebq_site=<domain>` website hint
 * (App\Http\Middleware\ApplyWebsiteHint, added 2026-07-11).
 *
 * The WordPress plugin's raw portal links (editor sidebar: rank-tracking,
 * custom-audit, page-audits, settings; dashboard-widget fallback hrefs)
 * carry no website identity, so they used to open whatever website the
 * Serfix session last had selected — the wrong site whenever the account
 * has several. The hint switches the session website, but only among
 * websites the authenticated user can already access.
 */
class ApplyWebsiteHintTest extends TestCase
{
    use RefreshDatabase;

    public function test_hint_switches_session_to_the_matching_accessible_website(): void
    {
        $user = User::factory()->create();
        $first = Website::factory()->create(['user_id' => $user->id, 'domain' => 'first-site.com']);
        $second = Website::factory()->create(['user_id' => $user->id, 'domain' => 'second-site.com']);

        // Scheme + www + path must all normalize away (CrawlSite::normalizeDomain).
        $this->actingAs($user)
            ->withSession(['current_website_id' => (string) $first->id])
            ->get('/dashboard?ebq_site='.urlencode('https://www.second-site.com/wp-admin/'));

        $this->assertSame((string) $second->id, session('current_website_id'));
    }

    public function test_hint_for_an_inaccessible_website_is_ignored(): void
    {
        $user = User::factory()->create();
        $mine = Website::factory()->create(['user_id' => $user->id, 'domain' => 'mine.com']);
        $other = User::factory()->create();
        Website::factory()->create(['user_id' => $other->id, 'domain' => 'not-mine.com']);

        $this->actingAs($user)
            ->withSession(['current_website_id' => (string) $mine->id])
            ->get('/dashboard?ebq_site=not-mine.com');

        $this->assertSame((string) $mine->id, session('current_website_id'));
    }

    public function test_hint_works_for_a_team_member(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $shared = Website::factory()->create(['user_id' => $owner->id, 'domain' => 'shared-site.com']);
        $own = Website::factory()->create(['user_id' => $member->id, 'domain' => 'member-own.com']);
        $shared->members()->attach($member->id, ['role' => 'member']);

        $this->actingAs($member)
            ->withSession(['current_website_id' => (string) $own->id])
            ->get('/dashboard?ebq_site=shared-site.com');

        $this->assertSame((string) $shared->id, session('current_website_id'));
    }

    public function test_guest_request_with_hint_does_not_error(): void
    {
        $response = $this->get('/dashboard?ebq_site=anything.com');

        $response->assertRedirect(); // to login; hint is a silent no-op
        $this->assertNull(session('current_website_id'));
    }
}
