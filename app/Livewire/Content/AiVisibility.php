<?php

namespace App\Livewire\Content;

use App\Jobs\Content\AuditAeoReadinessJob;
use App\Models\ContentIntegration;
use App\Models\Website;
use App\Services\Content\Aeo\AeoSignalReader;
use App\Support\Aeo\AiAgents;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * /content/ai-visibility — "are the AI answers finding you?"
 *
 * Three honest signals, and the page is careful about which is which:
 *   - readiness: can the engines read the site (robots.txt per AI agent,
 *     llms.txt, structured data). Always available.
 *   - crawler hits: who actually fetched pages. Only for sites running our
 *     plugin or kit — absence is "not installed", never "no crawlers came".
 *   - AI referrals: people who arrived from an AI answer. Needs GA connected.
 *
 * Client-copy invariant: no internal states, no reason codes, and nothing that
 * implies we can see inside ChatGPT. We report what the site allows, what
 * fetched it, and who arrived.
 */
class AiVisibility extends Component
{
    public ?string $websiteId = null;

    /** Set for this request only, after the client asks for a fresh check. */
    public bool $checkQueued = false;

    public function mount(): void
    {
        $this->websiteId = session('current_website_id')
            ?: Auth::user()?->accessibleWebsitesQuery()->value('id');
    }

    #[On('website-changed')]
    public function switchWebsite(string $websiteId): void
    {
        $this->websiteId = $websiteId;
        $this->checkQueued = false;
    }

    /**
     * Re-run the readiness check now. The job is ShouldBeUnique, so a second
     * press while one is running is a no-op — the button promises "queued",
     * not "done".
     */
    public function recheck(): void
    {
        $website = $this->website();
        if ($website === null) {
            return;
        }
        AuditAeoReadinessJob::dispatch((string) $website->id);
        $this->checkQueued = true;
    }

    private function website(): ?Website
    {
        if (! $this->websiteId) {
            return null;
        }

        return Auth::user()?->accessibleWebsitesQuery()->whereKey($this->websiteId)->first();
    }

    public function render()
    {
        $website = $this->website();
        $reader = app(AeoSignalReader::class);

        if ($website === null) {
            return view('livewire.content.ai-visibility', [
                'website' => null, 'audit' => null, 'agents' => [],
                'hits' => ['instrumented' => false, 'stale' => false, 'last_report' => null, 'total' => 0, 'bots' => []],
                'referrals' => ['connected' => false, 'total' => 0, 'engines' => [], 'series' => []],
                'canInstrument' => false,
            ]);
        }

        $audit = $reader->latestAudit($website);
        $hits = $reader->crawlerHits($website);
        $hitsByBot = collect($hits['bots'])->keyBy('bot');

        // One row per agent, merging "are you allowed in" with "did you come".
        // Policy-only tokens (Google-Extended, Applebot-Extended) never fetch
        // anything, so their hit column reads "—", not "never seen".
        $agents = [];
        foreach (AiAgents::all() as $id => $agent) {
            $agents[] = [
                'id' => $id,
                'label' => $agent['label'],
                'engine' => $agent['engine'],
                'kind' => $agent['kind'],
                'crawls' => $agent['crawls'],
                'access' => (string) (($audit->robots_ai ?? [])[$id] ?? 'unknown'),
                'hits' => (int) ($hitsByBot[$id]['hits'] ?? 0),
                'pages' => (int) ($hitsByBot[$id]['pages'] ?? 0),
                'last_seen' => $hitsByBot[$id]['last_seen'] ?? null,
            ];
        }

        return view('livewire.content.ai-visibility', [
            'website' => $website,
            'audit' => $audit,
            'agents' => $agents,
            'hits' => $hits,
            'referrals' => $reader->referrals($website),
            // Only the platforms we ship code for can report crawler hits;
            // everywhere else the panel must say so instead of nagging.
            'canInstrument' => ContentIntegration::query()
                ->where('website_id', $website->id)
                ->whereIn('platform', [
                    ContentIntegration::PLATFORM_WORDPRESS,
                    ContentIntegration::PLATFORM_WORDPRESS_APP_PASSWORD,
                    ContentIntegration::PLATFORM_WEBHOOK,
                ])
                ->exists(),
        ]);
    }
}
