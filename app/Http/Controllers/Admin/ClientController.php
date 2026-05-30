<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientActivity;
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

        // ─── Compact summary cards: total / admins / disabled / new this week ─
        $summary = [
            'total' => User::query()->count(),
            'admins' => User::query()->where('is_admin', true)->count(),
            'disabled' => User::query()->where('is_disabled', true)->count(),
            'new_7d' => User::query()->where('created_at', '>=', Carbon::now()->subDays(7))->count(),
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
                    ->whereColumn('client_activities.user_id', 'users.id'),
                'last_activity_at'
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
                $w->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%');
            }))
            ->when($status === 'admins', fn ($query) => $query->where('is_admin', true))
            ->when($status === 'active', fn ($query) => $query->where('is_disabled', false))
            ->when($status === 'disabled', fn ($query) => $query->where('is_disabled', true))
            ->when($sort === 'name', fn ($query) => $query->orderBy('name'))
            ->when($sort === 'email', fn ($query) => $query->orderBy('email'))
            ->when($sort === 'spend', fn ($query) => $query->orderByRaw('(ke_units_mtd + serp_units_mtd) DESC'))
            ->when(! in_array($sort, ['name', 'email', 'spend'], true), fn ($query) => $query->orderByDesc('created_at'))
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
            'editId' => (int) $request->query('edit', 0),
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
            'ids.*' => ['integer', 'min:1'],
        ]);

        $selfId = (int) ($request->user()?->id ?? 0);
        $ids = collect($data['ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && $id !== $selfId)
            ->unique()
            ->values()
            ->all();

        $skippedSelf = in_array($selfId, array_map('intval', $data['ids']), true);

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
        }

        $msg = "Client {$user->email} updated.";
        if ($planChanged) {
            $msg = "Client {$user->email} updated — plan set to ".($newPlanSlug ?? User::TIER_FREE).' (no payment).';
        }

        return redirect()->route('admin.clients.index')->with('status', $msg);
    }
}
