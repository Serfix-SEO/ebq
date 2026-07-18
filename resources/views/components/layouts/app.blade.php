<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" x-data="{ dark: localStorage.getItem('dark') === 'true', sidebarOpen: false }" x-bind:class="{ 'dark': dark }" x-init="$watch('dark', v => localStorage.setItem('dark', v))">
<head>
    <meta charset="utf-8">
    @include('partials.clarity')
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Serfix</title>
    @include('partials.favicon-links')
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
    @if (! app()->environment('testing'))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @endif
</head>
<body class="bg-white text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100 {{ app()->getLocale() === 'ar' ? 'font-arabic' : '' }}">
    @include('partials.locale-picker')
    {{-- Mobile overlay --}}
    <div x-show="sidebarOpen" x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition-opacity duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="sidebarOpen = false" class="fixed inset-0 z-30 bg-slate-900/40 md:hidden" style="display:none"></div>

    <div class="min-h-screen md:flex">
        {{-- Sidebar --}}
        <aside :class="sidebarOpen ? 'translate-x-0' : (document.documentElement.dir === 'rtl' ? 'translate-x-full' : '-translate-x-full')" class="fixed inset-y-0 start-0 z-40 flex w-64 flex-col border-e border-slate-200 bg-white transition-transform duration-200 md:sticky md:top-0 md:h-screen md:translate-x-0 dark:border-slate-800 dark:bg-slate-950">
            <div class="flex h-16 items-center justify-center border-b border-slate-200 px-5 dark:border-slate-800">
                <img src="{{ asset('serfix-logo.png') }}" alt="Serfix" width="90" height="32" class="h-8 w-auto object-contain dark:hidden">
                <img src="{{ asset('serfix-logo-dark.png') }}" alt="Serfix" width="90" height="32" class="hidden h-8 w-auto object-contain dark:block">
            </div>

            @php
                $current = request()->route()?->getName() ?? '';
                $currentWebsiteId = (string) session('current_website_id', '');
                $authUser = auth()->user();
                // Grouped sidebar (2026-07-13): "Pulse" = per-site health/crawl/
                // audit signals, "Orbit" = keyword-orbit tools (research, tracking).
                // Ungrouped items (last group, null label) render with no header,
                // same as before. Item shape unchanged — grouping is presentation
                // only, feature-gating/active-state logic is untouched.
                $navGroups = [
                    [
                        'label' => 'Pulse',
                        'items' => [
                            ['route' => 'dashboard', 'feature' => 'dashboard', 'label' => __('Site Health'), 'icon' => 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25'],
                            ['route' => 'statistics', 'feature' => 'dashboard', 'label' => __('Statistics'), 'icon' => 'M10.5 6a7.5 7.5 0 107.5 7.5h-7.5V6zM13.5 3v7.5H21A7.5 7.5 0 0013.5 3z'],
                            ['route' => 'site-explorer', 'feature' => null, 'label' => __('Explorer'), 'icon' => 'M7.5 14.25v2.25m3-4.5v4.5m3-6.75v6.75m3-9v9M6 20.25h12A2.25 2.25 0 0020.25 18V6A2.25 2.25 0 0018 3.75H6A2.25 2.25 0 003.75 6v12A2.25 2.25 0 006 20.25z'],
                            ['route' => 'backlinks.index', 'feature' => null, 'label' => __('Backlinks'), 'icon' => 'M19.5 4.5l-15 15m0 0h11.25m-11.25 0V8.25'],
                            ['route' => 'competitors.index', 'feature' => null, 'label' => __('Competitors'), 'icon' => 'M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 007.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 002.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 012.916.52 6.003 6.003 0 01-5.395 4.972m0 0a6.726 6.726 0 01-2.749 1.35m0 0a6.772 6.772 0 01-3.044 0'],
                            ['route' => 'pagespeed.index', 'feature' => 'audits', 'label' => __('Page Speed'), 'icon' => 'M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z'],
                            ['route' => 'pages.index', 'feature' => 'pages', 'label' => __('Site Links'), 'icon' => 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z'],
                            ['route' => 'custom-audit.index', 'feature' => 'audits', 'label' => __('Page Audit'), 'icon' => 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z'],
                            ['route' => 'link-structure.index', 'feature' => 'link_structure', 'label' => __('Link Graph'), 'icon' => 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244'],
                        ],
                    ],
                    [
                        // Content Autopilot — env-gated group; empty items ->
                        // the group-filter step below drops it entirely when
                        // the UI flag is off (unregistered routes would 500).
                        'label' => 'Content',
                        'items' => \Illuminate\Support\Facades\Route::has('content.index') ? [
                            ['route' => 'content.index', 'feature' => 'content', 'label' => __('Content Calendar'), 'badge' => __('New'), 'icon' => 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5'],
                            ['route' => 'content.settings', 'feature' => 'content', 'label' => __('Settings'), 'icon' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z'],
                        ] : [],
                    ],
                    [
                        'label' => 'Orbit',
                        'items' => [
                            ['route' => 'keywords.index', 'feature' => 'keywords', 'label' => __('Keywords'), 'icon' => 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z'],
                            ['route' => 'keyword-research.index', 'feature' => 'keywords', 'label' => __('Keyword Research'), 'icon' => 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z'],
                            ['route' => 'keyword-gap.index', 'feature' => 'keywords', 'label' => __('Competitor Gap'), 'icon' => 'M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5'],
                            ['route' => 'competitor-discovery.index', 'feature' => 'keywords', 'label' => __('Competitor Discovery'), 'icon' => 'M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247'],
                            ['route' => 'rank-tracking.index', 'feature' => 'rank_tracking', 'label' => __('Ranking'), 'icon' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z'],
                        ],
                    ],
                    [
                        'label' => null,
                        'items' => [
                            ['route' => 'sitemaps.index', 'feature' => 'sitemaps', 'label' => __('Sitemaps'), 'icon' => 'M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z'],
                            ['route' => 'reports.index', 'feature' => 'reports', 'label' => __('Reports'), 'icon' => 'M3 3v1.5M3 21v-6m0 0 2.77-.693a9 9 0 0 1 6.208.682l.108.054a9 9 0 0 0 6.086.71l3.114-.732a48.524 48.524 0 0 1-.005-10.499l-3.11.732a9 9 0 0 1-6.085-.711l-.108-.054a9 9 0 0 0-6.208-.682L3 4.5M3 15V4.5'],
                            ['route' => 'ai-studio.index', 'feature' => 'ai_studio', 'label' => __('AI Studio'), 'badge' => __('Beta'), 'icon' => 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567 16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z'],
                            ['route' => 'websites.index', 'feature' => null, 'label' => __('Websites'), 'icon' => 'M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418'],
                            ['route' => 'team.index', 'feature' => 'team', 'label' => __('Team'), 'icon' => 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.433-2.554M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z'],
                            ['route' => 'settings.index', 'feature' => 'settings', 'label' => __('Settings'), 'icon' => 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z'],
                            // Subscription / billing — top-level since plan management
                            // is a per-user global concern (not per-website). Heroicon
                            // credit-card outline matches the existing icon language.
                            ['route' => 'billing.show', 'feature' => null, 'label' => __('Billing'), 'icon' => 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z'],
                        ],
                    ],
                ];
                // The three plugin-management pages (releases, adoption,
                // feature flags) are unified behind a single "WordPress
                // Plugin" entry. The default tab is Releases — the most
                // operational view; admins land there and tab-nav at the
                // top of the page lets them jump to the others.
                //
                // `match_routes` lets the active-state logic highlight the
                // sidebar entry whenever any of the unified routes is the
                // current page — a single nav item, three landing pages.
                // Plans is a global SaaS concern (drives marketing
                // /pricing, the WP plugin wizard, and Stripe checkout).
                // Top-level entry, not folded into the WordPress Plugin
                // master page even though the plugin consumes them.
                //
                // `match_routes` lets the active-state logic highlight the
                // sidebar entry whenever any of the unified routes is the
                // current page — a single nav item, three landing pages.
                $adminItems = [
                    ['route' => 'admin.clients.index', 'label' => 'Clients'],
                    ['route' => 'admin.activities.index', 'label' => 'Activities'],
                    [
                        'route' => 'admin.ops.index',
                        'label' => 'Ops',
                        'match_routes' => ['admin.ops.'],
                    ],
                    [
                        'route' => 'admin.crawler.index',
                        'label' => 'Crawler',
                        'match_routes' => ['admin.crawler.'],
                    ],
                    [
                        'route' => 'admin.link-graph.index',
                        'label' => 'Link Graph',
                        'match_routes' => ['admin.link-graph.'],
                    ],
                    [
                        'route' => 'admin.domain-metrics.index',
                        'label' => 'Domains',
                        'match_routes' => ['admin.domain-metrics.'],
                    ],
                    [
                        'route' => 'admin.backlink-explorer.index',
                        'label' => 'Backlinks',
                        'match_routes' => ['admin.backlink-explorer.'],
                    ],
                    [
                        // Unified compute + data fleet page (crawl workers + DB shards as tabs).
                        'route' => 'admin.fleet.index',
                        'label' => 'Fleet',
                        'match_routes' => ['admin.fleet.', 'admin.db-fleet.'],
                    ],
                    [
                        'route' => 'admin.marketing.index',
                        'label' => 'Marketing',
                        'match_routes' => ['admin.marketing.'],
                    ],
                    ['route' => 'admin.leads.index', 'label' => 'Leads'],
                    ['route' => 'admin.bug-reports.index', 'label' => 'Bug Reports'],
                    ['route' => 'admin.usage.index', 'label' => 'API Usage'],
                    ['route' => 'admin.site-explorer-usage.index', 'label' => 'Site Explorer Usage'],
                    ['route' => 'admin.proxies.index', 'label' => 'Proxies'],
                    [
                        'route' => 'admin.settings',
                        'label' => __('Settings'),
                        'match_routes' => ['admin.settings'],
                    ],
                    [
                        'route' => 'admin.plugin-releases.index',
                        'label' => 'WordPress Plugin',
                        'match_routes' => [
                            'admin.plugin-releases.',
                            'admin.plugin-adoption.',
                            'admin.website-features.',
                            'admin.billing.',
                        ],
                    ],
                    [
                        'route' => 'admin.plans.index',
                        'label' => 'Plans',
                        'match_routes' => ['admin.plans.'],
                    ],
                    [
                        'route' => 'admin.keyword-servers.index',
                        'label' => 'Keyword Servers',
                        'match_routes' => ['admin.keyword-servers.'],
                    ],
                    [
                        'route' => 'admin.commands.index',
                        'label' => 'Commands',
                        'match_routes' => ['admin.commands.'],
                    ],
                    [
                        'route' => 'admin.docs.crawler',
                        'label' => 'Site Crawler Docs',
                        'match_routes' => ['admin.docs.'],
                    ],
                ];
            @endphp
            {{-- One pass: filter + decorate every group's items once, reused
                 by both the inline/button rendering below AND the flyout's
                 JSON item map — a single source of truth so the two never
                 drift (two independently-filtered loops previously caused
                 items to bleed between groups' teleported panels). --}}
            @php
                $filteredGroups = collect($navGroups)->map(function ($group) use ($authUser, $currentWebsiteId, $current) {
                    $items = collect($group['items'])->filter(function ($item) use ($authUser, $currentWebsiteId) {
                        if (! empty($item['admin_only']) && (! $authUser || ! $authUser->is_admin)) {
                            return false;
                        }
                        if ($authUser && $item['feature'] !== null && $currentWebsiteId !== '') {
                            return $authUser->hasFeatureAccess($item['feature'], $currentWebsiteId);
                        }
                        return true;
                    })->map(function ($item) use ($current) {
                        $item['href'] = route($item['route']);
                        $item['active'] = str_starts_with($current, explode('.', $item['route'])[0]);
                        return $item;
                    })->values();
                    return ['label' => $group['label'], 'items' => $items];
                })->filter(fn ($g) => $g['items']->isNotEmpty())->values();

                $flyoutGroups = $filteredGroups->filter(fn ($g) => $g['label'])->mapWithKeys(fn ($g) => [
                    $g['label'] => $g['items']->map(fn ($i) => [
                        'href' => $i['href'],
                        'label' => $i['label'],
                        'icon' => $i['icon'],
                        'active' => $i['active'],
                        'badge' => $i['badge'] ?? null,
                    ])->values()->all(),
                ]);
            @endphp
            <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4"
                x-data="{ openGroup: null, flyoutTop: 0, flyoutLeft: 0, groups: {{ Illuminate\Support\Js::from($flyoutGroups) }} }"
                @keydown.escape.window="openGroup = null">
                @foreach ($filteredGroups as $group)
                    @if ($group['label'])
                        @php
                            $groupKey = "'".addslashes($group['label'])."'";
                            $groupActive = $group['items']->contains('active', true);
                        @endphp
                        {{-- Grouped section: click expands a flyout submenu to the
                             side (not inline below) — x-teleport moves the shared
                             panel to <body> so it escapes this <nav>'s
                             overflow-y-auto clipping. Position is computed from
                             the button's own rect at click time, RTL-aware. --}}
                        <div class="relative" @class(['mt-4' => ! $loop->first])>
                            <button type="button" data-flyout-toggle
                                @click="
                                    if (openGroup === {{ $groupKey }}) { openGroup = null; return; }
                                    const r = $el.getBoundingClientRect();
                                    const rtl = document.documentElement.dir === 'rtl';
                                    flyoutTop = Math.min(r.top, window.innerHeight - 260);
                                    flyoutLeft = rtl ? (r.left - 232) : (r.right + 8);
                                    openGroup = {{ $groupKey }};
                                "
                                :aria-expanded="openGroup === {{ $groupKey }}"
                                @class([
                                    'flex w-full items-center justify-between rounded-lg px-3 py-2 text-[10px] font-semibold uppercase tracking-[0.18em] transition',
                                    'text-slate-500 dark:text-slate-300' => $groupActive,
                                    'text-slate-400 hover:text-slate-600 dark:hover:text-slate-300' => ! $groupActive,
                                ])
                                :class="{ 'bg-slate-100 dark:bg-slate-800': openGroup === {{ $groupKey }} }">
                                <span class="flex items-center gap-1.5">
                                    {{ $group['label'] }}
                                    @if ($groupActive)
                                        <span aria-hidden="true" class="h-1 w-1 rounded-full bg-orange-600 dark:bg-orange-400"></span>
                                    @endif
                                </span>
                                <svg class="h-3 w-3 flex-shrink-0 rtl:-scale-x-100" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </button>
                        </div>
                    @else
                        @foreach ($group['items'] as $item)
                            <a href="{{ $item['href'] }}"
                                @class([
                                    'group relative flex items-center gap-3 rounded-lg px-3 py-2 text-[13px] font-medium transition',
                                    'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-100' => $item['active'],
                                    'text-slate-600 hover:bg-slate-50 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-200' => !$item['active'],
                                ])>
                                @if ($item['active'])
                                    <span aria-hidden="true" class="absolute start-0 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-e-full bg-orange-600 dark:bg-orange-400"></span>
                                @endif
                                <svg @class(['h-[17px] w-[17px] flex-shrink-0', 'text-slate-900 dark:text-slate-100' => $item['active'], 'text-slate-400 group-hover:text-slate-600 dark:text-slate-500 dark:group-hover:text-slate-300' => !$item['active']]) xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" /></svg>
                                {{ $item['label'] }}
                                @if (! empty($item['badge']))
                                    <span class="ms-auto rounded-full bg-orange-100 px-1.5 py-px text-[9px] font-bold uppercase tracking-wider text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">{{ $item['badge'] }}</span>
                                @endif
                            </a>
                        @endforeach
                    @endif
                @endforeach

                <template x-teleport="body">
                    <div x-show="openGroup" x-cloak
                        @click.outside="if (! $event.target.closest('[data-flyout-toggle]')) openGroup = null"
                        x-transition.opacity.duration.100ms
                        :style="`top: ${flyoutTop}px; left: ${flyoutLeft}px;`"
                        class="fixed z-50 w-56 rounded-lg border border-slate-200 bg-white p-1.5 shadow-lg dark:border-slate-700 dark:bg-slate-900"
                        style="display:none" role="menu">
                        <p class="px-2.5 pb-1.5 pt-1 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400" x-text="openGroup"></p>
                        <template x-for="(item, idx) in (groups[openGroup] || [])" :key="idx">
                            <a :href="item.href" @click="openGroup = null" role="menuitem"
                                class="group relative flex items-center gap-3 rounded-lg px-2.5 py-2 text-[13px] font-medium transition"
                                :class="item.active ? 'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-100' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800/60 dark:hover:text-slate-200'">
                                <svg class="h-[17px] w-[17px] flex-shrink-0" :class="item.active ? 'text-slate-900 dark:text-slate-100' : 'text-slate-400 group-hover:text-slate-600 dark:text-slate-500 dark:group-hover:text-slate-300'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" /></svg>
                                <span x-text="item.label"></span>
                                <template x-if="item.badge">
                                    <span class="ms-auto rounded-full bg-orange-100 px-1.5 py-px text-[9px] font-bold uppercase tracking-wider text-orange-700 dark:bg-orange-500/15 dark:text-orange-300" x-text="item.badge"></span>
                                </template>
                            </a>
                        </template>
                    </div>
                </template>

                @if ($authUser?->is_admin)
                    <div class="px-3 pb-2 pt-5 text-[10px] font-semibold uppercase tracking-[0.18em] text-slate-400">Admin</div>
                    @foreach ($adminItems as $item)
                        @php
                            // Active when $current matches this item's
                            // primary route prefix or any of its
                            // `match_routes` (the WordPress Plugin entry
                            // covers releases / adoption / website-features).
                            $prefixes = $item['match_routes'] ?? [];
                            // Two-segment names (admin.settings) must NOT
                            // degrade to the bare 'admin.' prefix — that
                            // matched every admin page and kept the item
                            // permanently highlighted.
                            $base = substr($item['route'], 0, (int) strrpos($item['route'], '.'));
                            $prefixes[] = $base === 'admin' ? $item['route'] : $base.'.';
                            $active = false;
                            foreach ($prefixes as $prefix) {
                                if (str_starts_with($current, $prefix)) {
                                    $active = true;
                                    break;
                                }
                            }
                        @endphp
                        <a href="{{ route($item['route']) }}"
                           @class([
                               'group relative flex items-center gap-3 rounded-lg px-3 py-2 text-[13px] font-medium transition',
                               'bg-slate-100 text-slate-900 dark:bg-slate-800 dark:text-slate-100' => $active,
                               'text-slate-600 hover:bg-slate-50 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-200' => !$active,
                           ])>
                            @if ($active)
                                <span aria-hidden="true" class="absolute start-0 top-1/2 h-5 w-0.5 -translate-y-1/2 rounded-e-full bg-orange-600 dark:bg-orange-400"></span>
                            @endif
                            {{ $item['label'] }}
                        </a>
                    @endforeach
                @endif
            </nav>

            <div class="border-t border-slate-200 px-3 py-3 dark:border-slate-800">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="group flex w-full items-center gap-3 rounded-lg px-3 py-2 text-[13px] font-medium text-slate-500 transition hover:bg-slate-50 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-900 dark:hover:text-slate-100">
                        <svg class="h-[17px] w-[17px] text-slate-400 group-hover:text-slate-600 dark:group-hover:text-slate-300" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg>
                        {{ __('Log out') }}
                    </button>
                </form>
            </div>
        </aside>

        {{-- Main content --}}
        <div class="flex min-w-0 flex-1 flex-col bg-slate-50 dark:bg-slate-900">
            {{-- Top bar --}}
            <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-slate-200 bg-white/80 px-4 backdrop-blur-xl md:px-6 dark:border-slate-800 dark:bg-slate-950/80">
                <div class="flex items-center gap-3">
                    <button @click="sidebarOpen = !sidebarOpen" class="rounded-md p-2 text-slate-500 transition hover:bg-slate-100 md:hidden dark:hover:bg-slate-800">
                        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                    </button>
                    <livewire:website-selector />
                </div>
                <div class="flex items-center gap-1.5">
                    @auth
                        <button type="button"
                            @click="window.dispatchEvent(new CustomEvent('open-bug-report', { detail: { url: window.location.href } }))"
                            class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                            title="{{ __('Report a bug') }}" aria-label="{{ __('Report a bug') }}">
                            <svg class="h-[18px] w-[18px]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 12.75c1.148 0 2.278.08 3.383.237 1.037.146 1.866.966 1.866 2.013 0 3.728-2.35 6.75-5.25 6.75S6.75 18.728 6.75 15c0-1.046.83-1.867 1.866-2.013A24.204 24.204 0 0112 12.75zm0 0c2.883 0 5.647.508 8.207 1.44a23.91 23.91 0 01-1.152 6.06M12 12.75c-2.883 0-5.647.508-8.208 1.44.125 2.104.52 4.136 1.153 6.06M12 12.75a2.25 2.25 0 002.248-2.354M12 12.75a2.25 2.25 0 01-2.248-2.354M12 8.25c.995 0 1.971-.08 2.922-.236.403-.066.74-.358.795-.762a3.778 3.778 0 00-.399-2.25M12 8.25c-.995 0-1.97-.08-2.922-.236-.402-.066-.74-.358-.795-.762a3.734 3.734 0 01.4-2.253M12 8.25a2.25 2.25 0 00-2.248 2.146M12 8.25a2.25 2.25 0 012.248 2.146M8.683 5a6.032 6.032 0 01-1.155-1.002c.07-.63.27-1.222.574-1.747m.581 2.749A3.75 3.75 0 0115.318 5m0 0c.427-.283.815-.62 1.155-.999a4.471 4.471 0 00-.575-1.752M4.921 6a24.048 24.048 0 00-.392 3.314c1.668.546 3.416.914 5.223 1.082M19.08 6c.205 1.08.337 2.187.392 3.314a23.882 23.882 0 01-5.223 1.082" /></svg>
                            <span class="hidden whitespace-nowrap sm:inline">{{ __('Report a bug') }}</span>
                        </button>
                    @endauth
                    <button @click="dark = !dark" class="rounded-md p-2 text-slate-500 transition hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800" title="Toggle dark mode">
                        <svg x-show="!dark" class="h-[18px] w-[18px]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>
                        <svg x-show="dark" class="h-[18px] w-[18px]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="display:none"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>
                    </button>
                    {{-- User menu --}}
                    <div class="relative ms-1" x-data="{ userMenu: false }" @keydown.escape.window="userMenu = false" @click.outside="userMenu = false">
                        <button type="button" @click="userMenu = !userMenu"
                            class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700 ring-1 ring-slate-200 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700"
                            :aria-expanded="userMenu" aria-haspopup="true" :title="'{{ addslashes(auth()->user()->name ?? '') }}'">
                            {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
                        </button>
                        <div x-show="userMenu" x-cloak x-transition.opacity.duration.100ms
                            class="absolute end-0 z-50 mt-2 w-60 origin-top-right rtl:origin-top-left rounded-lg border border-slate-200 bg-white py-1 shadow-lg ring-1 ring-black/5 dark:border-slate-700 dark:bg-slate-900 dark:ring-white/10"
                            role="menu" style="display:none">
                            <div class="border-b border-slate-100 px-3 py-2.5 dark:border-slate-800">
                                <p class="truncate text-[13px] font-semibold text-slate-800 dark:text-slate-100">{{ auth()->user()->name }}</p>
                                <p class="truncate text-[11px] text-slate-500 dark:text-slate-400">{{ auth()->user()->email }}</p>
                            </div>
                            @if (\App\Support\LocaleConfig::active())
                                <div class="flex items-center gap-1 border-b border-slate-100 px-3 py-2 dark:border-slate-800">
                                    <span class="text-[11px] text-slate-400">{{ __('Language') }}</span>
                                    <a href="{{ route('locale.set', 'en') }}" @class(['ms-auto rounded px-1.5 py-0.5 text-[11px] font-semibold', 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400' => app()->getLocale() === 'en', 'text-slate-500 hover:text-slate-700 dark:text-slate-400' => app()->getLocale() !== 'en'])>EN</a>
                                    <a href="{{ route('locale.set', 'ar') }}" @class(['rounded px-1.5 py-0.5 text-[11px] font-semibold', 'bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-400' => app()->getLocale() === 'ar', 'text-slate-500 hover:text-slate-700 dark:text-slate-400' => app()->getLocale() !== 'ar'])>AR</a>
                                </div>
                            @endif
                            <a href="{{ route('settings.index') }}" role="menuitem"
                                class="flex items-center gap-2.5 px-3 py-2 text-[13px] text-slate-700 transition hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                                <svg class="h-4 w-4 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                Profile &amp; settings
                            </a>
                            <a href="{{ route('billing.show') }}" role="menuitem"
                                class="flex items-center gap-2.5 px-3 py-2 text-[13px] text-slate-700 transition hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-slate-800">
                                <svg class="h-4 w-4 text-slate-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z" /></svg>
                                Billing
                            </a>
                            <div class="my-1 border-t border-slate-100 dark:border-slate-800"></div>
                            <form method="POST" action="{{ route('logout') }}" class="px-1">
                                @csrf
                                <button type="submit" role="menuitem"
                                    class="flex w-full items-center gap-2.5 rounded-md px-2 py-2 text-[13px] text-red-600 transition hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/30">
                                    <svg class="h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" /></svg>
                                    {{ __('Log out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            <main class="min-w-0 flex-1 overflow-x-hidden p-4 md:p-8">
                @if (session('impersonation_notice'))
                    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ session('impersonation_notice') }}</div>
                @endif
                @if (session('session_notice'))
                    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">{{ session('session_notice') }}</div>
                @endif
                @if (session()->has('impersonator_id'))
                    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        You are impersonating another client account.
                        @if (auth()->user() && \App\Support\TrialStatus::isLockedOut(auth()->user()))
                            <span class="font-semibold">This client's trial has expired — on their own login they only see the Billing page (impersonation bypasses that lockout).</span>
                        @endif
                        <form method="POST" action="{{ route('admin.impersonation.stop') }}" class="inline-block">
                            @csrf
                            <button type="submit" class="ms-2 font-semibold underline">Return to admin</button>
                        </form>
                    </div>
                @endif
                @include('partials.winback-banner')
                @include('partials.quota-banner')
                @include('partials.connect-source-banner')
                {{ $slot }}
                @auth
                    <livewire:connect-sources-modal />
                    <livewire:bug-report-modal />
                @endauth
            </main>
        </div>
    </div>

    {{-- Global Livewire error handler: catch 419 (CSRF/session expired) and 503
         (server overloaded) instead of crashing the page silently or showing
         a raw error modal. On 419 we reload so the browser gets a fresh token.
         On 503 we show a dismissible banner and retry after 10s. --}}
    <div id="ebq-error-banner" style="display:none"
         class="fixed bottom-4 left-1/2 z-[9999] -translate-x-1/2 w-full max-w-md px-4">
        <div class="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 shadow-lg dark:border-amber-500/30 dark:bg-amber-950/80">
            <p id="ebq-error-msg" class="text-sm font-medium text-amber-900 dark:text-amber-200"></p>
            <button onclick="document.getElementById('ebq-error-banner').style.display='none'"
                    class="shrink-0 text-amber-600 hover:text-amber-800 dark:text-amber-400 text-lg leading-none">&times;</button>
        </div>
    </div>

    @auth
    <script>
    document.addEventListener('livewire:init', () => {
        Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status === 419) {
                    preventDefault();
                    // Session expired — reload to get a fresh CSRF token.
                    const b = document.getElementById('ebq-error-banner');
                    const m = document.getElementById('ebq-error-msg');
                    if (b && m) {
                        m.textContent = 'Your session expired. Reloading…';
                        b.style.display = 'block';
                    }
                    setTimeout(() => window.location.reload(), 1500);
                } else if (status === 503) {
                    preventDefault();
                    const b = document.getElementById('ebq-error-banner');
                    const m = document.getElementById('ebq-error-msg');
                    if (b && m) {
                        m.textContent = 'Server is busy — retrying in 10 seconds…';
                        b.style.display = 'block';
                        setTimeout(() => window.location.reload(), 10000);
                    }
                }
            });
        });
    });
    </script>
    @endauth
</body>
</html>
