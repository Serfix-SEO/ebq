<?php

namespace Tests\Feature\Content;

use App\Livewire\Content\PublishingSettings;
use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\Publishing\PhpKitBuilder;
use App\Support\Audit\SafeHttpGuard;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * The "PHP / HTML website" connect flow, from the customer's side.
 *
 * The customer is not a developer: they download a kit, upload two folders,
 * click Verify. So the secret is minted at DOWNLOAD time and must survive
 * until Verify, re-downloads must not invalidate a kit already on their
 * server, and a failed check must say what to do rather than quote an HTTP
 * status. The kit itself is exercised for real in PhpPublishingKitTest.
 */
class PhpKitConnectFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlanSeeder::class);
        $this->app->instance(SafeHttpGuard::class, new class extends SafeHttpGuard
        {
            public function check(string $url): array
            {
                return ['ok' => true];
            }
        });
    }

    /** @return array{0: User, 1: Website} */
    private function customer(): array
    {
        $user = User::factory()->create([
            'content_trial_started_at' => now(),
            'content_trial_ends_at' => now()->addDays(5),
        ]);
        $website = Website::factory()->for($user)->create([
            'domain' => 'safibusinessservice.com',
            'normalized_domain' => 'safibusinessservice.com',
        ]);
        ContentPlan::factory()->create(['website_id' => $website->id, 'billing_covered_at' => now()]);

        return [$user, $website];
    }

    private function panel(User $user, Website $website): \Livewire\Features\SupportTesting\Testable
    {
        $this->actingAs($user)->withSession(['current_website_id' => $website->id]);
        session(['current_website_id' => $website->id]);

        return Livewire::test(PublishingSettings::class)->set('showConnect', true);
    }

    private function kitSecret(Website $website): string
    {
        return (string) ContentIntegration::query()
            ->where('website_id', $website->id)->where('platform', ContentIntegration::PLATFORM_WEBHOOK)
            ->firstOrFail()->credentials['secret'];
    }

    public function test_the_tile_prefills_the_kit_address_from_the_sites_own_domain(): void
    {
        [$user, $website] = $this->customer();

        $this->panel($user, $website)
            ->call('selectPlatform', PublishingSettings::FLAVOR_PHP)
            ->assertSet('whEndpoint', 'https://safibusinessservice.com/serfix/receiver.php')
            ->assertSee('Download kit (.zip)')
            ->assertSee('PHP / HTML website');
    }

    public function test_downloading_mints_the_secret_and_puts_it_in_the_kit(): void
    {
        [$user, $website] = $this->customer();

        $this->panel($user, $website)
            ->call('selectPlatform', PublishingSettings::FLAVOR_PHP)
            ->call('downloadPhpKit')
            ->assertFileDownloaded('serfix-kit-safibusinessservice.com.zip');

        $integration = ContentIntegration::query()->where('website_id', $website->id)->firstOrFail();
        $this->assertSame(ContentIntegration::PLATFORM_WEBHOOK, $integration->platform);
        $this->assertSame(PublishingSettings::FLAVOR_PHP, $integration->config['flavor']);
        $this->assertSame(ContentIntegration::STATUS_PENDING, $integration->status, 'nothing is uploaded yet');
        $this->assertGreaterThanOrEqual(32, strlen($this->kitSecret($website)));

        // The ZIP's config.php carries exactly that secret.
        $config = $this->configFromKit(app(PhpKitBuilder::class)->build($integration));
        $this->assertSame($this->kitSecret($website), $config['secret']);
        $this->assertSame('https://safibusinessservice.com', $config['site_url']);
    }

    /** A kit already on the customer's server must keep working. */
    public function test_downloading_again_reuses_the_same_secret(): void
    {
        [$user, $website] = $this->customer();
        $panel = $this->panel($user, $website)->call('selectPlatform', PublishingSettings::FLAVOR_PHP);

        $panel->call('downloadPhpKit');
        $first = $this->kitSecret($website);
        $panel->call('downloadPhpKit');

        $this->assertSame($first, $this->kitSecret($website));
    }

    /** Verify signs with the kit's secret — the customer never types one. */
    public function test_verify_connects_using_the_secret_from_the_kit(): void
    {
        [$user, $website] = $this->customer();
        $panel = $this->panel($user, $website)->call('selectPlatform', PublishingSettings::FLAVOR_PHP)->call('downloadPhpKit');
        $secret = $this->kitSecret($website);

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($secret) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), $secret);

            return ($request->header('X-Serfix-Signature')[0] ?? '') === $expected
                ? Http::response(['ok' => true], 200)
                : Http::response(['error' => 'Invalid signature.'], 401);
        });

        $panel->call('connect')->assertHasNoErrors();

        $integration = ContentIntegration::query()->where('website_id', $website->id)->firstOrFail();
        $this->assertSame(ContentIntegration::STATUS_CONNECTED, $integration->status);
        $this->assertSame(PublishingSettings::FLAVOR_PHP, $integration->config['flavor'], 'it stays named as the PHP kit');
    }

    public function test_verify_before_downloading_says_to_download_first(): void
    {
        [$user, $website] = $this->customer();

        $this->panel($user, $website)
            ->call('selectPlatform', PublishingSettings::FLAVOR_PHP)
            ->call('connect')
            ->assertHasErrors('connect')
            ->assertSee('Download your kit first');
    }

    /**
     * The failures a non-developer actually hits, in words they can act on —
     * and they stay on the PHP tab instead of being dropped onto the generic
     * webhook form.
     */
    public function test_a_failed_check_explains_what_to_do_and_keeps_the_php_tab(): void
    {
        [$user, $website] = $this->customer();
        $panel = $this->panel($user, $website)->call('selectPlatform', PublishingSettings::FLAVOR_PHP)->call('downloadPhpKit');

        $cases = [
            404 => 'We couldn\'t find the kit at that address',
            401 => 'belongs to a different connection',
            500 => 'can\'t save files',
        ];
        // One verify request per connect; stubs accumulate, so queue them.
        $sequence = Http::fakeSequence();
        foreach (array_keys($cases) as $status) {
            $sequence->push('', $status);
        }

        foreach ($cases as $status => $message) {
            $panel->call('connect')
                ->assertHasErrors('connect')
                ->assertSee($message)
                ->assertSet('platform', PublishingSettings::FLAVOR_PHP)
                ->assertDontSee('HTTP '.$status);
        }
    }

    /** Never silently repoint a working custom webhook at an uninstalled kit. */
    public function test_a_working_custom_webhook_is_not_overwritten_by_a_kit_download(): void
    {
        [$user, $website] = $this->customer();
        ContentIntegration::create([
            'website_id' => $website->id,
            'platform' => ContentIntegration::PLATFORM_WEBHOOK,
            'status' => ContentIntegration::STATUS_CONNECTED,
            'credentials' => ['endpoint_url' => 'https://safibusinessservice.com/hook', 'secret' => str_repeat('a', 48)],
        ]);

        $this->panel($user, $website)
            ->call('selectPlatform', PublishingSettings::FLAVOR_PHP)
            ->call('downloadPhpKit')
            ->assertHasErrors('connect')
            ->assertNoFileDownloaded();

        $this->assertSame('https://safibusinessservice.com/hook', ContentIntegration::query()->firstOrFail()->credentials['endpoint_url']);
    }

    /** The ZIP is a credential: only someone who manages the site gets it. */
    public function test_another_user_cannot_download_someone_elses_kit(): void
    {
        [$owner, $website] = $this->customer();
        $this->panel($owner, $website)->call('selectPlatform', PublishingSettings::FLAVOR_PHP)->call('downloadPhpKit');
        $integration = ContentIntegration::query()->firstOrFail();

        [$stranger, $theirSite] = $this->customer();
        $this->panel($stranger, $theirSite)
            ->call('redownloadPhpKit', $integration->id)
            ->assertNoFileDownloaded();
    }

    public function test_a_downloaded_but_unverified_kit_reads_as_a_next_step_not_a_fault(): void
    {
        [$user, $website] = $this->customer();
        $this->panel($user, $website)->call('selectPlatform', PublishingSettings::FLAVOR_PHP)->call('downloadPhpKit');

        $this->panel($user, $website)
            ->assertSee('PHP / HTML website')
            ->assertSee('Waiting for the kit')
            ->assertDontSee('Needs attention')
            ->assertSee('Download kit');
    }

    public function test_the_kit_contains_the_files_the_readme_promises(): void
    {
        [$user, $website] = $this->customer();
        $this->panel($user, $website)->call('selectPlatform', PublishingSettings::FLAVOR_PHP)->call('downloadPhpKit');

        $entries = $this->kitEntries(app(PhpKitBuilder::class)->build(ContentIntegration::query()->firstOrFail()));

        foreach ([
            'README.txt',
            'serfix/receiver.php',
            'serfix/lib.php',
            'serfix/config.php',
            'serfix/.htaccess',
            'serfix/data/.htaccess',
            'articles/index.php',
            'articles/.htaccess',
        ] as $expected) {
            $this->assertContains($expected, $entries, "the kit must ship {$expected}");
        }
    }

    public function test_the_config_only_allows_images_from_our_own_storage(): void
    {
        [$user, $website] = $this->customer();
        $this->panel($user, $website)->call('selectPlatform', PublishingSettings::FLAVOR_PHP)->call('downloadPhpKit');

        $config = $this->configFromKit(app(PhpKitBuilder::class)->build(ContentIntegration::query()->firstOrFail()));

        $this->assertNotEmpty($config['image_hosts']);
        $this->assertArrayNotHasKey('image_allow_http', $config, 'plain-http images are a test-only switch, never shipped on');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return list<string> */
    private function kitEntries(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kit');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        unlink($path);

        return $names;
    }

    /** @return array<string, mixed> */
    private function configFromKit(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'kit');
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $php = (string) $zip->getFromName('serfix/config.php');
        $zip->close();
        unlink($path);

        $configPath = tempnam(sys_get_temp_dir(), 'cfg').'.php';
        file_put_contents($configPath, $php);
        $config = include $configPath;
        unlink($configPath);

        return $config;
    }
}
