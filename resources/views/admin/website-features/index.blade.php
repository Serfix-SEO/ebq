<x-layouts.app>
    @php
        /**
         * @var \Illuminate\Pagination\LengthAwarePaginator $websites
         * @var string $q
         * @var array<int, string> $feature_keys
         * @var array<string, string> $feature_labels
         * @var array<string, bool>  $global_flags
         */
    @endphp

    <div class="px-4 sm:px-6 lg:px-8 py-6">
        <x-admin.plugin-tabs current="feature-flags" />

        <div class="flex items-end justify-between mb-4">
            <div>
                <h2 class="text-lg font-semibold text-gray-900">Per-website feature flags</h2>
                <p class="mt-1 text-sm text-gray-500">
                    Toggle individual WordPress-plugin features per connected website.
                    A turned-OFF cell takes effect on the next API call from that site
                    (or up to 12 hours later when the cached transient on the WP side
                    expires). Core SEO output (sitemap, schema, meta tags, breadcrumbs)
                    is never gated here — it's always-on. The global kill-switch panel
                    above overrides every row here.
                </p>
            </div>
            <form method="GET" action="{{ route('admin.website-features.index') }}"
                  class="flex items-center gap-2">
                <input type="search" name="q" value="{{ $q }}"
                       placeholder="Search by domain"
                       class="text-sm border-gray-300 rounded-md shadow-sm focus:border-orange-500 focus:ring-orange-500" />
                <button type="submit"
                        class="text-sm font-medium px-3 py-1.5 rounded-md bg-gray-900 text-white hover:bg-gray-700">
                    Search
                </button>
            </form>
        </div>

        @if (session('status'))
            <div class="mb-4 rounded-md bg-emerald-50 border border-emerald-200 px-3 py-2 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        {{-- Global master kill-switch panel. Overrides every per-website
             flag below — toggling a feature OFF here disables it across
             every install (connected AND unconnected). Visually distinct
             so admins understand the blast radius. --}}
        <form method="POST" action="{{ route('admin.website-features.global-update') }}"
              class="mb-6 rounded-lg border border-orange-200 bg-orange-50/60 shadow-sm">
            @csrf
            @method('PUT')
            <div class="px-4 py-3 border-b border-orange-200 flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold text-orange-900">⚠️ Global feature kill-switch</h2>
                    <p class="text-xs text-orange-700 mt-0.5">
                        Master toggles that override every per-website flag below. Reaches connected sites
                        within seconds (next API call) and unconnected sites within ~6 hours (next plugin
                        update check). Turning a feature OFF here hides it from every installation, even ones
                        that have it enabled in their per-site row.
                    </p>
                </div>
                <button type="submit"
                        class="text-xs font-medium px-3 py-1.5 rounded-md bg-orange-700 text-white hover:bg-orange-600 whitespace-nowrap">
                    Save global flags
                </button>
            </div>
            <div class="px-4 py-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
                @foreach ($feature_keys as $key)
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox"
                               name="global_features[{{ $key }}]"
                               value="1"
                               @checked($global_flags[$key] ?? true)
                               class="mt-0.5 h-4 w-4 rounded border-orange-300 text-orange-600 focus:ring-orange-500">
                        <div>
                            <div class="text-xs font-medium text-orange-900">
                                {{ $feature_labels[$key] ?? $key }}
                            </div>
                            <div class="text-[10px] uppercase tracking-wide text-orange-600/70 mt-0.5">
                                {{ $key }}
                            </div>
                        </div>
                    </label>
                @endforeach
            </div>
        </form>

        <div class="overflow-x-auto bg-white shadow ring-1 ring-gray-200 sm:rounded-lg">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="sticky left-0 bg-gray-50 z-10 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Website
                        </th>
                        <th class="px-2 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Tier
                        </th>
                        @foreach ($feature_keys as $key)
                            <th class="px-2 py-3 text-center text-xs font-semibold uppercase tracking-wide text-gray-500"
                                title="{{ $feature_labels[$key] ?? $key }}">
                                {{ str_replace('_', ' ', $key) }}
                            </th>
                        @endforeach
                        <th class="px-3 py-3 text-right text-xs font-semibold uppercase tracking-wide text-gray-500">
                            &nbsp;
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse ($websites as $website)
                        @php
                            // For the checkboxes we want to reflect what's
                            // CURRENTLY stored on the website (or the
                            // shipped default if the override is null), so
                            // an admin can clearly see the existing intent
                            // and toggle it. We deliberately don't apply
                            // the freeze override here — admins should be
                            // able to configure flags ahead of an upgrade.
                            $stored = is_array($website->feature_flags) ? $website->feature_flags : [];
                            $effectiveTier = $website->effectiveTier();
                            $isFrozen = $website->isFrozen();

                            // Per-plan ceiling: the owning user's plan
                            // boolean map decides which flags this row is
                            // *allowed* to enable. Plan defaults render as
                            // "Locked by plan" badges on disallowed cells.
                            // The form still POSTs whatever the admin
                            // ticked — effectiveFeatureFlags() clamps at
                            // read time so this never accidentally widens
                            // the entitlement.
                            $owner = $website->owner;
                            $planFeatures = $owner !== null
                                ? $owner->effectivePlanFeatures()
                                : array_fill_keys(\App\Models\Website::FEATURE_KEYS, true);
                            $tierTone = match ($effectiveTier) {
                                'free'    => 'bg-gray-100 text-gray-700',
                                'pro'     => 'bg-orange-100 text-orange-800',
                                'startup' => 'bg-orange-100 text-orange-800',
                                'agency'  => 'bg-emerald-100 text-emerald-800',
                                default   => 'bg-gray-100 text-gray-700',
                            };
                        @endphp
                        <tr>
                            <form method="POST" action="{{ route('admin.website-features.update', $website) }}">
                                @csrf
                                @method('PUT')
                                <td class="sticky left-0 bg-white z-10 px-4 py-3 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">{{ $website->domain }}</div>
                                    <div class="text-xs text-gray-500">
                                        @if ($website->owner)
                                            {{ $website->owner->name ?: $website->owner->email }}
                                        @else
                                            <span class="italic text-gray-400">no owner</span>
                                        @endif
                                        @if ($isFrozen)
                                            <span class="ml-1 inline-flex items-center rounded-full bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">frozen</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-2 py-3 text-xs">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $tierTone }}">
                                        {{ $effectiveTier }}
                                    </span>
                                </td>
                                @foreach ($feature_keys as $key)
                                    @php
                                        $planAllows = (bool) ($planFeatures[$key] ?? false);
                                        $required = $planAllows ? null : \App\Models\Plan::requiredPlanFor($key);
                                    @endphp
                                    <td class="px-2 py-3 text-center">
                                        @if ($planAllows)
                                            <label class="inline-flex items-center justify-center cursor-pointer">
                                                <input type="checkbox"
                                                       name="feature_flags[{{ $key }}]"
                                                       value="1"
                                                       @checked(array_key_exists($key, $stored) ? (bool) $stored[$key] : true)
                                                       class="h-4 w-4 rounded border-gray-300 text-orange-600 focus:ring-orange-500">
                                            </label>
                                        @else
                                            <span class="inline-flex flex-col items-center gap-0.5"
                                                  title="{{ $feature_labels[$key] ?? $key }} is not available on the owner's current plan ({{ $effectiveTier }})">
                                                <input type="checkbox" disabled
                                                       class="h-4 w-4 rounded border-gray-300 text-gray-400 cursor-not-allowed">
                                                <span class="text-[9px] uppercase tracking-wide text-amber-700 font-semibold">
                                                    @if ($required)
                                                        Needs {{ $required }}
                                                    @else
                                                        Locked
                                                    @endif
                                                </span>
                                            </span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-3 text-right">
                                    <button type="submit"
                                            class="text-xs font-medium px-3 py-1.5 rounded-md bg-orange-600 text-white hover:bg-orange-500">
                                        Save
                                    </button>
                                </td>
                            </form>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($feature_keys) + 3 }}"
                                class="px-4 py-12 text-center text-sm text-gray-500">
                                @if ($q !== '')
                                    No websites match "{{ $q }}".
                                @else
                                    No websites yet. Once a customer connects their WordPress
                                    site via the Serfix plugin, it'll appear here.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $websites->links() }}
        </div>

        <p class="mt-6 text-xs text-gray-500">
            <strong>How feature toggles propagate:</strong> the WordPress plugin caches the
            flag map for 12 hours. Toggles take effect on the next plugin → Serfix API call
            (passive sync via every API response carrying a fresh <code>features</code>
            field), or at the latest within 12 hours when the local transient expires.
            Toggling a feature OFF retracts every UI surface, REST route, and enqueued
            asset for that feature on the customer's site without a plugin update.
        </p>
        <p class="mt-3 text-xs text-gray-500">
            <strong>Plan ceiling:</strong> cells marked "Needs &lt;plan&gt;" are disabled because
            the owner's <a href="{{ route('admin.plans.index') }}" class="underline">subscription plan</a>
            does not include that feature. Per-site overrides above can <em>narrow</em> the plan
            entitlement (turn a flag off) but cannot <em>widen</em> it (turn a plan-disallowed
            flag on) — the server enforces this at read time, so even a ticked checkbox here is
            ignored if the plan disallows it.
        </p>
    </div>
</x-layouts.app>
