<?php

namespace App\Livewire\Content;

use App\Models\ContentIntegration;
use App\Models\ContentPlan;
use App\Models\ContentTopic;
use App\Models\Website;
use App\Services\Content\Publishing\PublishDriverFactory;
use Illuminate\Support\Facades\Auth;
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
    public ?string $websiteId = null;

    // Connect form state
    public string $platform = ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD;
    public string $wpSiteUrl = '';
    public string $wpUsername = '';
    public string $wpAppPassword = '';
    public string $whEndpoint = '';
    public string $whSecret = '';
    public bool $showConnect = false;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        $this->wpSiteUrl = (string) ($this->website()?->domain ?? '');
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->reset('wpSiteUrl', 'wpUsername', 'wpAppPassword', 'whEndpoint', 'whSecret', 'showConnect');
        $this->wpSiteUrl = (string) ($this->website()?->domain ?? '');
    }

    public function connect(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }

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
        } else {
            $this->validate([
                'whEndpoint' => 'required|url|max:600',
                'whSecret' => 'required|string|min:16|max:200',
            ], [], ['whEndpoint' => __('endpoint URL'), 'whSecret' => __('signing secret')]);
            $this->platform = ContentIntegration::PLATFORM_WEBHOOK;
            $credentials = [
                'endpoint_url' => trim($this->whEndpoint),
                'secret' => trim($this->whSecret),
            ];
        }

        $integration = ContentIntegration::query()->updateOrCreate(
            ['website_id' => $website->id, 'platform' => $this->platform],
            ['credentials' => $credentials, 'status' => ContentIntegration::STATUS_PENDING, 'last_error' => null],
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

        $integration->forceFill([
            'status' => ContentIntegration::STATUS_CONNECTED,
            'last_verified_at' => now(),
            'last_error' => null,
        ])->save();

        $this->reset('wpUsername', 'wpAppPassword', 'whEndpoint', 'whSecret', 'showConnect');
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

    public function disconnect(string $integrationId): void
    {
        $this->integrationOrFail($integrationId)?->delete();
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
