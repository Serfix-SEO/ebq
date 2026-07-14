<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientActivity;
use App\Models\CrawlSite;
use App\Models\Plan;
use App\Models\User;
use App\Services\ClientActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', 'all');
        $sort = (string) $request->query('sort', 'recent');

        // ─── Compact summary cards ────────────────────────────────────────
        $activeSub = fn ($q) => $q->from('subscriptions')
            ->whereColumn('subscriptions.user_id', 'users.id')
            ->where('stripe_status', 'active');

        $summary = [
            'total' => User::query()->count(),
            'admins' => User::query()->where('is_admin', true)->count(),
            'disabled' => User::query()->where('is_disabled', true)->count(),
            'new_7d' => User::query()->where('created_at', '>=', Carbon::now()->subDays(7))->count(),
            // Trial → paid: users holding an ACTIVE Stripe subscription (every
            // account starts as trial, so an active sub = a conversion). Uses
            // the Cashier subscriptions table, not current_plan_slug — comped
            // plans (admin force-apply) also set the slug and must not count.
            'converted_paid' => User::query()->whereExists($activeSub)->count(),
            // Trial users who added a card but haven't subscribed: Cashier
            // stamps pm_type/pm_last_four when a payment method is saved.
            // High-intent segment worth chasing.
            'trial_with_card' => User::query()
                ->whereNotNull('pm_type')
                ->whereNotExists($activeSub)
                ->count(),
        ];

        $monthStart = Carbon::now()->startOfMonth();
        $costPerKeyword = (float) config('services.keywords_everywhere.cost_per_keyword_usd', 0.0001);
        $costPerCall = (float) config('services.serper.cost_per_call_usd', 0.0003);

        // ─── Per-user enrichments via correlated sub-selects so the listing
        //     stays a single query as it grows. None of these are filterable
        //     (we only use them for display), so sub-selects are fine here. ─
        $clients = User::query()
            ->select('users.*')
            ->selectSub(
                fn ($q) => $q->from('websites')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('websites.user_id', 'users.id'),
                'websites_count'
            )
            ->selectSub(
                fn ($q) => $q->from('client_activities')
                    ->selectRaw('MAX(created_at)')
                    ->whereColumn('client_activities.user_id', 'users.id')
                    // Admin support/debugging while impersonating shouldn't
                    // read back as the client's own "last activity".
                    ->where('is_impersonated', false),
                'last_activity_at'
            )
            // Trial-days-left badge: EXISTS is cheap and avoids a Cashier
            // subscribed() call (one query) per row.
            ->selectSub(
                fn ($q) => $q->from('subscriptions')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('subscriptions.user_id', 'users.id')
                    ->where('stripe_status', 'active'),
                'active_subs_count'
            )
            ->selectSub(
                fn ($q) => $q->from('client_activities')
                    ->selectRaw('COALESCE(SUM(units_consumed), 0)')
                    ->whereColumn('client_activities.user_id', 'users.id')
                    ->where('provider', 'keywords_everywhere')
                    ->where('created_at', '>=', $monthStart),
                'ke_units_mtd'
            )
            ->selectSub(
                fn ($q) => $q->from('client_activities')
                    ->selectRaw('COALESCE(SUM(units_consumed), 0)')
                    ->whereColumn('client_activities.user_id', 'users.id')
                    ->where('provider', 'serp_api')
                    ->where('created_at', '>=', $monthStart),
                'serp_units_mtd'
            )
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('name', 'like', '%'.$q.'%')
                    ->orWhere('email', 'like', '%'.$q.'%')
                    // Find the client that owns a website — admins usually know
                    // the domain, not the owner's email.
                    ->orWhereExists(fn ($sub) => $sub->from('websites')
                        ->whereColumn('websites.user_id', 'users.id')
                        ->where('websites.domain', 'like', '%'.$q.'%'));
            }))
            ->when($status === 'admins', fn ($query) => $query->where('is_admin', true))
            ->when($status === 'active', fn ($query) => $query->where('is_disabled', false))
            ->when($status === 'disabled', fn ($query) => $query->where('is_disabled', true))
            ->when($sort === 'name', fn ($query) => $query->orderBy('name'))
            ->when($sort === 'email', fn ($query) => $query->orderBy('email'))
            ->when($sort === 'spend', fn ($query) => $query->orderByRaw('(ke_units_mtd + serp_units_mtd) DESC'))
            ->when(! in_array($sort, ['name', 'email', 'spend'], true), fn ($query) => $query->orderByDesc('created_at'))
            ->with('websites:id,user_id,domain') // for the admin recrawl picker
            ->paginate(25)
            ->withQueryString();

        // Plans available to force-apply (comp) from the inline editor.
        // Ordered for display; the dropdown shows every plan so an operator
        // can also drop a client back to Free. `current_plan_slug` itself is
        // already loaded on each $client row (index selects users.*).
        $plans = Plan::query()
            ->orderBy('display_order')
            ->get(['slug', 'name', 'price_yearly_usd', 'is_active']);

        return view('admin.clients.index', [
            'clients' => $clients,
            'plans' => $plans,
            'q' => $q,
            'status' => $status,
            'sort' => $sort,
            'summary' => $summary,
            'rates' => [
                'keywords_everywhere' => $costPerKeyword,
                'serp_api' => $costPerCall,
            ],
            'editId' => (string) $request->query('edit', ''),
            'showCreate' => (bool) $request->query('new', 0) || $request->old('_create_open'),
        ]);
    }

    public function store(Request $request, ClientActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'is_admin' => ['nullable', 'boolean'],
        ]);

        $client = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'is_admin' => (bool) ($data['is_admin'] ?? false),
            'is_disabled' => false,
        ]);

        $logger->log('admin.client_created', userId: $client->id, meta: ['email' => $client->email]);

        return redirect()->route('admin.clients.index')->with('status', "Client {$client->email} created.");
    }

    /**
     * Bulk-toggle the disabled flag across a checkbox selection.
     *
     * The current admin's own id is filtered server-side so an operator
     * can't accidentally lock themselves out by clicking "Select all"
     * before "Disable" — the row checkbox is also hidden client-side,
     * but this is the load-bearing guard.
     */
    public function bulk(Request $request, ClientActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:disable,enable'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['string'],
        ]);

        $selfId = (string) ($request->user()?->id ?? '');
        $ids = collect($data['ids'])
            ->map(fn ($id) => $id)
            ->filter(fn (string $id) => ($id !== null && $id !== '') && $id !== $selfId)
            ->unique()
            ->values()
            ->all();

        $skippedSelf = in_array($selfId, $data['ids'], true);

        if ($ids === []) {
            return redirect()
                ->route('admin.clients.index', $request->query())
                ->with('status', $skippedSelf
                    ? 'You cannot disable your own account.'
                    : 'Nothing to update.');
        }

        $isDisable = $data['action'] === 'disable';
        $count = User::query()->whereIn('id', $ids)->update(['is_disabled' => $isDisable]);

        $type = $isDisable ? 'admin.clients_bulk_disabled' : 'admin.clients_bulk_enabled';
        foreach ($ids as $id) {
            $logger->log($type, userId: $id, meta: ['count' => $count]);
        }

        $verb = $isDisable ? 'Disabled' : 'Enabled';
        $msg = "{$verb} {$count} client" . ($count === 1 ? '' : 's') . '.';
        if ($skippedSelf) {
            $msg .= ' Your own account was skipped.';
        }

        return redirect()
            ->route('admin.clients.index', $request->query())
            ->with('status', $msg);
    }

    public function update(Request $request, User $user, ClientActivityLogger $logger): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'is_admin' => ['nullable', 'boolean'],
            'is_disabled' => ['nullable', 'boolean'],
            // Force-applied (comped) plan slug. Must be a real plan row.
            'plan_slug' => ['required', 'string', Rule::in(Plan::query()->pluck('slug')->all())],
        ]);

        // Snapshot the comped plan onto current_plan_slug — the same column
        // the Stripe webhook writes and that User::effectivePlan() reads at
        // step 3. For a client with no active paid subscription this moves
        // them onto the plan immediately, no payment involved. We store null
        // for the Free tier so the row matches a never-paid user.
        $newPlanSlug = $data['plan_slug'] === User::TIER_FREE ? null : $data['plan_slug'];
        $oldPlanSlug = $user->current_plan_slug;
        $planChanged = $oldPlanSlug !== $newPlanSlug;

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'is_admin' => (bool) ($data['is_admin'] ?? false),
            'is_disabled' => (bool) ($data['is_disabled'] ?? false),
            'current_plan_slug' => $newPlanSlug,
        ])->save();

        $logger->log('admin.client_updated', userId: $user->id, meta: [
            'is_admin' => $user->is_admin,
            'is_disabled' => $user->is_disabled,
        ]);

        // Separate, auditable trail for comped plan changes — these grant
        // paid entitlements without a Stripe charge, so log them distinctly.
        if ($planChanged) {
            $logger->log('admin.client_plan_forced', userId: $user->id, meta: [
                'from' => $oldPlanSlug ?? User::TIER_FREE,
                'to' => $newPlanSlug ?? User::TIER_FREE,
            ]);

            // crawl_sites.effective_cap is a stored aggregate (max crawl cap across
            // subscribers), only recomputed on website create/delete or first crawl
            // link — a plan change alone never touches a Website row, so without this
            // it silently keeps enforcing the OLD plan's page cap indefinitely.
            $user->websites()->whereNotNull('crawl_site_id')->with('crawlSite')->get()
                ->pluck('crawlSite')->filter()->unique('id')
                ->each(fn (CrawlSite $cs) => $cs->recomputeEffectiveCap());
        }

        $msg = "Client {$user->email} updated.";
        if ($planChanged) {
            $msg = "Client {$user->email} updated — plan set to ".($newPlanSlug ?? User::TIER_FREE).' (no payment).';
        }

        return redirect()->route('admin.clients.index')->with('status', $msg);
    }

    /**
     * Admin-triggered (re)crawl of a client's website. When the client has more
     * than one website, a website_id must be supplied (the view shows a picker).
     */
    public function crawl(Request $request, User $user): RedirectResponse
    {
        $websites = $user->websites()->get(['id', 'domain']);
        if ($websites->isEmpty()) {
            return redirect()->route('admin.clients.index', $request->query())
                ->with('status', "Client {$user->email} has no websites to crawl.");
        }

        $websiteId = (string) $request->input('website_id', '');
        $website = $websiteId !== ''
            ? $websites->firstWhere('id', $websiteId)
            : ($websites->count() === 1 ? $websites->first() : null);

        if (! $website) {
            return redirect()->route('admin.clients.index', $request->query())
                ->with('status', 'Select which website to crawl.');
        }

        // A frozen site (owner is over their plan's website limit) is a hard no-op
        // in CrawlWebsitePagesJob::handle() — it returns before creating a CrawlRun,
        // so dispatching here would silently do nothing while flashing "restarted".
        // Detect it up front and tell the admin why. Checked via the loaded owner's
        // frozen list because $website was selected with id+domain only (no user_id,
        // so calling isFrozen() on it would wrongly read as not-frozen).
        if (in_array((string) $website->id, $user->frozenWebsiteIds(), true)) {
            return redirect()->route('admin.clients.index', $request->query())
                ->with('status', "{$website->domain} is frozen — {$user->email} is over their plan's website limit. Raise the limit or remove another site to enable crawling.");
        }

        // An admin recrawl is an explicit force-restart. Release any held
        // ShouldBeUnique lock first (e.g. left by a crawl that died/aborted, or
        // still pending) so the dispatch always takes instead of being silently
        // de-duplicated. Both key spellings are released defensively.
        $uid = (new \App\Jobs\CrawlWebsitePagesJob($website->id))->uniqueId();
        foreach ([
            'laravel_unique_job:'.\App\Jobs\CrawlWebsitePagesJob::class.':'.$uid,
            'laravel_unique_job:'.\App\Jobs\CrawlWebsitePagesJob::class.$uid,
        ] as $lockKey) {
            \Illuminate\Support\Facades\Cache::lock($lockKey)->forceRelease();
        }

        \App\Jobs\CrawlWebsitePagesJob::dispatch($website->id, \App\Models\CrawlRun::TRIGGER_MANUAL, true);

        return redirect()->route('admin.clients.index', $request->query())
            ->with('status', "Crawl restarted for {$website->domain}.");
    }
}
