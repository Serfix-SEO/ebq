<?php

namespace App\Livewire\Content;

use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Support\ContentAutopilotConfig;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The Content Autopilot "Get started" surface, shown when the current website
 * isn't yet running content. Branches on the user's entitlement state:
 *   - never trialed, no sub → Start free trial
 *   - trial spent, no sub    → pricing (checkout)
 *   - sub active, slots free → Activate on this website
 *   - sub active, slots full → Add this website (addon)
 */
class GetStarted extends Component
{
    private function entitlements(): ContentEntitlements
    {
        return app(ContentEntitlements::class);
    }

    private function website(): ?Website
    {
        $user = auth()->user();
        $id = (string) session('current_website_id', '');
        if ($id !== '') {
            $w = $user->accessibleWebsitesQuery()->whereKey($id)->first();
            if ($w !== null) {
                return $w;
            }
        }

        return $user->accessibleWebsitesQuery()->first();
    }

    public function startTrial(): void
    {
        $user = auth()->user();
        $website = $this->website();
        if ($website === null || $user->content_trial_started_at !== null || $user->hasContentAccess()) {
            return;
        }
        $this->entitlements()->startTrial($user, $website);
        $this->redirectRoute('content.settings', navigate: true);
    }

    public function activate(): void
    {
        $user = auth()->user();
        $website = $this->website();
        $ent = $this->entitlements();
        if ($website === null || ! $ent->hasContentSubscription($user)) {
            return;
        }
        if ($ent->sitesCovered($user) >= $ent->sitesAllowed($user)) {
            return; // no free slot — the UI shows "Add website" instead
        }
        $ent->coverWebsite($website);
        $this->redirectRoute('content.settings', navigate: true);
    }

    public function render(): View
    {
        $user = auth()->user();
        $ent = $this->entitlements();
        $website = $this->website();

        $hasSub = $ent->hasContentSubscription($user);
        $neverTrialed = $user->content_trial_started_at === null;
        $slotFree = $hasSub && $ent->sitesCovered($user) < $ent->sitesAllowed($user);

        $state = match (true) {
            $hasSub && $slotFree => 'activate',
            $hasSub => 'add_website',
            $neverTrialed => 'trial',
            default => 'pricing',
        };

        return view('livewire.content.get-started', [
            'state' => $state,
            'website' => $website,
            'prices' => [
                'monthly' => ContentAutopilotConfig::displayPrice('monthly'),
                'annual' => ContentAutopilotConfig::displayPrice('annual'),
                'first_month' => ContentAutopilotConfig::displayPrice('first_month'),
                'addon_monthly' => ContentAutopilotConfig::displayPrice('addon_monthly'),
                'addon_annual' => ContentAutopilotConfig::displayPrice('addon_annual'),
            ],
            'trialDays' => ContentAutopilotConfig::trialDays(),
            'trialArticles' => ContentAutopilotConfig::trialArticles(),
        ]);
    }
}
