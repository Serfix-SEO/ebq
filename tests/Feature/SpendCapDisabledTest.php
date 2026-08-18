<?php

namespace Tests\Feature;

use App\Services\Content\ContentLlmSpendMeter;
use App\Services\Content\IdeogramSpendMeter;
use App\Services\Reports\DataForSeoSpendMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Caps are off in production (2026-08-16, owner's call): a $10 image cap was
 * stopping articles from getting images mid-month. `0` disables a breaker
 * while leaving the meter counting, so /admin/ops still shows real spend.
 *
 * These pin the two halves that matter: 0 really means unlimited (no matter
 * how much has been spent), and a positive cap still works — the breakers are
 * dormant, not deleted, so setting a number restores them without a deploy.
 */
class SpendCapDisabledTest extends TestCase
{
    use RefreshDatabase;

    public static function meters(): array
    {
        return [
            'dataforseo' => [DataForSeoSpendMeter::class, 'services.dataforseo.monthly_cap_usd'],
            'content llm' => [ContentLlmSpendMeter::class, 'services.content_autopilot.llm_monthly_cap_usd'],
            'ideogram images' => [IdeogramSpendMeter::class, 'services.ideogram.monthly_cap_usd'],
        ];
    }

    #[DataProvider('meters')]
    public function test_zero_cap_never_exhausts($meter, string $configKey): void
    {
        config([$configKey => 0]);
        $m = app($meter);

        $m->add(9_999.0);

        $this->assertNull($m->cap());
        $this->assertFalse($m->exhausted(), 'a disabled breaker must never stop the feature');
        $this->assertFalse($m->nearCap(), 'no cap means no 80% warning either');
    }

    public function test_spend_is_still_tracked_with_no_cap(): void
    {
        // Turning enforcement off must not turn accounting off — /admin/ops is
        // the only place this cost is visible.
        config(['services.ideogram.monthly_cap_usd' => 0]);
        $m = app(IdeogramSpendMeter::class);

        $before = $m->spent();
        $m->add(1.25);

        $this->assertEqualsWithDelta($before + 1.25, $m->spent(), 0.001);
    }

    #[DataProvider('meters')]
    public function test_a_positive_cap_still_trips($meter, string $configKey): void
    {
        config([$configKey => 5]);
        $m = app($meter);

        $m->add(6.0);

        $this->assertSame(5.0, $m->cap());
        $this->assertTrue($m->exhausted(), 'breakers must still be usable when a number is set');
    }

    public function test_ops_page_shows_spend_even_with_no_cap(): void
    {
        config(['services.ideogram.monthly_cap_usd' => 0]);
        app(IdeogramSpendMeter::class)->add(3.50);

        $admin = \App\Models\User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->get(route('admin.ops.index'))
            ->assertOk()
            ->assertSee('Content Autopilot image spend this month')
            ->assertSee('no cap')
            ->assertDontSee('cap reached');
    }
}
