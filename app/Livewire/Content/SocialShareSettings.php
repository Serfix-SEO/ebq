<?php

namespace App\Livewire\Content;

use App\Http\Controllers\SocialShareOAuthController;
use App\Models\ContentSocialAccount;
use App\Models\Website;
use App\Services\Content\Social\SocialPoster;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Auto-share" card on the Content Integrations page: connect a Facebook Page
 * and/or X account once — every published article's link is then posted
 * automatically. Providers whose OAuth app isn't configured are hidden
 * entirely (prod-safe before the apps exist). Client copy never mentions
 * tokens, tiers or API internals.
 */
class SocialShareSettings extends Component
{
    public ?string $websiteId = null;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
        if (! $this->websiteId) {
            $this->websiteId = Auth::user()?->accessibleWebsitesQuery()->value('id');
        }
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
    }

    private function website(): ?Website
    {
        if (! $this->websiteId) {
            return null;
        }

        return Auth::user()?->accessibleWebsitesQuery()->whereKey($this->websiteId)->first();
    }

    /** Finish the Facebook connect when the account manages multiple Pages. */
    public function chooseFacebookPage(string $pageId): void
    {
        $website = $this->website();
        $pages = (array) session('content_social.fb_pages', []);
        if ($website === null || $website->id !== (string) session('content_social.fb_website_id')) {
            return;
        }
        foreach ($pages as $page) {
            if ((string) ($page['id'] ?? '') === $pageId) {
                SocialShareOAuthController::storeFacebookPage($website, $page);
                session()->forget(['content_social.fb_pages', 'content_social.fb_website_id']);
                session()->flash('social-status', __('Facebook Page ":name" connected.', ['name' => $page['name']]));

                return;
            }
        }
    }

    public function toggleShare(string $accountId): void
    {
        $account = $this->accountOrFail($accountId);
        $account?->forceFill(['share_enabled' => ! $account->share_enabled])->save();
    }

    public function disconnect(string $accountId): void
    {
        $this->accountOrFail($accountId)?->delete();
        session()->flash('social-status', __('Disconnected.'));
    }

    private function accountOrFail(string $accountId): ?ContentSocialAccount
    {
        $website = $this->website();
        if ($website === null) {
            return null;
        }

        return ContentSocialAccount::query()
            ->where('website_id', $website->id)
            ->whereKey($accountId)
            ->first();
    }

    public function render()
    {
        $website = $this->website();
        $accounts = $website === null ? collect() : ContentSocialAccount::query()
            ->where('website_id', $website->id)
            ->orderBy('provider')
            ->get()
            ->keyBy('provider');

        $pendingPages = [];
        if ($website !== null && (string) session('content_social.fb_website_id') === $website->id) {
            $pendingPages = (array) session('content_social.fb_pages', []);
        }

        return view('livewire.content.social-share-settings', [
            'hasWebsite' => $website !== null,
            'sharingEnabled' => (bool) config('services.content_autopilot.social_sharing', true),
            'facebookConfigured' => SocialPoster::facebookConfigured(),
            'xConfigured' => SocialPoster::xConfigured(),
            'accounts' => $accounts,
            'pendingPages' => $pendingPages,
        ]);
    }
}
