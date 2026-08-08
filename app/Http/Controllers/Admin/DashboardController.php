<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentTopic;
use App\Models\Lead;
use App\Models\User;
use App\Models\Website;
use App\Services\Content\ContentEntitlements;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;

/**
 * Admin home: the day at a glance + the funnel + revenue.
 *
 * Counting rules (deliberate, keep them honest):
 *  - "Customers" excludes system accounts AND admin accounts. The owner's own
 *    test subscription inflated "Trial → paid" on the clients page
 *    (2026-08-08) — internal accounts are shown in their own tile instead.
 *  - Paid = an ACTIVE Cashier subscription (any product). Comped plans set
 *    current_plan_slug but hold no subscription, so they never count as paid.
 *  - Money comes from Stripe (paid invoices), never derived locally, and is
 *    cached briefly + failure-tolerant: a Stripe outage renders "—", not a
 *    broken admin home.
 */
class DashboardController extends Controller
{
    private const SERIES_DAYS = 14;

    public function index(): View
    {
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $yesterday = $today->copy()->subDay();

        // Real customers: not system, not admin.
        $customers = fn () => User::query()->where('is_system', false)->where('is_admin', false);

        $activeSub = function ($q): void {
            $q->selectRaw(1)->from('subscriptions')
                ->whereColumn('subscriptions.user_id', 'users.id')
                ->whereIn('stripe_status', ['active', 'trialing', 'past_due']);
        };

        // ── Today vs yesterday ──────────────────────────────────────────
        $daily = [
            'signups' => [
                'today' => $customers()->where('created_at', '>=', $today)->count(),
                'yesterday' => $customers()->whereBetween('created_at', [$yesterday, $today])->count(),
            ],
            'trials' => [
                'today' => $customers()->where('content_trial_started_at', '>=', $today)->count(),
                'yesterday' => $customers()->whereBetween('content_trial_started_at', [$yesterday, $today])->count(),
            ],
            'articles' => [
                'today' => ContentTopic::query()->where('status', ContentTopic::STATUS_PUBLISHED)
                    ->where('published_at', '>=', $today)->count(),
                'yesterday' => ContentTopic::query()->where('status', ContentTopic::STATUS_PUBLISHED)
                    ->whereBetween('published_at', [$yesterday, $today])->count(),
            ],
            'leads' => [
                'today' => Lead::query()->where('created_at', '>=', $today)->count(),
                'yesterday' => Lead::query()->whereBetween('created_at', [$yesterday, $today])->count(),
            ],
        ];

        // ── User segments ───────────────────────────────────────────────
        $totalCustomers = $customers()->count();
        $paid = $customers()->whereExists($activeSub)->count();
        $onTrial = $customers()->whereNotExists($activeSub)
            ->where('content_trial_ends_at', '>', $now)->count();
        $withCard = $customers()->whereNotNull('pm_type')->whereNotExists($activeSub)->count();
        $disabled = $customers()->where('is_disabled', true)->count();

        $segments = [
            'total' => $totalCustomers,
            'paid' => $paid,
            'on_trial' => $onTrial,
            // Free = everyone else: trial spent or never started, no sub.
            'free' => max(0, $totalCustomers - $paid - $onTrial),
            'with_card' => $withCard,
            'disabled' => $disabled,
            'internal' => User::query()->where(fn ($q) => $q->where('is_system', true)->orWhere('is_admin', true))->count(),
            'websites' => Website::query()->count(),
            'articles_total' => ContentTopic::query()->where('status', ContentTopic::STATUS_PUBLISHED)->count(),
        ];

        // ── 14-day series (signups / trials / published articles) ───────
        $days = collect(range(self::SERIES_DAYS - 1, 0))
            ->map(fn (int $back) => $today->copy()->subDays($back));
        $series = [
            'labels' => $days->map(fn (Carbon $d) => $d->format('D j'))->all(),
            'signups' => $this->perDay($customers()->getQuery(), 'created_at', $days),
            'trials' => $this->perDay($customers()->getQuery(), 'content_trial_started_at', $days),
            'articles' => $this->perDay(
                ContentTopic::query()->where('status', ContentTopic::STATUS_PUBLISHED)->getQuery(),
                'published_at',
                $days,
            ),
        ];

        // ── Latest customer activity ────────────────────────────────────
        $recentSignups = $customers()->latest()->limit(6)
            ->get(['id', 'name', 'email', 'created_at', 'content_trial_started_at']);
        $ent = app(ContentEntitlements::class);
        $recentSignups->each(function (User $u) use ($ent): void {
            $u->setAttribute('segment_label', match (true) {
                $ent->hasContentSubscription($u) => 'paid',
                $ent->onContentTrial($u) => 'trial',
                default => 'free',
            });
        });

        $recentSubscriptions = User::query()->where('is_system', false)
            ->join('subscriptions', 'subscriptions.user_id', '=', 'users.id')
            ->whereIn('subscriptions.stripe_status', ['active', 'trialing', 'past_due'])
            ->orderByDesc('subscriptions.created_at')
            ->limit(6)
            ->get([
                'users.id', 'users.name', 'users.email', 'users.is_admin',
                'subscriptions.type as sub_type', 'subscriptions.stripe_status as sub_status',
                'subscriptions.created_at as subscribed_at',
            ]);

        return view('admin.dashboard', [
            'daily' => $daily,
            'segments' => $segments,
            'series' => $series,
            'recentSignups' => $recentSignups,
            'recentSubscriptions' => $recentSubscriptions,
            'stripe' => $this->stripeSnapshot($today),
        ]);
    }

