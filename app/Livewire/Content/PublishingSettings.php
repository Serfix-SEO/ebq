<?php

namespace App\Livewire\Content;

use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\Website;
use App\Services\Content\Publishing\ProvidesTargets;
use App\Services\Content\Publishing\PublishDriverFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Where your articles publish" card on the Content Settings page (below the
 * wizard). Phase 3: connect WordPress (application password) or a custom
 * webhook, verify credentials live, toggle hands-off auto-publish.
 *
 * Credentials go through ContentIntegration's ENCRYPTED cast and are
 * verified via the real driver before the integration flips to connected.
 * Secrets are never echoed back to the browser after save.
 */
class PublishingSettings extends Component
{
    /**
     * UI-only selector value. A Laravel site is a WEBHOOK integration — same
     * driver, same signed payload — but it gets its own tab, its own install
     * guide and a pre-filled endpoint, because "paste a webhook URL" is not an
     * instruction a Laravel developer should have to translate themselves.
     * The chosen flavour is recorded in ContentIntegration.config so the
     * connected-platforms list can name it correctly.
     */
    public const FLAVOR_LARAVEL = 'laravel';

    /** Where serfix/content-ai-laravel mounts its receiver by default. */
    public const LARAVEL_WEBHOOK_PATH = '/serfix/content-ai/webhook';

    public ?string $websiteId = null;

    // Connect form state
    public string $platform = ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD;

    public string $wpSiteUrl = '';

    public string $wpUsername = '';

    public string $wpAppPassword = '';

    public string $whEndpoint = '';

    public string $whSecret = '';

    public string $shopifyStoreDomain = '';

    public string $shopifyToken = '';

    public string $webflowToken = '';

    public string $wixApiKey = '';

    public string $wixSiteId = '';

    public string $sanityProjectId = '';

    public string $sanityToken = '';

    public string $sanityUrlPattern = '';

    public string $hubspotToken = '';

    /** Publish live vs save as draft — stored as config.post_status. */
    public string $postStatus = 'publish';

    public bool $showConnect = false;

    /**
     * Two-step connect state: when a verified destination still needs a
     * choice (which blog / collection / dataset), the pending step renders as
     * a dropdown while the integration stays `pending`. Credentials are
     * already saved — the token is never echoed back.
     *
     * @var array{key: string, label: string, options: list<array{id: string, label: string}>}|null
     */
    public ?array $pendingTarget = null;

    public ?string $pendingIntegrationId = null;

