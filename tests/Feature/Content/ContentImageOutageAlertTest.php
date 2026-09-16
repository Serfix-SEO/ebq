<?php

namespace Tests\Feature\Content;

use App\Mail\FailedJobsDigestMail;
use App\Models\ContentArticle;
use App\Models\ContentImage;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\IdeogramClient;
use App\Support\ContentImageHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Image generation must not be able to fail silently.
 *
 * Twice it has: the spend cap tripped on 2026-08-17 and 91 articles shipped
 * imageless, then the Ideogram token started answering 401 on 2026-09-11 and
 * ~250 articles across 16 clients shipped imageless over five days. Neither
 * produced an exception, a failed job, or a single alert — the client returns
 * ok:false and the job creates rows only on success, so the whole outage
 * existed as Log::warning lines nobody reads.
 *
 * The two blackouts broke in different places and neither detector would have
 * caught the other, so both are tested here.
 */
class ContentImageOutageAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ContentImageHealth::reset();
        Cache::forget('image-health-warned:'.now()->utc()->format('Y-m-d').':auth');
        Cache::forget('image-health-warned:'.now()->utc()->format('Y-m-d').':imageless');
        User::factory()->create(['is_admin' => true]);
    }

    /** @return ContentArticle a finished article on a plan that wants images */
    private function article(bool $withImage = false, bool $planWantsImages = true): ContentArticle
    {
        $website = Website::factory()->for(User::factory())->create();
        $plan = ContentPlan::factory()->create([
            'website_id' => $website->id,
            'images_enabled' => $planWantsImages,
        ]);
        $topic = ContentTopic::factory()->create(['plan_id' => $plan->id, 'website_id' => $website->id]);
        $article = ContentArticle::create([
            'topic_id' => $topic->id,
            'version' => 1,
            'is_current' => true,
            'h1' => 'A Finished Article',
            'html' => '<p>body</p>',
            'word_count' => 400,
        ]);

        if ($withImage) {
            ContentImage::create([
                'article_id' => $article->id,
                'role' => ContentImage::ROLE_FEATURED,
                'status' => ContentImage::STATUS_GENERATED,
                'disk_path' => 'content/x.png',
            ]);
        }

        return $article;
    }

    private function digest(): string
    {
        Mail::fake();
        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();

        $body = '';
        Mail::assertSent(FailedJobsDigestMail::class, function ($mail) use (&$body) {
            $body = (string) $mail->body;

            return true;
        });

        return $body;
    }

    private function noDigest(): void
    {
        Mail::fake();
        $this->artisan('ebq:failed-jobs-alert')->assertSuccessful();
        Mail::assertNotSent(FailedJobsDigestMail::class);
    }

    /**
     * The exact 2026-09-11 failure: the provider rejects our token. This must
     * alert on the FIRST occurrence — waiting for imageless articles to pile
     * up is how five days went by.
     */
    public function test_a_rejected_api_token_raises_the_alarm_immediately(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Access denied. Please verify your API Token is valid.'], 401)]);
        config(['services.ideogram.key' => 'whatever']);

        $out = app(IdeogramClient::class)->generate('a cat');

        $this->assertFalse($out['ok']);
        $this->assertNotNull(ContentImageHealth::authBlocked(), 'a 401 must be recorded as an auth block');

        $body = $this->digest();
        $this->assertStringContainsString('IMAGE GENERATION IS DOWN', $body);
        $this->assertStringContainsString('401', $body);
        $this->assertStringContainsString('IDEOGRAM_API_KEY', $body, 'the digest must say what to actually do');
        $this->assertStringContainsString('ebq:backfill-article-images', $body, 'and how to repair what already shipped');
    }

    /** A rotated key must silence the alarm with no admin action. */
    public function test_one_successful_image_clears_the_alarm(): void
    {
        ContentImageHealth::recordFailure('ideogram_http_401', 401);
        $this->assertNotNull(ContentImageHealth::authBlocked());

        config(['services.ideogram.key' => 'a-good-key']);
        Http::fake(['*' => Http::response(['data' => [['url' => 'https://example.test/i.png', 'seed' => 1]]], 200)]);

        $out = app(IdeogramClient::class)->generate('a cat');

        $this->assertTrue($out['ok']);
        $this->assertNull(ContentImageHealth::authBlocked());
        $this->noDigest();
    }

    /** The outage length must be the FIRST failure, not the latest retry. */
    public function test_the_reported_outage_starts_at_the_first_failure(): void
    {
        $this->travelTo(now()->subDays(3));
        ContentImageHealth::recordFailure('ideogram_http_401', 401);
        $this->travelBack();
        ContentImageHealth::recordFailure('ideogram_http_401', 401);

        $auth = ContentImageHealth::authBlocked();
        $this->assertSame(2, $auth['count']);
        // Carbon 3 returns a SIGNED difference, so compare the absolute age.
        $this->assertGreaterThanOrEqual(
            71,
            abs(now()->diffInHours(\Illuminate\Support\Carbon::parse($auth['since']))),
            'the outage must be dated from the first rejection, not the most recent one',
        );
    }

    /**
     * The 2026-08-17 shape: the spend meter returns BEFORE the client is ever
     * called, so there is no provider signal at all — only the articles.
     */
    public function test_a_pile_of_imageless_articles_alerts_even_with_no_provider_error(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->article();
        }

        $this->assertNull(ContentImageHealth::authBlocked(), 'no provider error in this scenario');

        $body = $this->digest();
        $this->assertStringContainsString('IMAGE GENERATION LOOKS DOWN', $body);
        $this->assertStringContainsString('spend cap', $body, 'the meter is the first thing to check in this shape');
    }

    /** A trickle is normal — a rejected render must not page anyone. */
    public function test_a_couple_of_imageless_articles_are_not_an_outage(): void
    {
        $this->article();
        $this->article();

        $this->noDigest();
    }

    /** Images off for a plan is a client's choice, not a fault. */
    public function test_plans_that_turned_images_off_are_not_counted(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->article(planWantsImages: false);
        }

        $this->noDigest();
    }

    public function test_articles_that_did_get_images_are_not_counted(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->article(withImage: true);
        }

        $this->noDigest();
    }

    /** The global kill-switch means "off on purpose" — silence, not an alarm. */
    public function test_the_platform_kill_switch_silences_the_alarm(): void
    {
        \App\Models\Setting::set('content.images.enabled', false);
        ContentImageHealth::recordFailure('ideogram_http_401', 401);

        $this->noDigest();
    }

    /** A five-day outage should nag daily, not every fifteen minutes. */
    public function test_the_alarm_repeats_at_most_once_a_day(): void
    {
        ContentImageHealth::recordFailure('ideogram_http_401', 401);

        $this->assertStringContainsString('IMAGE GENERATION IS DOWN', $this->digest());
        $this->noDigest();

        $this->travel(25)->hours();
        $this->assertStringContainsString('IMAGE GENERATION IS DOWN', $this->digest());
    }
}
