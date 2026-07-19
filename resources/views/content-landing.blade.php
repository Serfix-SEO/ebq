@php
    $cfg = \App\Support\ContentAutopilotConfig::class;
@endphp
<x-marketing.page
    title="Content Autopilot — SEO articles written & published for you — Serfix"
    description="Serfix Content Autopilot researches, writes, optimizes, illustrates and publishes an expert SEO article for your site — on autopilot. 5-day free trial, no card."
    active="features"
>
    {{-- ── Hero ──────────────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-6 py-20 text-center lg:px-8 lg:py-24">
            <p class="text-xs font-semibold uppercase tracking-[0.2em] text-orange-600">{{ __('Content Autopilot') }}</p>
            <h1 class="mx-auto mt-4 max-w-3xl text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                {{ __('Expert SEO articles, written and published for you') }}
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-balance text-[17px] leading-8 text-slate-600">
                {{ __('Serfix researches your niche, writes a genuinely useful article, checks it against technical SEO, adds images, and publishes it to your site — on autopilot. You review and approve everything.') }}
            </p>
            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('Start :days-day free trial', ['days' => $cfg::trialDays()]) }}
                </a>
                <span class="text-sm text-slate-500">{{ __(':n free articles · no card required', ['n' => $cfg::trialArticles()]) }}</span>
            </div>
        </div>
    </section>

    {{-- ── How it works ──────────────────────────────────────── --}}
    <section class="border-b border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-6xl px-6 py-16 lg:px-8">
            <h2 class="text-center text-2xl font-semibold tracking-tight text-slate-900">{{ __('How it works') }}</h2>
            <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    [__('Understand your site'), __('We read your website and audience to learn what you sell and who you serve.')],
                    [__('Find the topics'), __('Real questions and keywords your customers already search for become your content calendar.')],
                    [__('Write & optimize'), __('Each article is written to be useful, then checked against technical SEO and made to read naturally.')],
                    [__('Illustrate & publish'), __('Images are generated and the finished article is published to your site on your schedule.')],
                ] as $i => $step)
                    <div class="rounded-2xl border border-slate-200 bg-white p-6">
                        <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-orange-100 text-sm font-bold text-orange-700">{{ $i + 1 }}</div>
                        <h3 class="mt-4 text-base font-semibold text-slate-900">{{ $step[0] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $step[1] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ── Pricing ───────────────────────────────────────────── --}}
    <section class="bg-white">
        <div class="mx-auto max-w-4xl px-6 py-16 lg:px-8">
            <h2 class="text-center text-2xl font-semibold tracking-tight text-slate-900">{{ __('Simple pricing') }}</h2>
            <p class="mt-2 text-center text-sm text-slate-500">{{ __('Start free for :days days. Then, for one website:', ['days' => $cfg::trialDays()]) }}</p>
            <div class="mt-10 grid gap-6 sm:grid-cols-2">
                <div class="rounded-2xl border-2 border-orange-300 bg-orange-50/40 p-8 text-center">
                    <div class="inline-flex rounded-full bg-orange-600 px-3 py-0.5 text-xs font-bold uppercase tracking-wide text-white">{{ __('$:p first month', ['p' => $cfg::displayPrice('first_month')]) }}</div>
                    <div class="mt-4 text-4xl font-extrabold text-slate-900">${{ $cfg::displayPrice('monthly') }}<span class="text-lg font-medium text-slate-500">/{{ __('mo') }}</span></div>
                    <div class="mt-1 text-sm text-slate-500">{{ __('Billed monthly') }}</div>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center">
                    <div class="inline-flex rounded-full bg-success/15 px-3 py-0.5 text-xs font-bold uppercase tracking-wide text-success">{{ __('Best value') }}</div>
                    <div class="mt-4 text-4xl font-extrabold text-slate-900">${{ $cfg::displayPrice('annual') }}<span class="text-lg font-medium text-slate-500">/{{ __('mo') }}</span></div>
                    <div class="mt-1 text-sm text-slate-500">{{ __('Billed yearly') }}</div>
                </div>
            </div>
            <p class="mt-5 text-center text-sm text-slate-500">
                {{ __('Up to :n articles per website each month. Each additional website: $:m/mo (or $:a/mo billed yearly).', ['n' => $cfg::monthlyArticlesPerWebsite(), 'm' => $cfg::displayPrice('addon_monthly'), 'a' => $cfg::displayPrice('addon_annual')]) }}
            </p>
            <div class="mt-8 text-center">
                <a href="{{ route('register') }}" class="inline-flex items-center justify-center rounded-xl bg-gradient-to-r from-orange-500 to-orange-600 px-7 py-3.5 text-sm font-bold text-white shadow-lg shadow-orange-600/25 hover:brightness-110">
                    {{ __('Get started free') }}
                </a>
            </div>
        </div>
    </section>
</x-marketing.page>
