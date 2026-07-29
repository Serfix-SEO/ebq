<?php

namespace App\Livewire\Content;

use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use App\Support\ContentAutopilotConfig;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The Content Autopilot "Get started" surface, shown when the current website
 * isn't yet running content. Branches on the user's entitlement state:
 *   - a free slot (subscription OR comped) → Activate on this website
 *   - sub active, slots full                → Add this website (addon)
 *   - never trialed                         → Start free trial
 *   - otherwise                             → pricing (checkout)
 *
 * "Slots" are NOT subscription-only: admin-comped free sites
 * ({@see ContentEntitlements::compSites()}) are additive in sitesAllowed().
 * Deriving the free-slot state from hasContentSubscription() alone sent every
 * comped client to the PAYMENT page while they held unused free slots
 * (prod 2026-07-29). Any new branch here must ask hasSlotSource(), never
 * hasContentSubscription() — same class of leak as the dashboard-limit gates.
 */
class GetStarted extends Component
{
    private function entitlements(): ContentEntitlements
    {
        return app(ContentEntitlements::class);
    }

    /** Does the user hold slots at all — paid subscription or comped free sites? */
    private function hasSlotSource(User $user): bool
    {
        $ent = $this->entitlements();

        return $ent->hasContentSubscription($user) || $ent->compSites($user) > 0;
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

        // Same preference as EnsureContentAccess: without it this screen judges
        // a DIFFERENT site than the middleware does, and the two bounce the user
        // back and forth. See ContentEntitlements::preferredWebsite().
        return $this->entitlements()->preferredWebsite($user);
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
        // A paid subscription is not the only source of slots — admin-comped
        // free sites count too (ContentEntitlements::sitesAllowed() has always
        // added them). Gating on hasContentSubscription() alone left comped
        // clients unable to activate at all.
        if ($website === null) {
            // Defensive: the UI renders the 'no_website' state instead of this
            // button, so reaching here means a stale page. Say so rather than
            // dying silently — a dead button is the worst possible feedback.
            session()->flash('error', __('Add a website first, then activate Content Autopilot on it.'));

            return;
        }
        if (! $this->hasSlotSource($user)) {
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
        // Slots come from a subscription OR from admin-comped free sites. This
        // used to require $hasSub, so a comped client whose trial was already
        // spent fell through to 'pricing' — the payment page — while holding
        // unused free slots (prod 2026-07-29: 4 of 4 comped clients).
        $slotFree = $this->hasSlotSource($user)
            && $ent->sitesCovered($user) < $ent->sitesAllowed($user);

        $state = match (true) {
            // No website at all (a client who deleted theirs) — every other
            // state's CTA acts ON a website, so offering them silently did
            // nothing when clicked. Ask for the site first.
            $website === null => 'no_website',
            $slotFree => 'activate',
            $hasSub => 'add_website',
            $neverTrialed => 'trial',
            default => 'pricing',
        };

        return view('livewire.content.get-started', [
            'state' => $state,
            'website' => $website,
            'freeSlots' => max(0, $ent->sitesAllowed($user) - $ent->sitesCovered($user)),
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
