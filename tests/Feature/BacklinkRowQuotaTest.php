<?php

namespace Tests\Feature;

use App\Models\ClientActivity;
use App\Models\Plan;
use App\Models\User;
use App\Services\Reports\BacklinkRowQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BacklinkRowQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $domain, int $rows): array
    {
        return [
            'domain' => $domain,
            'backlinks' => array_map(fn ($i) => [
                'url_from' => "https://src{$i}.test/p", 'url_to' => "https://{$domain}/", 'anchor' => "a{$i}", 'dofollow' => true,
            ], range(1, $rows)),
        ];
    }

    private function user(int $monthly): User
    {
        Plan::create(['slug' => 'trial', 'name' => 'Trial',
            'api_limits' => ['report' => ['max_backlink_rows' => 1000, 'monthly_backlink_rows' => $monthly, 'allow_link_drilldown' => 0]]]);

        return User::factory()->create(['current_plan_slug' => 'trial']);
    }

    public function test_charges_once_per_domain_per_window(): void
    {
        $user = $this->user(1000);
        $quota = app(BacklinkRowQuota::class);

        $out = $quota->apply($user, $this->payload('one.test', 40));
        $this->assertSame(40, $out['_backlink_view']['shown']);
        $this->assertSame(40, $out['_backlink_view']['monthly_used']);
        $this->assertFalse($out['_backlink_view']['exhausted']);
        $this->assertSame(1, ClientActivity::where('provider', 'backlink_rows')->count());

        // Repeat view: same rows shown, NO second charge.
        $quota->apply($user, $this->payload('one.test', 40));
        $this->assertSame(1, ClientActivity::where('provider', 'backlink_rows')->count());
        $this->assertSame(40, (int) ClientActivity::where('provider', 'backlink_rows')->sum('units_consumed'));
    }

    public function test_exhausted_quota_shows_teaser_and_flags_banner(): void
    {
        $user = $this->user(10);
        $quota = app(BacklinkRowQuota::class);

        // First domain eats the whole 10-row budget.
        $out = $quota->apply($user, $this->payload('one.test', 30));
        $this->assertSame(10, (int) ClientActivity::where('provider', 'backlink_rows')->sum('units_consumed'));
        $this->assertSame(25, $out['_backlink_view']['shown']); // teaser floor
        $this->assertTrue($out['_backlink_view']['exhausted']);

        // Second domain: zero budget left → teaser only, nothing charged.
        $out2 = $quota->apply($user, $this->payload('two.test', 30));
        $this->assertSame(25, $out2['_backlink_view']['shown']);
        $this->assertTrue($out2['_backlink_view']['exhausted']);
        $this->assertSame(10, (int) ClientActivity::where('provider', 'backlink_rows')->sum('units_consumed'));
    }

    public function test_unlimited_plan_skips_metering(): void
    {
        Plan::create(['slug' => 'enterprise', 'name' => 'Enterprise', 'api_limits' => null]);
        $user = User::factory()->create(['current_plan_slug' => 'enterprise']);

        $out = app(BacklinkRowQuota::class)->apply($user, $this->payload('big.test', 900));

        $this->assertSame(900, $out['_backlink_view']['shown']);
        $this->assertNull($out['_backlink_view']['monthly_limit']);
        $this->assertSame(0, ClientActivity::where('provider', 'backlink_rows')->count());
    }
}
