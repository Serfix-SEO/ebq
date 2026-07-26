<?php

namespace App\Services\Google;

use App\Models\GoogleAccount;
use App\Support\UrlNormalizer;
use Google\Service\AnalyticsData;
use Google\Service\AnalyticsData\DateRange;
use Google\Service\AnalyticsData\Dimension;
use Google\Service\AnalyticsData\Metric;
use Google\Service\AnalyticsData\RunReportRequest;
use Google\Service\GoogleAnalyticsAdmin;

class GoogleAnalyticsService
{
    public function __construct(private GoogleClientFactory $clientFactory) {}

    /**
     * @return array<int, array{id: string, name: string}>
     */
    public function listProperties(GoogleAccount $account): array
    {
        $client = $this->clientFactory->make($account);
        $admin = new GoogleAnalyticsAdmin($client);

        $properties = [];
        $pageToken = null;

        do {
            $response = $admin->accountSummaries->listAccountSummaries([
                'pageSize' => 200,
                'pageToken' => $pageToken,
            ]);

            foreach ($response->getAccountSummaries() as $summary) {
                foreach ($summary->getPropertySummaries() as $prop) {
                    $properties[] = [
                        'id' => $prop->getProperty(),
                        'name' => $prop->getDisplayName(),
                    ];
                }
            }

            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $properties;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchDailyTraffic(GoogleAccount $account, string $propertyId, string $startDate, string $endDate): array
    {
        $client = $this->clientFactory->make($account);
        $data = new AnalyticsData($client);

        $request = new RunReportRequest;
        $request->setDateRanges([
            new DateRange(['startDate' => $startDate, 'endDate' => $endDate]),
        ]);
        $request->setDimensions([
            new Dimension(['name' => 'date']),
            new Dimension(['name' => 'sessionSource']),
        ]);
        $request->setMetrics([
            new Metric(['name' => 'totalUsers']),
            new Metric(['name' => 'sessions']),
            new Metric(['name' => 'bounceRate']),
        ]);
        $request->setLimit(10000);

        $response = $data->properties->runReport($propertyId, $request);

        $rows = [];
        $now = now()->toDateTimeString();

        foreach ($response->getRows() ?? [] as $row) {
            $dims = $row->getDimensionValues();
            $metrics = $row->getMetricValues();

            $dateStr = substr($dims[0]->getValue(), 0, 4).'-'
                .substr($dims[0]->getValue(), 4, 2).'-'
                .substr($dims[0]->getValue(), 6, 2);

            $rows[] = [
                'date' => $dateStr,
                'source' => $dims[1]->getValue() ?: '(direct)',
                'users' => (int) ($metrics[0]->getValue() ?? 0),
                'sessions' => (int) ($metrics[1]->getValue() ?? 0),
                'bounce_rate' => round((float) ($metrics[2]->getValue() ?? 0) * 100, 2),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * Per-PAGE daily traffic for Content Autopilot article reporting. GA4's
     * pagePath is host-relative, so we pull hostName too and rebuild the full
     * https URL, normalized the same way GSC pages are (UrlNormalizer) — that's
     * the join key between content_page_analytics and search_console_data.page.
     *
     * @return array<int, array<string, mixed>> rows keyed for upsert into content_page_analytics
     */
    public function fetchPageTraffic(GoogleAccount $account, string $propertyId, string $startDate, string $endDate): array
    {
        $client = $this->clientFactory->make($account);
        $data = new AnalyticsData($client);

        $request = new RunReportRequest;
        $request->setDateRanges([
            new DateRange(['startDate' => $startDate, 'endDate' => $endDate]),
        ]);
        $request->setDimensions([
            new Dimension(['name' => 'date']),
            new Dimension(['name' => 'hostName']),
            new Dimension(['name' => 'pagePath']),
        ]);
        $request->setMetrics([
            new Metric(['name' => 'screenPageViews']),
            new Metric(['name' => 'sessions']),
            new Metric(['name' => 'totalUsers']),
            new Metric(['name' => 'engagementRate']),
        ]);
        $request->setLimit(100000);

        $response = $data->properties->runReport($propertyId, $request);

        // Aggregate into (date, normalized-url) buckets: two GA paths can
        // normalize to the same URL (e.g. trailing slash), and the upsert key
        // is [website_id, date, page] so duplicates within one batch would clash.
        $buckets = [];
        $now = now()->toDateTimeString();

        foreach ($response->getRows() ?? [] as $row) {
            $dims = $row->getDimensionValues();
            $metrics = $row->getMetricValues();

            $raw = $dims[0]->getValue();
            $date = substr($raw, 0, 4).'-'.substr($raw, 4, 2).'-'.substr($raw, 6, 2);
            $host = trim((string) ($dims[1]->getValue() ?? ''));
            $path = (string) ($dims[2]->getValue() ?? '/');
            if ($host === '') {
                continue;
            }
            $url = UrlNormalizer::normalize('https://'.$host.$path);
            $bkey = $date.'|'.$url;

            if (! isset($buckets[$bkey])) {
                $buckets[$bkey] = [
                    'date' => $date,
                    'page' => mb_substr($url, 0, 600),
                    'pageviews' => 0,
                    'sessions' => 0,
                    'users' => 0,
                    'engagement_sum' => 0.0,
                    'engagement_n' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            $buckets[$bkey]['pageviews'] += (int) ($metrics[0]->getValue() ?? 0);
            $buckets[$bkey]['sessions'] += (int) ($metrics[1]->getValue() ?? 0);
            $buckets[$bkey]['users'] += (int) ($metrics[2]->getValue() ?? 0);
            $buckets[$bkey]['engagement_sum'] += (float) ($metrics[3]->getValue() ?? 0);
            $buckets[$bkey]['engagement_n']++;
        }

        $rows = [];
        foreach ($buckets as $b) {
            $rows[] = [
                'date' => $b['date'],
                'page' => $b['page'],
                'pageviews' => $b['pageviews'],
                'sessions' => $b['sessions'],
                'users' => $b['users'],
                'engagement_rate' => $b['engagement_n'] > 0
                    ? round($b['engagement_sum'] / $b['engagement_n'] * 100, 2)
                    : null,
                'created_at' => $b['created_at'],
                'updated_at' => $b['updated_at'],
            ];
        }

        return $rows;
    }

    /**
     * Range-DEDUPLICATED user totals for two windows in ONE API call (the
     * Data API accepts multiple dateRanges). This is what the GA4 UI shows:
     * "active users" counts a person once per range, whereas summing our
     * daily rows counts them once per active day — structurally higher and
     * with a different period-over-period delta (user-reported mismatch,
     * 2026-07-07). Also brings in newUsers, which daily rows never had.
     *
     * @return array{current: array{active_users:int,new_users:int}, previous: array{active_users:int,new_users:int}}|null
     */
    public function fetchRangeUserTotals(
        GoogleAccount $account,
        string $propertyId,
        string $currentStart,
        string $currentEnd,
        string $previousStart,
        string $previousEnd,
    ): ?array {
        $client = $this->clientFactory->make($account);
        $data = new AnalyticsData($client);

        $request = new RunReportRequest;
        $request->setDateRanges([
            new DateRange(['startDate' => $currentStart, 'endDate' => $currentEnd, 'name' => 'current']),
            new DateRange(['startDate' => $previousStart, 'endDate' => $previousEnd, 'name' => 'previous']),
        ]);
        $request->setMetrics([
            new Metric(['name' => 'activeUsers']),
            new Metric(['name' => 'newUsers']),
        ]);

        $response = $data->properties->runReport($propertyId, $request);

        $out = [
            'current' => ['active_users' => 0, 'new_users' => 0],
            'previous' => ['active_users' => 0, 'new_users' => 0],
        ];
        foreach ($response->getRows() ?? [] as $row) {
            // With multiple dateRanges and no dimensions, each row carries the
            // range name as an implicit dimension value.
            $range = $row->getDimensionValues()[0]?->getValue() ?? '';
            $key = $range === 'date_range_1' || $range === 'previous' ? 'previous' : 'current';
            $metrics = $row->getMetricValues();
            $out[$key] = [
                'active_users' => (int) ($metrics[0]->getValue() ?? 0),
                'new_users' => (int) ($metrics[1]->getValue() ?? 0),
            ];
        }

        return $out;
    }
}