    /** @return array<int, int> one count per element of $days */
    private function perDay(Builder $base, string $column, Collection $days): array
    {
        $from = $days->first();
        $rows = (clone $base)
            ->whereNotNull($column)
            ->where($column, '>=', $from)
            ->selectRaw("date($column) as d, count(*) as c")
            ->groupBy('d')
            ->pluck('c', 'd');

        return $days->map(fn (Carbon $d) => (int) ($rows[$d->toDateString()] ?? 0))->all();
    }

    /**
     * Drill-down: the RECORDS behind a dashboard tile. Every metric resolves
     * to the same row shape so one table view serves them all, and each query
     * mirrors the tile's counting rules exactly — a drill that disagrees with
     * its tile is worse than no drill.
     */
    public function drill(string $metric): View
    {
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();
        $customers = fn () => User::query()->where('is_system', false)->where('is_admin', false);
        $activeSub = function ($q): void {
            $q->selectRaw(1)->from('subscriptions')
                ->whereColumn('subscriptions.user_id', 'users.id')
                ->whereIn('stripe_status', ['active', 'trialing', 'past_due']);
        };

        $userRows = fn ($query) => $query->latest()->limit(200)->get()
            ->map(fn (User $u) => [
                'title' => $u->name ?: $u->email,
                'subtitle' => $u->email,
                'badge' => $u->is_disabled ? 'disabled' : null,
                'at' => $u->created_at,
                'href' => route('admin.clients.index', ['q' => $u->email]),
            ]);

        [$label, $rows] = match ($metric) {
            'signups-today' => ['Signups today', $userRows($customers()->where('created_at', '>=', $today))],
            'trials-today' => ['Trials started today', $userRows($customers()->where('content_trial_started_at', '>=', $today))],
            'customers' => ['All customers', $userRows($customers())],
            'paid' => ['Paid customers', $userRows($customers()->whereExists($activeSub))],
            'on-trial' => ['Customers on trial', $userRows($customers()->whereNotExists($activeSub)->where('content_trial_ends_at', '>', $now))],
            'free' => ['Free customers', $userRows($customers()->whereNotExists($activeSub)->where(fn ($q) => $q->whereNull('content_trial_ends_at')->orWhere('content_trial_ends_at', '<=', $now)))],
            'with-card' => ['Card added, not paid', $userRows($customers()->whereNotNull('pm_type')->whereNotExists($activeSub))],
            'disabled' => ['Disabled customers', $userRows($customers()->where('is_disabled', true))],
            'internal' => ['Internal accounts', $userRows(User::query()->where(fn ($q) => $q->where('is_system', true)->orWhere('is_admin', true)))],
            'articles-today', 'articles-all' => [
                $metric === 'articles-today' ? 'Articles published today' : 'Published articles (all time)',
                ContentTopic::query()->with('website')
                    ->where('status', ContentTopic::STATUS_PUBLISHED)
                    ->when($metric === 'articles-today', fn ($q) => $q->where('published_at', '>=', $today))
                    ->orderByDesc('published_at')->limit(200)->get()
                    ->map(fn (ContentTopic $t) => [
                        'title' => $t->title ?: $t->target_keyword,
                        'subtitle' => $t->website?->domain ?? '—',
                        'badge' => null,
                        'at' => $t->published_at,
                        'href' => null,
                    ]),
            ],
            'leads-today' => [
                'Leads today',
                Lead::query()->where('created_at', '>=', $today)->latest()->limit(200)->get()
                    ->map(fn (Lead $l) => [
                        'title' => $l->name ?: $l->email,
                        'subtitle' => $l->email.($l->source ? ' · '.$l->source : ''),
                        'badge' => $l->converted_at ? 'converted' : null,
                        'at' => $l->created_at,
                        'href' => route('admin.leads.index'),
                    ]),
            ],
            'websites' => [
                'Websites',
                Website::query()->with('user')->latest()->limit(200)->get()
                    ->map(fn (Website $w) => [
                        'title' => $w->domain ?: '(no domain)',
                        'subtitle' => $w->user?->email ?? '—',
                        'badge' => null,
                        'at' => $w->created_at,
                        'href' => $w->user ? route('admin.clients.index', ['q' => $w->user->email]) : null,
                    ]),
            ],
            'payments-today', 'payments-month' => [
                $metric === 'payments-today' ? 'Payments today' : 'Payments this month',
                collect($this->stripeSnapshot($today)['recent'])
                    ->when($metric === 'payments-today', fn ($c) => $c->filter(fn ($p) => $p['at'] !== null && $p['at']->gte($today)))
                    ->map(fn (array $p) => [
                        'title' => $p['email'] ?? 'unknown',
                        'subtitle' => number_format($p['amount'], 2).' '.$p['currency'],
                        'badge' => null,
                        'at' => $p['at'],
                        'href' => $p['url'],
                    ])->values(),
            ],
            default => abort(404),
        };

        return view('admin.dashboard-drill', [
            'metric' => $metric,
            'label' => $label,
            'rows' => $rows,
        ]);
    }

