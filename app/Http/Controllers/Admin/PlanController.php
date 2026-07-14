<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin plan management — edit pricing, Stripe price IDs, trial days,
 * feature bullets, display order, active state.
 *
 * Plans drive three surfaces simultaneously:
 *   1. The marketing /pricing page (when migrated to read from DB).
 *   2. The public /api/v1/plans endpoint that powers the WordPress
 *      plugin's setup-wizard pricing step.
 *   3. The /billing/checkout endpoint that hands Stripe price IDs
 *      to Cashier when a user starts a trial.
 *
 * Slug is intentionally read-only after creation — it's the immutable
 * public identifier referenced by Stripe webhook handlers, the WP
 * plugin, and any in-flight checkout sessions. Renaming it would
 * orphan running subscriptions.
 *
 * `is_active=false` deprecates a plan without deleting historical
 * subscription rows that reference it.
 */
class PlanController extends Controller
{
    public function index(): View
    {
        $plans = Plan::query()->orderBy('display_order')->get();

        return view('admin.plans.index', [
            'plans' => $plans,
        ]);
    }

    public function create(): View
    {
        return view('admin.plans.edit', [
            'plan' => new Plan([
                'is_active' => true,
                'is_highlighted' => false,
                'trial_days' => 0,
                'display_order' => Plan::max('display_order') + 1,
                'features' => [],
                'plan_features' => array_fill_keys(Plan::FEATURE_KEYS, false),
            ]),
            'isNew' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePlanInput($request, isNew: true);
        $plan = Plan::create($data);

        return redirect()
            ->route('admin.plans.index')
            ->with('status', sprintf('Plan "%s" created.', $plan->name));
    }

    public function edit(Plan $plan): View
    {
        return view('admin.plans.edit', [
            'plan' => $plan,
            'isNew' => false,
        ]);
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $data = $this->validatePlanInput($request, isNew: false);
        $plan->update($data);

        return redirect()
            ->route('admin.plans.index')
            ->with('status', sprintf('Plan "%s" saved.', $plan->name));
    }

    /**
     * Sanity-validate + normalise plan input. Slug is required only on
     * creation; it can't be changed after that. Features come from the
     * form as a newline-separated textarea; we split into an array on
     * the way in. Stripe price IDs MUST start with `price_` — defends
     * against pasting product IDs (`prod_…`) or unrelated strings.
     */
    private function validatePlanInput(Request $request, bool $isNew): array
    {
        $rules = [
            'name' => 'required|string|max:64',
            'tagline' => 'nullable|string|max:191',
            'price_monthly_usd' => 'required|integer|min:0|max:99999',
            'price_yearly_usd' => 'nullable|integer|min:0|max:999999',
            'stripe_price_id_monthly' => 'nullable|string|max:128|regex:/^price_/',
            'stripe_price_id_yearly' => 'nullable|string|max:128|regex:/^price_/',
            'trial_days' => 'required|integer|min:0|max:365',
            // null / empty string = unlimited. Stored as null in DB.
            'max_websites' => 'nullable|integer|min:0|max:999',
            // null / empty = unlimited (global crawler cap applies).
            'max_crawl_pages' => 'nullable|integer|min:0|max:9999999',
            // null / empty = unlimited. Enforced on new invites only.
            'max_seats' => 'nullable|integer|min:0|max:9999',
            // null / empty = N/A. Display-only, no per-seat billing engine.
            'extra_seat_price_usd' => 'nullable|integer|min:0|max:99999',
            // Site Explorer lookups. null / empty limit = unlimited.
            'site_explorer_limit' => 'nullable|integer|min:0|max:99999',
            'site_explorer_window_hours' => 'nullable|integer|min:1|max:168',
            'features' => 'nullable|string|max:8000',
            'display_order' => 'required|integer|min:0|max:9999',
            'is_active' => 'sometimes|boolean',
            'is_highlighted' => 'sometimes|boolean',
            // Per-plan API caps. Blank field => unlimited (stored as
            // null inside the api_limits JSON column).
            'api_limits' => 'sometimes|array',
            'api_limits.keywords_everywhere.monthly_credits' => 'nullable|integer|min:0|max:10000000',
            'api_limits.serper.monthly_calls' => 'nullable|integer|min:0|max:10000000',
            'api_limits.mistral.monthly_tokens' => 'nullable|integer|min:0|max:1000000000',
            'api_limits.rank_tracker.max_active_keywords' => 'nullable|integer|min:0|max:100000',
            'api_limits.keyword_research.monthly_searches' => 'nullable|integer|min:0|max:1000000',
            'api_limits.keyword_research.max_results_per_search' => 'nullable|integer|min:0|max:1000000',
            'api_limits.ai_studio.monthly_tokens' => 'nullable|integer|min:0|max:100000000',
            'api_limits.long_form.monthly_articles' => 'nullable|integer|min:0|max:10000',
            'api_limits.quick_win_finder.results_shown' => 'nullable|integer|min:0|max:1000',
            // Plugin entitlement matrix. Submitted as a bag of
            // `plan_features[<key>]=on` checkbox fields; unchecked
            // boxes don't POST, so we default-fill below.
            'plan_features' => 'sometimes|array',
            'plan_features.*' => 'nullable|in:on,1,true',
        ];
        if ($isNew) {
            $rules['slug'] = 'required|string|max:32|alpha_dash|unique:plans,slug';
        }

        $data = $request->validate($rules);

        // Features textarea → array. One feature per line, trimmed,
        // empty lines dropped. A line may carry an optional explainer
        // video using the form "Bullet text | https://youtu.be/…": we
        // split on the first pipe and treat the right side as a video
        // only when it parses as a YouTube URL (so legitimate pipes in
        // bullet copy survive). The video map is position-keyed against
        // the cleaned features list, so both stay aligned.
        $features = [];
        $videos = [];
        foreach (preg_split('/\r?\n/', (string) ($data['features'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $text = $line;
            $video = null;
            if (str_contains($line, '|')) {
                [$left, $right] = explode('|', $line, 2);
                $candidate = trim($right);
                if (Plan::youtubeId($candidate) !== null) {
                    $text = trim($left);
                    $video = $candidate;
                }
            }

            // "| url" with no label left of the pipe — nothing to show.
            if ($text === '') {
                continue;
            }

            if ($video !== null) {
                $videos[(string) count($features)] = $video;
            }
            $features[] = $text;
        }
        $data['features'] = $features;
        $data['feature_videos'] = $videos !== [] ? $videos : null;

        // Checkboxes only POST when checked.
        $data['is_active'] = $request->boolean('is_active');
        $data['is_highlighted'] = $request->boolean('is_highlighted');

        // Compact api_limits: drop empty fields so null = unlimited
        // round-trips cleanly. Empty namespaces are stripped too.
        $apiLimits = $data['api_limits'] ?? [];
        $cleanLimits = [];
        foreach (['keywords_everywhere', 'serper', 'mistral', 'rank_tracker', 'keyword_research', 'ai_studio', 'long_form', 'quick_win_finder'] as $ns) {
            $node = $apiLimits[$ns] ?? [];
            if (! is_array($node)) {
                continue;
            }
            foreach ($node as $k => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $cleanLimits[$ns][$k] = (int) $v;
            }
        }
        $data['api_limits'] = $cleanLimits !== [] ? $cleanLimits : null;

        // Plugin entitlement matrix — checkbox payload normalisation.
        // Checkboxes only POST when ticked, so we explicitly fill the
        // full 8-key map with false for any flag the operator left off.
        // This is what makes "untick + save" actually remove the flag,
        // instead of silently leaving the previous DB value in place.
        $rawFlags = $request->input('plan_features', []);
        $planFeatures = [];
        foreach (Plan::FEATURE_KEYS as $key) {
            $planFeatures[$key] = (bool) ($rawFlags[$key] ?? false);
        }
        $data['plan_features'] = $planFeatures;

        return $data;
    }
}
