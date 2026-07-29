<?php

namespace App\Console\Commands;

use App\Mail\ContentRankGainsMail;
use App\Models\ContentKeywordRankHistory;
use App\Models\ContentTrackedKeyword;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Preview/QA helper for the "your rankings moved up" digest. Renders REAL
 * tracked keywords for a website; when their recorded history has no gains yet
 * (a brand-new tracker), --demo fills in illustrative movements so the layout
 * can be reviewed. Read-only — never writes history or trips the daily throttle.
 */
class SendRankGainsTestMail extends Command
{
    protected $signature = 'ebq:rank-gains-test-mail
        {email : Where to send the preview}
        {--website= : Website id (default: the site with the most tracked keywords)}
        {--demo : Use illustrative movements instead of real recorded gains}';

    protected $description = 'Send a preview of the rank-improvement digest email';

    public function handle(): int
    {
        $websiteId = $this->option('website') ?: ContentTrackedKeyword::query()
            ->selectRaw('website_id, count(*) as tracked')
            ->groupBy('website_id')
            ->orderByDesc('tracked')
            ->value('website_id');

        $website = $websiteId ? Website::find($websiteId) : null;

        $owner = $website?->owner;
        if ($website === null || $owner === null) {
            $this->error('No website with tracked keywords found.');

            return self::FAILURE;
        }

        $keywords = ContentTrackedKeyword::query()->where('website_id', $website->id)->limit(6)->get();
        if ($keywords->isEmpty()) {
            $this->error("Website {$website->domain} has no tracked keywords.");

            return self::FAILURE;
        }

        $movements = $this->option('demo')
            ? $this->demoMovements($keywords)
            : $this->recordedGains($keywords);

        if ($movements === []) {
            $this->warn('No recorded gains for this website yet — re-run with --demo to preview the layout.');

            return self::SUCCESS;
        }

        Mail::to((string) $this->argument('email'))
            ->send(new ContentRankGainsMail($owner, $website, $movements));

        $this->info(sprintf(
            'Sent a %d-movement preview for %s to %s.',
            count($movements), $website->domain, $this->argument('email'),
        ));

        return self::SUCCESS;
    }

    /** Real improvements between each keyword's last two recorded checks. */
    private function recordedGains($keywords): array
    {
        $out = [];
        foreach ($keywords as $kw) {
            $points = ContentKeywordRankHistory::query()
                ->where('website_id', $kw->website_id)
                ->where('normalized_keyword', $kw->normalized_keyword)
                ->orderByDesc('checked_on')
                ->limit(2)
                ->get();
            $current = $points->first()?->position;
            $previous = $points->skip(1)->first()?->position;
            if ($current === null || $previous === null || $previous <= $current) {
                continue;
            }
            $out[] = [
                'keyword_id' => $kw->id,
                'keyword' => $kw->keyword,
                'previous' => $previous,
                'current' => $current,
                'gain' => $previous - $current,
                'milestone' => $current <= 3 ? 'top_3' : ($current <= 10 ? 'page_1' : null),
            ];
        }

        return $out;
    }

    private function demoMovements($keywords): array
    {
        $shape = [
            ['previous' => 9, 'current' => 2, 'milestone' => 'top_3'],
            ['previous' => 18, 'current' => 7, 'milestone' => 'page_1'],
            ['previous' => 34, 'current' => 21, 'milestone' => null],
            ['previous' => null, 'current' => 52, 'milestone' => 'now_ranking'],
            ['previous' => 15, 'current' => 11, 'milestone' => null],
        ];

        $out = [];
        foreach ($keywords->take(count($shape)) as $i => $kw) {
            $s = $shape[$i];
            $out[] = $s + [
                'keyword_id' => $kw->id,
                'keyword' => $kw->keyword,
                'gain' => $s['previous'] !== null ? $s['previous'] - $s['current'] : null,
            ];
        }

        return $out;
    }
}
