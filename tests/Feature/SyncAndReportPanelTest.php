<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\SyncAndReportPanel;
use App\Mail\GrowthReportMail;
use App\Models\SearchConsoleData;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\TestCase;

class SyncAndReportPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The panel is #[Lazy]; without this only the skeleton placeholder
        // renders and the flash messages never appear in the HTML.
        Livewire::withoutLazyLoading();
    }

    public function test_send_report_dispatches_growth_report_mail(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);
        session(['current_website_id' => $website->id]);

        $this->seedSafeGscRow($website->id);

        Livewire::actingAs($user)
            ->test(SyncAndReportPanel::class)
            ->call('sendReport')
            ->assertSee('data through');

        // The dispatcher queues the mailable (locale-aware queued send).
        Mail::assertQueued(GrowthReportMail::class, function (GrowthReportMail $mail) use ($user, $website) {
            return $mail->user->is($user) && $mail->website->is($website);
        });
    }

    public function test_send_report_surfaces_error_when_search_console_data_missing(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);
        session(['current_website_id' => $website->id]);

        Livewire::actingAs($user)
            ->test(SyncAndReportPanel::class)
            ->call('sendReport')
            ->assertSee('still syncing');

        Mail::assertNothingSent();
    }

    public function test_send_report_is_rate_limited_after_five_sends_per_hour(): void
    {
        Mail::fake();

        $user = User::factory()->create();
        $website = Website::factory()->create(['user_id' => $user->id]);
        session(['current_website_id' => $website->id]);

        $this->seedSafeGscRow($website->id);

        RateLimiter::clear('send-growth-report:'.$user->id);

        $component = Livewire::actingAs($user)->test(SyncAndReportPanel::class);

        for ($i = 0; $i < 5; $i++) {
            $component->call('sendReport');
        }

        $component->call('sendReport')
            ->assertSee('Too many attempts');
    }

    private function seedSafeGscRow(string $websiteId): void
    {
        SearchConsoleData::create([
            'website_id' => $websiteId,
            'date' => Carbon::now()->subDays(5)->toDateString(),
            'query' => 'seed',
            'page' => 'https://example.com/',
            'clicks' => 1,
            'impressions' => 10,
            'position' => 5.0,
            'ctr' => 0.1,
        ]);
    }
}
