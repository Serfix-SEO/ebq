@props([
    /**
     * Identifier of the active tab. One of: 'releases', 'adoption', 'feature-flags'.
     * Drives the visual highlight; safe when missing (no tab is highlighted).
     */
    'current' => '',
])

@php
    $tabs = [
        [
            'key'   => 'releases',
            'route' => 'admin.plugin-releases.index',
            'label' => 'Releases',
            'desc'  => 'Versioning, packaging, rollback',
        ],
        [
            'key'   => 'adoption',
            'route' => 'admin.plugin-adoption.index',
            'label' => 'Adoption',
            'desc'  => 'Connected sites, version distribution',
        ],
        [
            'key'   => 'feature-flags',
            'route' => 'admin.website-features.index',
            'label' => 'Feature flags',
            'desc'  => 'Per-website + global kill-switch',
        ],
        [
            'key'   => 'billing',
            'route' => 'admin.billing.index',
            'label' => 'Billing',
            'desc'  => 'Subscriptions, trials, Stripe state',
        ],
        // Plans are deliberately NOT here — they're a global SaaS-wide
        // concern (marketing /pricing, plugin wizard, Stripe checkout,
        // any future product surface). Lives at the top-level admin
        // sidebar entry "Plans" instead. Don't add it back without
        // taking it out of `app.blade.php`'s `$adminItems`.
    ];
@endphp

<div class="mb-6 border-b border-slate-200 dark:border-slate-800">
    <div class="mb-4 flex items-baseline justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">WordPress Plugin</h1>
            <p class="mt-1 text-sm text-slate-500">
                Manage releases, track adoption across customer sites, and control which
                plugin features are exposed at the global and per-website level.
            </p>
        </div>
    </div>

    <nav class="-mb-px flex gap-2 overflow-x-auto" aria-label="Plugin admin tabs">
        @foreach ($tabs as $tab)
            @php $active = $current === $tab['key']; @endphp
            <a
                href="{{ route($tab['route']) }}"
                @class([
                    'group relative inline-flex flex-col gap-0.5 px-4 py-3 text-sm font-medium transition border-b-2',
                    'border-orange-600 text-slate-900 dark:border-orange-400 dark:text-slate-100' => $active,
                    'border-transparent text-slate-500 hover:text-slate-800 hover:border-slate-300 dark:hover:text-slate-200 dark:hover:border-slate-700' => ! $active,
                ])
                @if ($active) aria-current="page" @endif
            >
                <span>{{ $tab['label'] }}</span>
                <span @class([
                    'text-[11px] font-normal',
                    'text-slate-500 dark:text-slate-400' => $active,
                    'text-slate-400 dark:text-slate-500 group-hover:text-slate-500' => ! $active,
                ])>{{ $tab['desc'] }}</span>
            </a>
        @endforeach
    </nav>
</div>
