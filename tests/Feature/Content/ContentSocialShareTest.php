<?php

namespace Tests\Feature\Content;

use App\Jobs\Content\ShareArticleToSocialJob;
use App\Models\ContentArticle;
use App\Models\ContentPlan;
use App\Models\ContentSocialAccount;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Social\SocialPoster;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Social auto-share: the job posts the REAL live URL to connected accounts,
 * never posts a dead/missing link, never reposts, and isolates per-network
 * failures. Http::fake everywhere — no test may reach a real social API.
 */
class ContentSocialShareTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_URL = 'https://client-site.example/blog/best-coffee-grinder';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        config(['services.facebook.client_id' => 'fb-app', 'services.facebook.client_secret' => 'fb-secret']);
        config(['services.x.client_id' => 'x-app', 'services.x.client_secret' => 'x-secret']);
        config(['services.pinterest.client_id' => 'pin-app', 'services.pinterest.client_secret' => 'pin-secret']);
    }

    /** @return array{0: Website, 1: ContentTopic} */
    private function publishedTopic(): array
    {
        $user = User::factory()->create();
        $website = Website::factory()->for($user)->create();
        $plan = ContentPlan::factory()->create(['website_id' => $website->id, 'status' => ContentPlan::STATUS_ACTIVE]);
        $topic = ContentTopic::factory()->create([
            'plan_id' => $plan->id,
            'website_id' => $website->id,
            'title' => 'Best Coffee Grinder',
            'status' => ContentTopic::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
        ContentArticle::storeVersion($topic, [
            'h1' => 'Best Coffee Grinder: The Complete Guide',
            'meta_description' => 'Everything about picking a grinder.',
            'html' => '<p>x</p>', 'seo_score' => 95, 'word_count' => 1500,
        ]);

        return [$website, $topic];
    }

    private function facebookAccount(Website $website, array $extra = []): ContentSocialAccount
    {
        return ContentSocialAccount::create(array_merge([
            'website_id' => $website->id,
            'provider' => ContentSocialAccount::PROVIDER_FACEBOOK,
            'credentials' => ['page_id' => '123', 'page_token' => 'pt', 'page_name' => 'My Page'],
            'status' => ContentSocialAccount::STATUS_CONNECTED,
            'display_name' => 'My Page',
        ], $extra));
    }

    private function xAccount(Website $website, array $extra = []): ContentSocialAccount
    {
        return ContentSocialAccount::create(array_merge([
            'website_id' => $website->id,
            'provider' => ContentSocialAccount::PROVIDER_X,
            'credentials' => ['access_token' => 'xt', 'refresh_token' => 'xr', 'expires_at' => now()->addHour()->timestamp, 'username' => 'client'],
            'status' => ContentSocialAccount::STATUS_CONNECTED,
            'display_name' => '@client',
        ], $extra));
    }

    private function pinterestAccount(Website $website, array $extra = []): ContentSocialAccount
    {
        return ContentSocialAccount::create(array_merge([
            'website_id' => $website->id,
            'provider' => ContentSocialAccount::PROVIDER_PINTEREST,
            'credentials' => [
                'access_token' => 'pt', 'refresh_token' => 'pr',
                'expires_at' => now()->addDays(20)->timestamp,
                'username' => 'client', 'board_id' => 'b-1', 'board_name' => 'Recipes',
            ],
            'status' => ContentSocialAccount::STATUS_CONNECTED,
            'display_name' => 'Recipes',
        ], $extra));
    }

    /** Give the topic's current article a featured image Pinterest can fetch. */
    private function withFeaturedImage(ContentTopic $topic, string $url = 'https://cdn.example/img/hero.png'): void
    {
        \App\Models\ContentArticle::query()
            ->where('topic_id', $topic->id)
            ->where('is_current', true)
            ->update(['og_image' => $url]);
    }

    public function test_pinterest_pins_the_article_image_with_its_link(): void
    {
        [$website, $topic] = $this->publishedTopic();
        $this->pinterestAccount($website);
        $this->withFeaturedImage($topic);

        Http::fake([
            self::LIVE_URL => Http::response('ok', 200),
            'cdn.example/*' => Http::response('', 200, ['Content-Type' => 'image/png']),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-1'], 201),
        ]);

        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'api.pinterest.com/v5/pins')) {
                return false;
            }

            return $request['board_id'] === 'b-1'
                && $request['link'] === self::LIVE_URL
                && str_contains((string) $request['title'], 'Best Coffee Grinder')
                && ($request['media_source']['source_type'] ?? '') === 'image_url'
                && ($request['media_source']['url'] ?? '') === 'https://cdn.example/img/hero.png';
        });
    }

    /** A Pin without an image is impossible — skip rather than fail the account. */
    public function test_pinterest_is_skipped_when_the_article_has_no_usable_image(): void
    {
        [$website, $topic] = $this->publishedTopic();
        $account = $this->pinterestAccount($website);

        Http::fake([
            self::LIVE_URL => Http::response('ok', 200),
            'api.pinterest.com/*' => Http::response(['id' => 'pin-1'], 201),
        ]);

        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v5/pins'));
        $account->refresh();
        $this->assertSame(ContentSocialAccount::STATUS_CONNECTED, $account->status, 'a missing image is not a broken connection');
        $this->assertStringContainsString('no image', (string) $account->last_error);
    }

    /** An image URL that is not actually an image would fail Pinterest's fetch. */
    public function test_pinterest_is_skipped_when_the_image_url_is_not_an_image(): void
    {
        [$website, $topic] = $this->publishedTopic();
        $this->pinterestAccount($website);
        $this->withFeaturedImage($topic, 'https://cdn.example/img/gone.png');

        Http::fake([
            self::LIVE_URL => Http::response('ok', 200),
            'cdn.example/*' => Http::response('<html>not found</html>', 404, ['Content-Type' => 'text/html']),
            'api.pinterest.com/*' => Http::response(['id' => 'pin-1'], 201),
        ]);

        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v5/pins'));
    }

    /** Pinterest tokens live ~30 days; a stale one refreshes before pinning. */
    public function test_expired_pinterest_token_refreshes_then_pins(): void
    {
        [$website, $topic] = $this->publishedTopic();
        $account = $this->pinterestAccount($website, [
            'credentials' => [
                'access_token' => 'old', 'refresh_token' => 'pr',
                'expires_at' => now()->subMinute()->timestamp,
                'board_id' => 'b-1', 'board_name' => 'Recipes',
            ],
        ]);
        $this->withFeaturedImage($topic);

        Http::fake([
            self::LIVE_URL => Http::response('ok', 200),
            'cdn.example/*' => Http::response('', 200, ['Content-Type' => 'image/png']),
            'api.pinterest.com/v5/oauth/token' => Http::response(['access_token' => 'new', 'expires_in' => 2592000], 200),
            'api.pinterest.com/v5/pins' => Http::response(['id' => 'pin-2'], 201),
        ]);

        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v5/oauth/token'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v5/pins')
            && $request->hasHeader('Authorization', 'Bearer new'));

        $account->refresh();
        // Pinterest does not rotate refresh tokens; the stored one must survive.
        $this->assertSame('pr', (string) $account->credentials['refresh_token']);
    }

    /** One network failing must never hold up the others. */
    public function test_a_missing_image_does_not_stop_x_from_posting(): void
    {
        [$website, $topic] = $this->publishedTopic();
        $this->xAccount($website);
        $this->pinterestAccount($website);

        Http::fake([
            self::LIVE_URL => Http::response('ok', 200),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '789']], 201),
            'api.pinterest.com/*' => Http::response(['id' => 'pin-1'], 201),
        ]);

        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.x.com/2/tweets'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v5/pins'));
        // X posted, so the once-per-topic stamp still lands.
        $this->assertNotEmpty(($topic->fresh()->meta ?? [])['social_shared_at'] ?? null);
    }

    public function test_posts_link_to_both_networks_and_stamps_once(): void
    {
        [$website, $topic] = $this->publishedTopic();
        $this->facebookAccount($website);
        $this->xAccount($website);

        Http::fake([
            self::LIVE_URL => Http::response('ok', 200),
            'graph.facebook.com/*' => Http::response(['id' => '123_456'], 200),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '789']], 201),
        ]);

        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com')
                && str_contains($request->url(), '/123/feed')
                && $request['link'] === self::LIVE_URL
                && str_contains((string) $request['message'], 'Best Coffee Grinder');
        });
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.x.com/2/tweets')
                && str_contains((string) $request['text'], self::LIVE_URL);
        });

        $meta = (array) $topic->fresh()->meta;
        $this->assertNotEmpty($meta['social_shared_at'] ?? null);
        $this->assertSame('posted', $meta['social_share_results']['facebook'] ?? null);

        // Second run: once-guard — no further social calls.
        Http::fake(); // reset recorder; anything sent now would be recorded fresh
        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));
        Http::assertNothingSent();
    }

    public function test_x_text_stays_within_the_character_budget(): void
    {
        $longTitle = str_repeat('Very Long Keyword Title ', 30);
        $text = SocialPoster::compose('x', $longTitle, '', self::LIVE_URL);
        // t.co wraps any URL to 23 chars regardless of its real length.
        $effective = mb_strlen(str_replace(self::LIVE_URL, str_repeat('x', 23), $text));
        $this->assertLessThanOrEqual(280, $effective);
        $this->assertStringContainsString(self::LIVE_URL, $text);
    }

    public function test_dead_link_is_never_shared(): void
    {
        [$website, $topic] = $this->publishedTopic();
        $this->facebookAccount($website);

        Http::fake([self::LIVE_URL => Http::response('missing', 404)]);

        $job = new ShareArticleToSocialJob($topic->id, self::LIVE_URL);
        $job->tries = 1; // exhaust immediately, no release
        $job->handle(app(SocialPoster::class));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
        $this->assertEmpty(((array) $topic->fresh()->meta)['social_shared_at'] ?? null);
    }

    public function test_disabled_account_is_skipped(): void
    {
        [$website, $topic] = $this->publishedTopic();
        $this->facebookAccount($website, ['share_enabled' => false]);

        Http::fake([self::LIVE_URL => Http::response('ok', 200)]);

        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'graph.facebook.com'));
    }

    public function test_revoked_facebook_token_flips_account_to_reconnect_without_blocking_x(): void
    {
        [$website, $topic] = $this->publishedTopic();
        $fb = $this->facebookAccount($website);
        $this->xAccount($website);

        Http::fake([
            self::LIVE_URL => Http::response('ok', 200),
            'graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'expired']], 401),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '789']], 201),
        ]);

        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));

        $this->assertSame(ContentSocialAccount::STATUS_ERROR, $fb->fresh()->status);
        $this->assertNotEmpty($fb->fresh()->last_error);
        // X still posted, and the topic is stamped shared.
        $meta = (array) $topic->fresh()->meta;
        $this->assertSame('posted', $meta['social_share_results']['x'] ?? null);
        $this->assertSame('reconnect', $meta['social_share_results']['facebook'] ?? null);
    }

    public function test_expired_x_token_refreshes_then_posts(): void
    {
        [$website, $topic] = $this->publishedTopic();
        $this->xAccount($website, ['credentials' => [
            'access_token' => 'stale', 'refresh_token' => 'xr',
            'expires_at' => now()->subMinute()->timestamp, 'username' => 'client',
        ]]);

        Http::fake([
            self::LIVE_URL => Http::response('ok', 200),
            'api.x.com/2/oauth2/token' => Http::response(['access_token' => 'fresh', 'refresh_token' => 'xr2', 'expires_in' => 7200], 200),
            'api.x.com/2/tweets' => Http::response(['data' => ['id' => '789']], 201),
        ]);

        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2/token'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/2/tweets') && $r->header('Authorization')[0] === 'Bearer fresh');
        $account = ContentSocialAccount::where('provider', 'x')->first();
        $this->assertSame('xr2', ((array) $account->credentials)['refresh_token']);
    }

    public function test_kill_switch_stops_sharing(): void
    {
        config(['services.content_autopilot.social_sharing' => false]);
        [$website, $topic] = $this->publishedTopic();
        $this->facebookAccount($website);

        Http::fake();
        (new ShareArticleToSocialJob($topic->id, self::LIVE_URL))->handle(app(SocialPoster::class));
        Http::assertNothingSent();
    }
}
