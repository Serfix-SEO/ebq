<?php

namespace App\Livewire\Settings;

use App\Models\Website;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Attributes\On;
use Livewire\Component;

class WordPressPlugin extends Component
{
    public ?string $websiteId = null;

    public ?string $statusMessage = null;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id');
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->statusMessage = null;
    }

    public function revokeToken(string $tokenId): void
    {
        $user = Auth::user();
        if (! $user || ! $user->canViewWebsiteId($this->websiteId)) {
            return;
        }

        PersonalAccessToken::query()
            ->where('tokenable_type', Website::class)
            ->where('tokenable_id', $this->websiteId)
            ->whereKey($tokenId)
            ->delete();

        $this->statusMessage = 'Token revoked. The WordPress site will immediately lose access.';
    }

    public function render()
    {
        $website = null;
        $tokens = collect();

        if (($this->websiteId !== null && $this->websiteId !== '') && Auth::user()?->canViewWebsiteId($this->websiteId)) {
            $website = Website::find($this->websiteId);
            $tokens = PersonalAccessToken::query()
                ->where('tokenable_type', Website::class)
                ->where('tokenable_id', $this->websiteId)
                ->orderByDesc('created_at')
                ->get(['id', 'name', 'last_used_at', 'created_at']);
        }

        return view('livewire.settings.wordpress-plugin', [
            'website' => $website,
            'tokens' => $tokens,
        ]);
    }
}
