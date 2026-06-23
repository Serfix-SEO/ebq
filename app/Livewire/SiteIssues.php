<?php

namespace App\Livewire;

use App\Services\ActionQueueService;
use App\Services\Crawler\CrawlReportService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Dedicated, filterable + paginated detail page for ONE Priority Action Queue
 * group — the replacement for the old capped 100-row slide-over. Handles both
 * crawl_* findings (DB-paginated straight from crawl_findings, with a type/
 * severity/URL filter — essential when a single category holds tens of thousands
 * of findings) and the GSC/keyword action types (paginated in-memory; those sets
 * are small). Reached via {@see \App\Livewire\Dashboard\PriorityActionQueue}.
 */
class SiteIssues extends Component
{
    use WithPagination;

    public string $issueKey = '';

    public ?string $websiteId = null;

    /** Filters (crawl groups only). `type`/`severity` are no-ops for other groups. */
    #[Url(as: 'type')]
    public string $type = '';

    #[Url(as: 'sev')]
    public string $severity = '';

    #[Url(as: 'q')]
    public string $q = '';

    private const PER_PAGE = 50;

    private const NON_CRAWL = [
        'indexing_fails', 'cannibalization', 'content_decay', 'rank_drops',
        'audit_performance', 'striking_distance', 'quick_wins',
    ];

    public function mount(string $issueKey): void
    {
        $this->issueKey = $issueKey;
        $this->websiteId = session('current_website_id');

        abort_unless($this->isAllowedKey($issueKey), 404);
        abort_unless(($this->websiteId !== null && $this->websiteId !== '') && Auth::user()?->canViewWebsiteId($this->websiteId), 403);
    }

    public function updated(string $name): void
    {
        // Any filter change resets to page 1.
        if (in_array($name, ['type', 'severity', 'q'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->type = '';
        $this->severity = '';
        $this->q = '';
        $this->resetPage();
    }

    /** Drill into one issue type from the grouped (Semrush-style) breakdown view. */
    public function selectType(string $type): void
    {
        $this->type = $type;
        $this->resetPage();
    }

    private function isAllowedKey(string $key): bool
    {
        return in_array($key, self::NON_CRAWL, true) || str_starts_with($key, 'crawl_');
    }

    private function isCrawl(): bool
    {
        return str_starts_with($this->issueKey, 'crawl_');
    }

    private function category(): string
    {
        return substr($this->issueKey, strlen('crawl_'));
    }

    /** Title/description/severity/total for this group (from the cached queue). */
    private function meta(): array
    {
        $version = \App\Services\ReportCache::version($this->websiteId);
        $groups = Cache::remember(
            sprintf('action-queue:%s:%d:all', $this->websiteId, $version),
            600,
            fn (): array => app(ActionQueueService::class)->groupedActions($this->websiteId, null),
        );
        $item = collect($groups)->firstWhere('key', $this->issueKey);

        return [
            'title' => $item['title'] ?? 'Issue detail',
            'description' => $item['description'] ?? '',
            'severity' => $item['severity'] ?? 'high',
            'count' => (int) ($item['count'] ?? 0),
        ];
    }

    /** Type filter options (crawl groups only): [type => "Label (123)"]. */
    private function typeOptions(): array
    {
        if (! $this->isCrawl()) {
            return [];
        }
        $crawl = app(CrawlReportService::class);
        $out = [];
        foreach ($crawl->typeCounts($this->category(), $this->websiteId) as $type => $count) {
            $out[$type] = $crawl->typeLabel($type).' ('.number_format($count).')';
        }

        return $out;
    }

    /**
     * Default view for a crawl category: a Semrush-style breakdown by issue
     * TYPE instead of one undifferentiated row list. Drops to the flat row list
     * (rows()) once a type is picked or a search term overrides grouping.
     */
    private function showGroups(): bool
    {
        return $this->isCrawl() && $this->type === '' && trim($this->q) === '';
    }

    /** @return list<array{type:string,label:string,count:int,severity:string}> */
    private function groups(): array
    {
        return app(CrawlReportService::class)->typeBreakdown($this->category(), $this->websiteId, $this->severity);
    }

    /** The paginated, filtered rows for this group, normalized for the view. */
    private function rows(): LengthAwarePaginator
    {
        $user = Auth::user();
        $withAccess = fn (array $row): array => $row + [
            'fix_allowed' => ! empty($row['fix_url'])
                && ($user?->hasFeatureAccess($row['fix_feature'] ?? '', $this->websiteId) ?? false),
        ];

        if ($this->isCrawl()) {
            $crawl = app(CrawlReportService::class);
            $paginator = $crawl->issuesQuery($this->category(), $this->websiteId, [
                'type' => $this->type,
                'severity' => $this->severity,
                'q' => trim($this->q),
            ])->paginate(self::PER_PAGE);

            $paginator->getCollection()->transform(
                fn ($f) => $withAccess($crawl->mapFinding($f, $this->websiteId))
            );

            return $paginator;
        }

        // Non-crawl groups: build the full normalized set (small) and paginate it
        // in-memory, applying the free-text filter across title + subtitle.
        $all = app(ActionQueueService::class)->issueRows($this->issueKey, $this->websiteId, null);
        $needle = mb_strtolower(trim($this->q));
        if ($needle !== '') {
            $all = array_values(array_filter($all, fn (array $r): bool => str_contains(
                mb_strtolower(($r['title'] ?? '').' '.($r['subtitle'] ?? '')), $needle
            )));
        }

        $page = $this->getPage();
        $slice = array_map($withAccess, array_slice($all, ($page - 1) * self::PER_PAGE, self::PER_PAGE));

        return new LengthAwarePaginator($slice, count($all), self::PER_PAGE, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    public function render()
    {
        $grouped = $this->showGroups();

        return view('livewire.site-issues', [
            'meta' => $this->meta(),
            'grouped' => $grouped,
            'groups' => $grouped ? $this->groups() : [],
            'rows' => $grouped ? null : $this->rows(),
            'typeOptions' => $this->typeOptions(),
            'isCrawl' => $this->isCrawl(),
        ]);
    }
}
