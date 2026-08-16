<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\WebsiteAttachService;
use App\Support\GiantDomains;
use App\Support\MajorBrands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Big brands can't be signed up as "your website"; small businesses still can.
 *
 * Context: `du.ae` (a UAE telecom) reached an active plan with 36 topics and 4
 * finished articles on 2026-08-16 — a free account, no publish integration,
 * and a homepage that timed out during crawl. Shape validation can't catch it
 * (`du.ae` is a valid hostname) and GiantDomains can't own it (that list feeds
 * competitor discovery, where a telecom is a legitimate rival for another
 * telecom).
 *
 * The over-blocking half of this test matters more than the blocking half:
 * small and mid-size businesses are the customer.
 */
class MajorBrandGateTest extends TestCase
{
    use RefreshDatabase;

    public static function protectedDomains(): array
    {
        return [
            'the reported case' => ['du.ae'],
            'its subdomain' => ['shop.du.ae'],
            'the other UAE telecom' => ['etisalat.ae'],
            'its rebrand' => ['eand.com'],
            'GCC telecom' => ['stc.com.sa'],
            'airline' => ['emirates.com'],
            'bank' => ['emiratesnbd.com'],
            'global brand' => ['nike.com'],
            'energy' => ['adnoc.ae'],
            'government' => ['mohap.gov.ae'],
            'bare gov suffix' => ['dm.gov.ae'],
            'military' => ['army.mil'],
        ];
    }

    /**
     * Real customer domains taken from prod. If a change to the brand list
     * ever starts blocking one of these, the gate has gone too far.
     */
    public static function customerDomains(): array
    {
        return [
            ['nestara.in'],
            ['bytepith.com'],
            ['tajtourpackages.com'],
            ['mycurtain.ae'],
            ['aegiscoworking.ae'],
            ['rajeshcarrental.com'],
            ['tmcgeneralclinic.com'],
            ['orderofserviceforfuneral.co.uk'],
            ['pearlnatureholidays.com'],
            ['daomarketing.com'],
            ['serfix.io'],
            // Near-misses: short names and brand-adjacent words must not trip
            // a sloppy substring match.
            ['dubaicurtains.ae'],
            ['dutchbakery.nl'],
            ['nikesports-repair.co.uk'],
            ['myemirates-tours.com'],
            ['govconsulting.com'],
        ];
    }

    #[DataProvider('protectedDomains')]
    public function test_big_brands_are_blocked(string $domain): void
    {
        $this->assertTrue(MajorBrands::isProtected($domain), $domain.' should be blocked');
    }

    #[DataProvider('customerDomains')]
    public function test_real_customers_are_not_blocked(string $domain): void
    {
        $this->assertFalse(MajorBrands::isProtected($domain), $domain.' must stay allowed');
    }

    public function test_competitor_discovery_is_untouched_by_the_brand_list(): void
    {
        // The whole reason this is a second list: a telecom IS a valid
        // competitor for another telecom, so GiantDomains must not learn it.
        $this->assertFalse(GiantDomains::isGiant('du.ae'));
        $this->assertFalse(GiantDomains::isGiant('etisalat.ae'));
        $this->assertFalse(GiantDomains::isGiant('emirates.com'));
        // ...while the platforms it does own are unchanged.
        $this->assertTrue(GiantDomains::isGiant('youtube.com'));
    }

    public function test_admins_can_add_a_brand_without_a_deploy(): void
    {
        $this->assertFalse(MajorBrands::isProtected('somebigco.ae'));

        Setting::set(MajorBrands::SETTING_BLOCK, "somebigco.ae\nother.example");

        $this->assertTrue(MajorBrands::isProtected('somebigco.ae'));
        $this->assertTrue(MajorBrands::isProtected('careers.somebigco.ae'));
        $this->assertTrue(MajorBrands::isProtected('other.example'));
    }

    public function test_allow_list_overrides_everything_for_the_real_owner(): void
    {
        $this->assertTrue(MajorBrands::isProtected('du.ae'));

        Setting::set(MajorBrands::SETTING_ALLOW, 'du.ae');

        $this->assertFalse(MajorBrands::isProtected('du.ae'));
        $this->assertNull(MajorBrands::reason('du.ae'));
        // Everything else stays blocked.
        $this->assertTrue(MajorBrands::isProtected('etisalat.ae'));
    }

    public function test_authority_signal_catches_an_unlisted_global_brand(): void
    {
        $this->seedMetrics('unlisted-megabrand.com', da: 88, referring: 250_000);

        $this->assertTrue(MajorBrands::isProtected('unlisted-megabrand.com'));
        $this->assertSame('authority_signal', MajorBrands::reason('unlisted-megabrand.com'));
    }

    public function test_authority_signal_leaves_a_healthy_small_business_alone(): void
    {
        // A genuinely successful niche site — nowhere near brand scale.
        $this->seedMetrics('goodlocalbakery.ae', da: 42, referring: 900);
        $this->assertFalse(MajorBrands::isProtected('goodlocalbakery.ae'));

        // High authority but modest link profile, and vice versa: BOTH
        // thresholds are required, so neither alone blocks.
        $this->seedMetrics('nichebutstrong.com', da: 85, referring: 3_000);
        $this->assertFalse(MajorBrands::isProtected('nichebutstrong.com'));

        $this->seedMetrics('manylinkslowda.com', da: 30, referring: 120_000);
        $this->assertFalse(MajorBrands::isProtected('manylinkslowda.com'));
    }

    public function test_a_domain_we_have_no_metrics_for_is_never_blocked_by_the_signal(): void
    {
        // Every brand-new small business is exactly this case, and the check
        // must never trigger a paid lookup to decide.
        $this->assertFalse(MajorBrands::isProtected('brand-new-shop-2026.ae'));
    }

    public function test_attach_service_refuses_a_brand_domain(): void
    {
        $user = User::factory()->create();

        $result = app(WebsiteAttachService::class)->attach($user, 'https://www.du.ae/');

        $this->assertNull($result['website']);
        $this->assertSame('major_brand', $result['blocked']);
        $this->assertSame(0, Website::query()->count());
    }

    public function test_public_funnel_refuses_a_brand_domain(): void
    {
        $response = $this->post(route('content.onboarding.begin'), ['domain' => 'du.ae']);

        $response->assertSessionHasErrors('domain');
        $this->assertSame(0, Website::query()->count());
        $this->assertSame(0, \App\Models\CrawlSite::query()->count());
    }

    public function test_a_small_business_still_gets_through_the_whole_flow(): void
    {
        // attach() bootstraps a real crawl; fake the network so the suite
        // neither reaches the live site nor waits on its timeouts.
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('<html><body>hi</body></html>', 200)]);
        $user = User::factory()->create();

        $result = app(WebsiteAttachService::class)->attach($user, 'https://mycurtain.ae');

        $this->assertNull($result['blocked']);
        $this->assertNotNull($result['website']);
        $this->assertSame('mycurtain.ae', $result['website']->normalized_domain);
    }

    private function seedMetrics(string $domain, int $da, int $referring): void
    {
        // domain_metrics is auto-increment, not ULID — don't supply an id.
        DB::table('domain_metrics')->insert([
            'domain' => $domain,
            'moz_da' => $da,
            'dfs_referring_domains' => $referring,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