    public string $chosenTargetId = '';

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        $this->wpSiteUrl = (string) ($this->website()?->domain ?? '');
        $this->whSecret = $this->generatedSecret();
    }

    /**
     * A strong signing secret, pre-filled so the customer never has to invent
     * one. This secret is the ENTIRE authentication boundary for that site — a
     * guessable phrase means anyone can publish HTML to their pages.
     */
    public function regenerateSecret(): void
    {
        $this->whSecret = $this->generatedSecret();
    }

    private function generatedSecret(): string
    {
        return Str::random(48);
    }

    /**
     * Switch the connect tab. Selecting Laravel pre-fills the endpoint from the
     * site's own domain, because the package mounts at a known path — the
     * customer should be copying a secret, not assembling a URL.
     */
    public function selectPlatform(string $platform): void
    {
        $this->platform = $platform;
        $this->resetErrorBag();
        $this->reset('pendingTarget', 'pendingIntegrationId', 'chosenTargetId');

        if ($platform === self::FLAVOR_LARAVEL && trim($this->whEndpoint) === '') {
            $this->whEndpoint = $this->suggestedLaravelEndpoint();
        }
    }

    public function suggestedLaravelEndpoint(): string
    {
        $domain = trim((string) ($this->website()?->normalized_domain ?? ''));

        return $domain === ''
            ? 'https://your-site.com'.self::LARAVEL_WEBHOOK_PATH
            : 'https://'.$domain.self::LARAVEL_WEBHOOK_PATH;
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->reset('wpSiteUrl', 'wpUsername', 'wpAppPassword', 'whEndpoint', 'whSecret', 'showConnect');
        $this->resetPlatformFields();
        $this->wpSiteUrl = (string) ($this->website()?->domain ?? '');
        $this->whSecret = $this->generatedSecret();
    }

    private function resetPlatformFields(): void
    {
        $this->reset(
            'shopifyStoreDomain', 'shopifyToken', 'webflowToken', 'wixApiKey', 'wixSiteId',
            'sanityProjectId', 'sanityToken', 'sanityUrlPattern', 'hubspotToken', 'postStatus',
            'pendingTarget', 'pendingIntegrationId', 'chosenTargetId',
        );
    }

    public function connect(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }
        $this->reset('pendingTarget', 'pendingIntegrationId', 'chosenTargetId');

        $config = null;

        if ($this->platform === ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD) {
            $this->validate([
                'wpSiteUrl' => 'required|string|max:255',
                'wpUsername' => 'required|string|max:120',
                'wpAppPassword' => 'required|string|max:200',
            ], [], ['wpSiteUrl' => __('site URL'), 'wpUsername' => __('username'), 'wpAppPassword' => __('application password')]);
            $credentials = [
                'site_url' => trim($this->wpSiteUrl),
                'username' => trim($this->wpUsername),
                'app_password' => trim($this->wpAppPassword),
            ];
        } elseif ($this->platform === ContentIntegration::PLATFORM_SHOPIFY) {
            $this->validate([
                'shopifyStoreDomain' => 'required|string|max:255',
                'shopifyToken' => 'required|string|max:255',
            ], [], ['shopifyStoreDomain' => __('store domain'), 'shopifyToken' => __('access token')]);
            $credentials = [
                'store_domain' => trim($this->shopifyStoreDomain),
                'access_token' => trim($this->shopifyToken),
            ];
            $config = $this->postStatusConfig();
        } elseif ($this->platform === ContentIntegration::PLATFORM_WEBFLOW) {
            $this->validate([
                'webflowToken' => 'required|string|max:500',
            ], [], ['webflowToken' => __('API token')]);
            $credentials = ['api_token' => trim($this->webflowToken)];
            $config = $this->postStatusConfig();
        } elseif ($this->platform === ContentIntegration::PLATFORM_WIX) {
            $this->validate([
                'wixApiKey' => 'required|string|max:2000',
                'wixSiteId' => ['required', 'string', 'regex:/^[0-9a-f-]{30,40}$/i'],
            ], [
                'wixSiteId.regex' => __('That does not look like a Wix site ID — copy the GUID from your dashboard URL (…/dashboard/{site-id}/…).'),
            ], ['wixApiKey' => __('API key'), 'wixSiteId' => __('site ID')]);
            $credentials = [
                'api_key' => trim($this->wixApiKey),
                'site_id' => trim($this->wixSiteId),
            ];
            $config = $this->postStatusConfig();
        } elseif ($this->platform === ContentIntegration::PLATFORM_SANITY) {
            $this->validate([
                'sanityProjectId' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/i'],
                'sanityToken' => 'required|string|max:500',
                'sanityUrlPattern' => 'nullable|url|starts_with:https://|max:600',
            ], [
                'sanityProjectId.regex' => __('That does not look like a Sanity project ID (letters and numbers only).'),
            ], ['sanityProjectId' => __('project ID'), 'sanityToken' => __('token'), 'sanityUrlPattern' => __('public URL pattern')]);
            $pattern = trim($this->sanityUrlPattern);
            if ($pattern !== '' && ! str_contains($pattern, '{slug}')) {
                $this->addError('sanityUrlPattern', __('The URL pattern needs a {slug} placeholder, e.g. https://your-site.com/blog/{slug}.'));

                return;
            }
            $credentials = [
                'project_id' => strtolower(trim($this->sanityProjectId)),
                'token' => trim($this->sanityToken),
            ];
            $config = $this->postStatusConfig() + array_filter(['url_pattern' => $pattern]);
        } elseif ($this->platform === ContentIntegration::PLATFORM_HUBSPOT) {
            $this->validate([
                'hubspotToken' => 'required|string|max:255',
            ], [], ['hubspotToken' => __('private app token')]);
            $credentials = ['token' => trim($this->hubspotToken)];
            $config = $this->postStatusConfig();
        } else {
            // https ONLY: the signature stops forgery, not disclosure. Over
            // plain http every article — and the site's whole content plan —
            // travels in cleartext across the internet.
            $this->validate([
                'whEndpoint' => 'required|url|starts_with:https://|max:600',
                // 32 chars of real entropy. The field is pre-filled with a
                // generated secret (see mount()); this floor exists to stop
                // someone replacing it with a memorable phrase, which is the
                // whole security boundary for that site.
                'whSecret' => 'required|string|min:32|max:200',
            ], [
                'whEndpoint.starts_with' => __('The endpoint URL must use https:// — articles are sent over the public internet.'),
                'whSecret.min' => __('The signing secret must be at least 32 characters. Use the generated one.'),
            ], ['whEndpoint' => __('endpoint URL'), 'whSecret' => __('signing secret')]);
            // Laravel IS a webhook integration — same driver, same signed
            // payload. Only the setup instructions differ, so remember which
            // tab was used and store it as a plain webhook.
            $flavor = $this->platform === self::FLAVOR_LARAVEL ? self::FLAVOR_LARAVEL : null;
            $this->platform = ContentIntegration::PLATFORM_WEBHOOK;
            $credentials = [
                'endpoint_url' => trim($this->whEndpoint),
                'secret' => trim($this->whSecret),
            ];
            if ($flavor !== null) {
                $config = ['flavor' => $flavor];
            }
        }

        $attributes = ['credentials' => $credentials, 'status' => ContentIntegration::STATUS_PENDING, 'last_error' => null];
        if ($config !== null) {
            // Merge over what's already stored so a reconnect keeps earlier
            // choices (blog/collection/dataset) when the config key survives.
            $existing = ContentIntegration::query()
                ->where('website_id', $website->id)->where('platform', $this->platform)
                ->value('config');
            $attributes['config'] = array_merge((array) ($existing ?? []), $config);
        }

        $integration = ContentIntegration::query()->updateOrCreate(
            ['website_id' => $website->id, 'platform' => $this->platform],
            $attributes,
        );

        $driver = app(PublishDriverFactory::class)->for($integration);
        $result = $driver?->verify($integration);

        if ($result === null || ! $result->ok) {
            $integration->forceFill([
                'status' => ContentIntegration::STATUS_ERROR,
                'last_error' => mb_substr((string) ($result?->error ?? 'Unsupported platform.'), 0, 500),
            ])->save();
            $this->addError('connect', $result?->error ?? __('This platform is not supported yet.'));

            return;
        }

        $this->resolveTargets($integration, $driver);
    }

    /** @return array{post_status: string} */
    private function postStatusConfig(): array
    {
        return ['post_status' => in_array($this->postStatus, ['publish', 'draft'], true) ? $this->postStatus : 'publish'];
    }

    /**
     * Walk the driver's remaining target steps: auto-select any step with
     * exactly one option; stop on the first step that needs a human choice
     * (renders as a dropdown, integration stays pending); flip to connected
     * when nothing is left to choose.
     */
    private function resolveTargets(ContentIntegration $integration, \App\Services\Content\Publishing\PublishDriver $driver): void
    {
        if ($driver instanceof ProvidesTargets) {
            // Bounded: each iteration either consumes a step or returns.
            for ($i = 0; $i < 5; $i++) {
                $steps = $driver->targets($integration->refresh());
                if ($steps === []) {
                    break;
                }
                $step = $steps[0];
                if (count($step['options']) === 1) {
                    $picked = $driver->selectTarget($integration, $step['key'], $step['options'][0]['id']);
                    if (! $picked->ok) {
                        $this->addError('connect', (string) $picked->error);

                        return;
                    }

                    continue;
                }

                $this->pendingTarget = $step;
                $this->pendingIntegrationId = $integration->id;
                $this->chosenTargetId = '';
                $this->showConnect = true;

                return;
            }
        }

        $this->markConnected($integration);
    }

    /** The user picked an option for the pending target step. */
    public function chooseTarget(): void
    {
        if ($this->pendingTarget === null || $this->pendingIntegrationId === null || $this->chosenTargetId === '') {
            return;
        }
        $integration = $this->integrationOrFail($this->pendingIntegrationId);
        if ($integration === null) {
            return;
        }
        $driver = app(PublishDriverFactory::class)->for($integration);
        if (! $driver instanceof ProvidesTargets) {
            return;
        }

        $result = $driver->selectTarget($integration, (string) $this->pendingTarget['key'], $this->chosenTargetId);
        if (! $result->ok) {
            $this->addError('connect', (string) $result->error);

            return;
        }

        $this->reset('pendingTarget', 'pendingIntegrationId', 'chosenTargetId');
        $this->resolveTargets($integration, $driver);
    }

    private function markConnected(ContentIntegration $integration): void
    {
        $integration->forceFill([
            'status' => ContentIntegration::STATUS_CONNECTED,
            'last_verified_at' => now(),
            'last_error' => null,
        ])->save();

        $this->reset('wpUsername', 'wpAppPassword', 'whEndpoint', 'whSecret', 'showConnect');
        $this->resetPlatformFields();
        session()->flash('publishing-status', __('Connected. Approved articles will now publish automatically.'));
    }

    public function reverify(string $integrationId): void
    {
        $integration = $this->integrationOrFail($integrationId);
        if ($integration === null) {
            return;
        }
        $result = app(PublishDriverFactory::class)->for($integration)?->verify($integration);
        $ok = $result?->ok ?? false;
        $integration->forceFill([
            'status' => $ok ? ContentIntegration::STATUS_CONNECTED : ContentIntegration::STATUS_ERROR,
            'last_verified_at' => $ok ? now() : $integration->last_verified_at,
            'last_error' => $ok ? null : mb_substr((string) ($result?->error ?? 'Verification failed.'), 0, 500),
        ])->save();
    }

    /**
     * Re-enter credentials for a broken integration.
     *
     * Opens the connect panel prefilled with the site URL already on file, so
     * fixing a rejected password is "paste the new one" rather than retyping
     * everything. Deliberately does NOT prefill the username: WordPress
     * rejecting the credentials means either half could be wrong, and a
     * pre-filled wrong username is the harder error to spot.
     */
    public function reconnect(string $integrationId): void
    {
        $integration = $this->integrationOrFail($integrationId);
        if ($integration === null) {
            return;
        }

        $this->platform = $integration->platform;
        $creds = (array) $integration->credentials;
        // Prefill only the NON-secret half of each credential pair; the
        // secret (password/token/key) always has to be re-pasted.
        $this->wpSiteUrl = (string) ($creds['site_url'] ?? '');
        $this->shopifyStoreDomain = (string) ($creds['store_domain'] ?? '');
        $this->wixSiteId = (string) ($creds['site_id'] ?? '');
        $this->sanityProjectId = (string) ($creds['project_id'] ?? '');
        $this->reset('wpUsername', 'wpAppPassword', 'shopifyToken', 'webflowToken', 'wixApiKey', 'sanityToken', 'hubspotToken');
        $this->postStatus = (string) (($integration->config['post_status'] ?? 'publish') ?: 'publish');
        $this->showConnect = true;
    }

    public function disconnect(string $integrationId): void
    {
        $this->integrationOrFail($integrationId)?->delete();
    }

    /** Inline endpoint-URL edit state (webhook integrations only). */
    public ?string $editingEndpointId = null;

    public string $editEndpointUrl = '';

    public function editEndpoint(string $integrationId): void
    {
        $integration = $this->integrationOrFail($integrationId);
        if ($integration === null || $integration->platform !== ContentIntegration::PLATFORM_WEBHOOK) {
            return;
        }
        $this->editingEndpointId = $integration->id;
        $this->editEndpointUrl = (string) (((array) $integration->credentials)['endpoint_url'] ?? '');
        $this->resetErrorBag('editEndpointUrl');
    }

    public function cancelEditEndpoint(): void
    {
        $this->reset('editingEndpointId', 'editEndpointUrl');
        $this->resetErrorBag('editEndpointUrl');
    }

    /**
     * Change ONLY the endpoint URL — the signing secret stays, so the client
     * can move their receiver without re-wiring the secret on both ends. The
     * new URL is live-verified before the integration stays connected.
     */
    public function saveEndpoint(): void
    {
        if ($this->editingEndpointId === null) {
            return;
        }
        $integration = $this->integrationOrFail($this->editingEndpointId);
        if ($integration === null || $integration->platform !== ContentIntegration::PLATFORM_WEBHOOK) {
            return;
        }

        $this->validate([
            'editEndpointUrl' => 'required|url|starts_with:https://|max:600',
        ], [
            'editEndpointUrl.starts_with' => __('The endpoint URL must use https:// — articles are sent over the public internet.'),
        ], ['editEndpointUrl' => __('endpoint URL')]);

        $credentials = (array) $integration->credentials;
        $credentials['endpoint_url'] = trim($this->editEndpointUrl);
        $integration->forceFill(['credentials' => $credentials])->save();

        $result = app(PublishDriverFactory::class)->for($integration)?->verify($integration);
        $ok = $result?->ok ?? false;
        $integration->forceFill([
            'status' => $ok ? ContentIntegration::STATUS_CONNECTED : ContentIntegration::STATUS_ERROR,
            'last_verified_at' => $ok ? now() : $integration->last_verified_at,
            'last_error' => $ok ? null : mb_substr((string) ($result?->error ?? 'Verification failed.'), 0, 500),
        ])->save();

        $this->reset('editingEndpointId', 'editEndpointUrl', 'webhookTest');
        session()->flash('publishing-status', $ok
            ? __('Endpoint updated and verified.')
            : __('Endpoint saved, but verification failed — check the URL and use the tester below.'));
    }

    /**
     * Webhook tester result, shown inline on the integrations page.
     *
     * @var array{integration_id: string, ok: bool, status: ?int, url: ?string, id: ?string, error: ?string}|null
     */
    public ?array $webhookTest = null;

    /**
     * Send a sample article through the REAL webhook delivery path (full
     * payload, real HMAC) so the client can prove their receiver stores
     * articles — a verify-only 200 hides exactly that failure.
     */
    public function testWebhook(string $integrationId): void
    {
        $this->webhookTest = null;
        $integration = $this->integrationOrFail($integrationId);
        if ($integration === null || $integration->platform !== ContentIntegration::PLATFORM_WEBHOOK) {
            return;
        }

        // Don't let a stuck client hammer their own endpoint.
        $key = 'webhook-test:'.$integration->id;
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 10)) {
            $this->webhookTest = [
                'integration_id' => $integration->id, 'ok' => false, 'status' => null,
                'url' => null, 'id' => null,
                'error' => __('Too many test deliveries — wait a few minutes and try again.'),
            ];

            return;
        }
        \Illuminate\Support\Facades\RateLimiter::hit($key, 600);

        $result = app(\App\Services\Content\Publishing\WebhookDriver::class)->testDelivery($integration);

        $this->webhookTest = [
            'integration_id' => $integration->id,
            'ok' => $result->ok,
            'status' => $result->response['status'] ?? null,
            'url' => $result->externalUrl,
            'id' => $result->externalId ?: null,
            'error' => $result->error,
        ];
    }

    public function toggleAutoPublish(): void
    {
        $plan = $this->plan();
        $plan?->update(['auto_publish' => ! $plan->auto_publish]);
    }

    public function render()
    {
        $website = $this->website();
        $plan = $this->plan();

        return view('livewire.content.publishing-settings', [
            'integrations' => $website?->contentIntegrations()->orderBy('platform')->get() ?? collect(),
            'plan' => $plan,
            'waiting' => $plan !== null
                ? $plan->topics()->where('status', ContentTopic::STATUS_SCHEDULED)->count()
                : 0,
            'hasWebsite' => $website !== null,
        ]);
    }

    // ── internals ───────────────────────────────────────────────────────

    private function website(): ?Website
    {
        return $this->websiteId
            ? Auth::user()?->accessibleWebsitesQuery()->whereKey($this->websiteId)->first()
            : null;
    }

    private function plan(): ?ContentPlan
    {
        return $this->websiteId
            ? ContentPlan::query()->where('website_id', $this->websiteId)->first()
            : null;
    }

    private function integrationOrFail(string $id): ?ContentIntegration
    {
        $website = $this->website();

        return $website?->contentIntegrations()->whereKey($id)->first();
    }
}
