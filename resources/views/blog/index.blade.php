{{--
    Blog index — host side. `$articles` is the paginator the package controller
    passes in; everything else is ours.

    Design mirrors the marketing pages (content-landing.blade.php vocabulary):
    orange-tinted hero with a glow, featured latest article, card grid, and a
    Content Autopilot CTA. Every utility class here is verified present in the
    compiled bundle (public/build/assets/app-*.css) — uncompiled classes render
    as nothing (see project memory: prebuilt Tailwind bundle).
--}}
<x-marketing.page
    title="Blog — Serfix"
    description="SEO guides, product notes and practical playbooks from the Serfix team."
    :canonical="route('content-ai.index')"
>
    {{-- ── Hero ─────────────────────────────────────────────── --}}
    <section class="relative overflow-hidden border-b border-slate-200 bg-gradient-to-b from-orange-50/70 via-white to-white">
        <div class="pointer-events-none absolute -top-24 start-1/2 h-72 w-[36rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-orange-300 to-amber-200 opacity-40 blur-3xl"></div>
        <div class="mx-auto max-w-4xl px-6 py-16 text-center lg:px-8 lg:py-20">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-orange-200 bg-white/70 px-3.5 py-1 text-xs font-bold uppercase tracking-[0.15em] text-orange-600 shadow-sm backdrop-blur">
                <span class="h-1.5 w-1.5 rounded-full bg-orange-500"></span>The Serfix blog
            </span>
            <h1 class="mx-auto mt-6 max-w-2xl text-balance text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">
                Learn what actually <span class="bg-gradient-to-r from-orange-500 to-orange-600 bg-clip-text text-transparent">moves rankings</span>
            </h1>
            <p class="mx-auto mt-5 max-w-2xl text-balance text-lg leading-8 text-slate-600">
                Guides, product notes and practical playbooks on ranking, links and content — from the team building Serfix.
            </p>
        </div>
    </section>

    <div class="mx-auto max-w-6xl px-6 py-14 lg:px-8 lg:py-16">
        @php
            $items = collect($articles->items());
            $featured = $articles->onFirstPage() ? $items->first() : null;
            $rest = $featured ? $items->slice(1) : $items;
        @endphp

        @if ($items->isEmpty())
            {{-- ── Empty state ─────────────────────────────── --}}
            <div class="mx-auto max-w-xl rounded-3xl border border-slate-200 bg-white p-12 text-center shadow-sm">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-orange-100 text-orange-600">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"/></svg>
                </span>
                <h2 class="mt-5 text-xl font-bold text-slate-900">First articles coming soon</h2>
                <p class="mt-2 text-sm leading-6 text-slate-600">We're writing the first batch of guides right now. Check back shortly.</p>
            </div>
        @else
            {{-- ── Featured (latest) ───────────────────────── --}}
            @if ($featured)
                <a href="{{ $featured->url() }}" class="group block overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-lg hover:shadow-orange-600/5">
                    <div class="grid lg:grid-cols-3">
                        <div class="lg:col-span-2">
                            @if ($image = $featured->featuredImage())
                                <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?? $featured->title }}"
                                     class="aspect-video w-full object-cover" loading="eager">
                            @else
                                <div class="flex aspect-video w-full items-center justify-center bg-gradient-to-br from-orange-500 to-orange-600">
                                    <span class="text-6xl font-extrabold text-white/90">{{ mb_substr($featured->title, 0, 1) }}</span>
                                </div>
                            @endif
                        </div>
                        <div class="flex flex-col justify-center p-8">
                            <span class="inline-flex self-start rounded-full bg-orange-100 px-3 py-1 text-xs font-bold uppercase tracking-wide text-orange-700">Latest</span>
                            <h2 class="mt-4 text-balance text-2xl font-extrabold tracking-tight text-slate-900">
                                {{ $featured->title }}
                            </h2>
                            <p class="mt-3 text-sm leading-6 text-slate-600 line-clamp-2">{{ $featured->summary() }}</p>
                            <div class="mt-5 flex items-center gap-x-2 text-xs font-medium text-slate-500">
                                @if ($featured->published_at)
                                    <time datetime="{{ $featured->published_at->toIso8601String() }}">{{ $featured->published_at->toFormattedDateString() }}</time>
                                    <span aria-hidden="true">·</span>
                                @endif
                                <span>{{ $featured->readingMinutes() }} min read</span>
                            </div>
                            <span class="mt-6 inline-flex items-center gap-1.5 text-sm font-bold text-orange-600">
                                Read the article
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                            </span>
                        </div>
                    </div>
                </a>
            @endif

            {{-- ── Grid ────────────────────────────────────── --}}
            @if ($rest->isNotEmpty())
                <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($rest as $article)
                        <a href="{{ $article->url() }}" class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-lg hover:shadow-orange-600/5">
                            @if ($image = $article->featuredImage())
                                <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?? $article->title }}"
                                     loading="lazy" class="aspect-video w-full object-cover">
                            @else
                                <div class="flex aspect-video w-full items-center justify-center bg-gradient-to-br from-orange-500 to-orange-600">
                                    <span class="text-5xl font-extrabold text-white/90">{{ mb_substr($article->title, 0, 1) }}</span>
                                </div>
                            @endif
                            <div class="flex flex-1 flex-col p-6">
                                <h2 class="text-lg font-bold tracking-tight text-slate-900">{{ $article->title }}</h2>
                                <p class="mt-2 flex-1 text-sm leading-6 text-slate-600 line-clamp-2">{{ $article->summary() }}</p>
                                <div class="mt-4 flex items-center gap-x-2 text-xs font-medium text-slate-500">
                                    @if ($article->published_at)
                                        <time datetime="{{ $article->published_at->toIso8601String() }}">{{ $article->published_at->toFormattedDateString() }}</time>
                                        <span aria-hidden="true">·</span>
                                    @endif
                                    <span>{{ $article->readingMinutes() }} min read</span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif

            <div class="mt-12">{{ $articles->links() }}</div>
        @endif
    </div>

    {{-- ── Content Autopilot CTA ────────────────────────────── --}}
    <section class="border-t border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-4xl px-6 py-16 text-center lg:px-8">
            <h2 class="text-balance text-3xl font-extrabold tracking-tight text-slate-900">
                Want articles like these, <span class="bg-gradient-to-r from-orange-500 to-orange-600 bg-clip-text text-transparent">written for your site</span>?
            </h2>
            <p class="mx-auto mt-4 max-w-2xl text-balance leading-8 text-slate-600">
                Every article on this blog is researched, written, optimized and published by Serfix Content Autopilot — the same product you can put to work on your own website.
            </p>
            <a href="{{ route('content.landing') }}" class="mt-8 inline-flex items-center justify-center gap-1.5 rounded-full bg-gradient-to-r from-orange-500 to-orange-600 px-8 py-4 text-base font-bold text-white shadow-lg shadow-orange-600/30 hover:brightness-110">
                Try Content Autopilot
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
        </div>
    </section>
</x-marketing.page>