    /**
     * Payments straight from Stripe, cached 10 minutes.
     *
     * @return array{available: bool, today_count: int, today_amount: float,
     *               month_amount: float, mrr: float|null, currency: string,
     *               recent: array<int, array{email: ?string, amount: float, currency: string, at: ?Carbon, url: ?string}>}
     */
    private function stripeSnapshot(Carbon $today): array
    {
        $unavailable = [
            'available' => false, 'today_count' => 0, 'today_amount' => 0.0,
            'month_amount' => 0.0, 'mrr' => null, 'currency' => 'USD', 'recent' => [],
        ];
        $secret = (string) config('cashier.secret');
        if ($secret === '') {
            return $unavailable;
        }

        return Cache::remember('admin:dashboard:stripe:v1', 600, function () use ($secret, $today, $unavailable) {
            try {
                $stripe = new StripeClient($secret);

                // Paid invoices this month cover both "today" and "this month"
                // in one call (volume is far below the 100 cap for now).
                $monthStart = $today->copy()->startOfMonth();
                $invoices = $stripe->invoices->all([
                    'status' => 'paid',
                    'created' => ['gte' => $monthStart->getTimestamp()],
                    'limit' => 100,
                ]);

                $todayCount = 0;
                $todayAmount = 0.0;
                $monthAmount = 0.0;
                $recent = [];
                $currency = 'USD';
                foreach ($invoices->data as $inv) {
                    $amount = (int) ($inv->amount_paid ?? 0);
                    if ($amount <= 0) {
                        continue; // $0 trial-start invoices are noise
                    }
                    $currency = strtoupper((string) ($inv->currency ?? 'usd'));
                    $monthAmount += $amount / 100;
                    if ((int) $inv->created >= $today->getTimestamp()) {
                        $todayCount++;
                        $todayAmount += $amount / 100;
                    }
                    if (count($recent) < 6) {
                        $recent[] = [
                            'email' => $inv->customer_email ?? null,
                            'amount' => round($amount / 100, 2),
                            'currency' => strtoupper((string) ($inv->currency ?? 'usd')),
                            'at' => isset($inv->created) ? Carbon::createFromTimestamp((int) $inv->created) : null,
                            'url' => $inv->hosted_invoice_url ?? null,
                        ];
                    }
                }

                // MRR: every active subscription item, normalised to monthly.
                $mrr = 0.0;
                $subs = $stripe->subscriptions->all(['status' => 'active', 'limit' => 100]);
                foreach ($subs->data as $sub) {
                    foreach ($sub->items->data ?? [] as $item) {
                        $price = $item->price ?? null;
                        if ($price === null || ($price->unit_amount ?? null) === null) {
                            continue;
                        }
                        $qty = (int) ($item->quantity ?? 1);
                        $monthly = match ($price->recurring->interval ?? 'month') {
                            'year' => $price->unit_amount / 12,
                            'week' => $price->unit_amount * 4.33,
                            'day' => $price->unit_amount * 30,
                            default => $price->unit_amount,
                        };
                        $mrr += ($monthly / 100) * $qty;
                    }
                }

                return [
                    'available' => true,
                    'today_count' => $todayCount,
                    'today_amount' => round($todayAmount, 2),
                    'month_amount' => round($monthAmount, 2),
                    'mrr' => round($mrr, 2),
                    'currency' => $currency,
                    'recent' => $recent,
                ];
            } catch (\Throwable $e) {
                Log::warning('Admin dashboard: Stripe snapshot failed: '.$e->getMessage());

                return $unavailable;
            }
        });
    }
}
