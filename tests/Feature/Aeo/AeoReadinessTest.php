<?php

namespace Tests\Feature\Aeo;

use App\Models\ContentAeoAudit;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Aeo\AeoReadinessService;
use App\Services\Crawler\CrawlFetcher;
use App\Support\Aeo\AiAgents;
use App\Support\Crawler\RobotsTxtParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Can the AI answer engines read this site?"
 *
 * The check that matters here is the one no existing caller ever made: every
 * RobotsTxtParser call in the codebase asks about Googlebot, and a site can
 * welcome Googlebot while shutting out GPTBot entirely.
 */
class AeoReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Website
    {
        return Website::factory()->for(User::factory())->create([
            'domain' => 'example.com', 'normalized_domain' => 'example.com',
        ]);
    }

    /** Stub the fetcher so no test ever reaches the network. */
    private function fetcher(?string $robots, ?string $llms = null): CrawlFetcher
    {
        $stub = new class($robots, $llms) extends CrawlFetcher
        {
            public function __construct(private ?string $robots, private ?string $llms)
            {
                // Deliberately skips parent::__construct — nothing but fetch() is used.
            }

            public function fetch(string $url, array $conditional = [], int $timeout = 20, ?string $proxy = null): array
            {
                $body = str_contains($url, 'robots.txt') ? $this->robots : $this->llms;
                if ($body === null) {
                    return ['ok' => false, 'status' => 503, 'body' => ''];
                }

                return ['ok' => true, 'status' => 200, 'body' => $body];
            }
        };

        return $stub;
    }

    public function test_a_site_that_welcomes_google_can_still_block_the_ai_crawlers(): void
    {
        $robots = <<<'TXT'
        User-agent: *
        Allow: /

        User-agent: GPTBot
        Disallow: /

        User-agent: PerplexityBot
        Disallow: /
        TXT;

        $audit = (new AeoReadinessService($this->fetcher($robots)))->audit($this->site());

        $this->assertNotNull($audit);
        $this->assertFalse(RobotsTxtParser::isBlocked($robots, '/'), 'Googlebot is welcome — that is the trap this feature exists for.');
        $this->assertSame(ContentAeoAudit::BLOCKED, $audit->robots_ai['gptbot']);
        $this->assertSame(ContentAeoAudit::BLOCKED, $audit->robots_ai['perplexitybot']);
        $this->assertSame(ContentAeoAudit::ALLOWED, $audit->robots_ai['claudebot']);
        $this->assertContains('gptbot', $audit->blockedAgents());
    }

    public function test_a_blanket_disallow_blocks_every_ai_agent(): void
    {
        $audit = (new AeoReadinessService($this->fetcher("User-agent: *\nDisallow: /")))->audit($this->site());

        foreach (AiAgents::all() as $id => $agent) {
            $this->assertSame(ContentAeoAudit::BLOCKED, $audit->robots_ai[$id], $agent['label'].' should be blocked');
        }
        $this->assertLessThan(50, $audit->readiness_score);
    }

    public function test_no_robots_file_means_everyone_is_allowed(): void
    {
        // A 404 is a complete answer: there is no robots.txt, so nothing is blocked.
        $audit = (new AeoReadinessService($this->fetcher('')))->audit($this->site());

        $this->assertTrue($audit->robots_fetched);
        $this->assertSame(ContentAeoAudit::ALLOWED, $audit->robots_ai['gptbot']);
    }

    public function test_an_unreachable_robots_file_is_unknown_never_allowed(): void
    {
        $audit = (new AeoReadinessService($this->fetcher(null)))->audit($this->site());

        $this->assertFalse($audit->robots_fetched);
        foreach ($audit->robots_ai as $verdict) {
            $this->assertSame(ContentAeoAudit::UNKNOWN, $verdict);
        }
        // The robots checks drop out of the score instead of failing it — we do
        // not punish a client for our own failed request.
        $codes = array_column($audit->breakdown, 'code');
        $this->assertNotContains('robots_search', $codes);
    }

    public function test_an_llms_file_is_detected_and_scored(): void
    {
        $with = (new AeoReadinessService($this->fetcher("User-agent: *\nAllow: /", "# Example\n> A shop")))->audit($this->site());
        $without = (new AeoReadinessService($this->fetcher("User-agent: *\nAllow: /", null)))->audit($this->site());

        $this->assertTrue($with->llms_txt_present);
        $this->assertSame('https://example.com/llms.txt', $with->llms_txt_url);
        $this->assertFalse($without->llms_txt_present);
        $this->assertGreaterThan($without->readiness_score, $with->readiness_score);
    }

    public function test_a_soft_404_is_not_counted_as_an_llms_file(): void
    {
        // Plenty of hosts answer 200 with the homepage for any unknown path.
        $html = "<!DOCTYPE html>\n<html><head><title>Not found</title></head><body>Nope</body></html>";
        $audit = (new AeoReadinessService($this->fetcher("User-agent: *\nAllow: /", $html)))->audit($this->site());

        $this->assertFalse($audit->llms_txt_present);
        $this->assertNull($audit->llms_txt_url);
    }

    public function test_policy_only_tokens_are_marked_as_never_crawling(): void
    {
        // Google-Extended and Applebot-Extended only ever appear in robots.txt;
        // they never fetch a page, so the UI must not show them as "never seen".
        $this->assertFalse(AiAgents::all()['google_extended']['crawls']);
        $this->assertFalse(AiAgents::all()['applebot_extended']['crawls']);
        $this->assertArrayNotHasKey('google_extended', AiAgents::crawlers());
    }

    public function test_user_agent_matching_prefers_the_specific_product(): void
    {
        $this->assertSame('claude_searchbot', AiAgents::matchUserAgent('Mozilla/5.0 (compatible; Claude-SearchBot/1.0)'));
        $this->assertSame('claudebot', AiAgents::matchUserAgent('ClaudeBot/1.0'));
        $this->assertSame('chatgpt_user', AiAgents::matchUserAgent('Mozilla/5.0 ChatGPT-User/1.0'));
        $this->assertNull(AiAgents::matchUserAgent('Googlebot/2.1'));
    }
}
