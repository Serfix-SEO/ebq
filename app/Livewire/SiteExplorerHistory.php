<?php

namespace App\Livewire;

use App\Models\ClientActivity;
use App\Models\Website;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * "Recently analyzed" list on the Site Explorer page — the user's own
 * `site_explorer.query` activity log (same rows the admin usage page reads),
 * deduped to one entry per domain (most recent lookup), newest first.
 */
#[Lazy]
class SiteExplorerHistory extends Component
{
    private const LIMIT = 15;

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="mt-8 space-y-2">
            <div class="h-3 w-32 animate-pulse rounded bg-slate-200 dark:bg-slate-800"></div>
            <div class="h-10 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800/60"></div>
            <div class="h-10 animate-pulse rounded-lg bg-slate-100 dark:bg-slate-800/60"></div>
        </div>
        HTML;
    }

    public function render()
    {
        $user = Auth::user();

        return view('livewire.site-explorer-history', [
            'history' => $user ? $this->history((string) $user->id) : [],
        ]);
    }

    /**
     * @return array<int, array{domain: string, last_viewed_at: \Illuminate\Support\Carbon, is_own_website: bool}>
     */
    private function history(string $userId): array
    {
        $rows = ClientActivity::query()
            ->where('type', 'site_explorer.query')
            ->where('user_id', $userId)
            ->latest('created_at')
            ->limit(200) // bounded scan; dedup below rarely needs more than a handful of pages
            ->get(['meta', 'created_at']);

        $ownDomains = Website::query()
            ->where('user_id', $userId)
            ->pluck('normalized_domain')
            ->filter()
            ->all();

        $seen = [];
        foreach ($rows as $row) {
            $domain = (string) ($row->meta['domain'] ?? '');
            if ($domain === '' || isset($seen[$domain])) {
                continue;
            }
            $seen[$domain] = [
                'domain' => $domain,
                'last_viewed_at' => $row->created_at,
                'is_own_website' => in_array($domain, $ownDomains, true),
            ];
            if (count($seen) >= self::LIMIT) {
                break;
            }
        }

        return array_values($seen);
    }
}
