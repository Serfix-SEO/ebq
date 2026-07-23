{{--
    Blog article page — the HOST side of serfix/content-ai-laravel.

    This is exactly what a customer writes: their own layout, their own classes,
    with the package's public API dropped in. Nothing in the package is patched.

    The package controller hands us `$article` and `$meta`; `$serfix_body` and
    `$serfix_body_below` are the globals it shares with every view. We do NOT
    use `$serfix_head` here, because <x-marketing.page> already owns the <head>
    and renders title/description/canonical/OG itself — feeding it the values
    from $meta avoids two of each tag. The JSON-LD goes in the layout's `schema`
    slot, which is what that slot is for.

    Class vocabulary matches the marketing pages — every utility here is
    verified in the compiled bundle (prebuilt Tailwind, no JIT at runtime).
--}}
<x-marketing.page
    :title="$meta['title']"
    :description="$meta['description']"
    :canonical="$meta['canonical']"
    :robots="$meta['robots']"
    :ogImage="$article->featuredImage()?->url()"
    ogType="article"
    :publishedTime="$article->published_at?->toIso8601String()"
    :modifiedTime="$article->updated_at?->toIso8601String()"
>
    <x-slot:schema>
        <x-content-ai::schema :article="$article" />
    </x-slot:schema>

    {{-- ── Article header ───────────────────────────────────── --}}
    <header class="relative overflow-hidden border-b border-slate-200 bg-gradient-to-b from-orange-50/70 via-white to-white">
        <div class="pointer-events-none absolute -top-24 start-1/2 h-72 w-[36rem] -translate-x-1/2 rounded-full bg-gradient-to-r from-orange-300 to-amber-200 opacity-40 blur-3xl"></div>
        <div class="mx-auto max-w-4xl px-6 py-12 lg:px-8 lg:py-16">
            <nav class="flex items-center gap-1.5 text-sm font-medium text-slate-500" aria-label="Breadcrumb">
                <a href="{{ route('landing') }}" class="hover:text-orange-600">Home</a>
                <svg class="h-3.5 w-3.5 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                <a href="{{ route('content-ai.index') }}" class="hover:text-orange-600">Blog</a>
            </nav>

            <h1 class="mt-6 text-balance text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl">
                {{ $article->h1 ?: $article->title }}
            </h1>

            <div class="mt-5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm font-medium text-slate-500">
                <span class="inline-flex items-center gap-1.5">
                    <span class="flex h-7 w-7 items-center justify-center rounded-full bg-gradient-to-br from-orange-500 to-orange-600 text-xs font-extrabold text-white">S</span>
                    Serfix Team
                </span>
                @if ($article->published_at)
                    <span aria-hidden="true">·</span>
                    <time datetime="{{ $article->published_at->toIso8601String() }}">
                        {{ $article->published_at->toFormattedDateString() }}
                    </time>
                @endif
                <span aria-hidden="true">·</span>
                <span>{{ $article->readingMinutes() }} min read</span>
            </div>

            @unless ($article->isPublished())
                <p class="mt-5 rounded-xl bg-orange-50 px-4 py-3 text-sm font-medium text-orange-800 ring-1 ring-orange-200">
                    Preview — this article is not published yet.
                </p>
            @endunless
        </div>
    </header>

    <article class="mx-auto max-w-3xl px-6 pt-10 lg:px-8">
        {{-- Featured image — only when the article body doesn't already open
             with it (the pipeline usually injects it inline; rendering both
             stacked the same image twice). --}}
        @php
            $image = $article->featuredImage();
            $imageInBody = $image && str_contains((string) $article->html, basename(parse_url($image->url(), PHP_URL_PATH) ?: ''));
        @endphp
        @if ($image && ! $imageInBody)
            <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?? $article->title }}"
                 class="w-full rounded-2xl border border-slate-200 shadow-sm" loading="eager">
        @endif

        {{-- The article itself. Typography comes from OUR stylesheet, not the
             package: it ships unstyled precisely so this is our decision. --}}
        <div class="prose prose-slate mt-10 max-w-none prose-headings:scroll-mt-24 prose-headings:tracking-tight prose-a:text-orange-600 prose-img:rounded-xl">
            {!! $serfix_body !!}
        </div>
    </article>

    {{-- ── Content Autopilot CTA ────────────────────────────── --}}
    <div class="mx-auto max-w-3xl px-6 pt-10 lg:px-8">
        <div class="relative overflow-hidden rounded-3xl bg-gradient-to-r from-orange-500 to-orange-600 p-8 text-center shadow-lg shadow-orange-600/25">
            <div class="pointer-events-none absolute -top-16 start-1/2 h-40 w-72 -translate-x-1/2 rounded-full bg-white/10 blur-3xl"></div>
            <h2 class="text-balance text-2xl font-extrabold tracking-tight text-white">
                This article was written on autopilot
            </h2>
            <p class="mx-auto mt-3 max-w-xl text-balance text-sm leading-6 text-orange-100">
                Serfix researched the topic, wrote it, optimized it and published it here automatically. Put the same engine to work on your own website.
            </p>
            <a href="{{ route('content.landing') }}"
               class="mt-6 inline-flex items-center justify-center gap-1.5 rounded-full bg-white px-7 py-3 text-sm font-bold text-orange-600 shadow-sm hover:bg-orange-50">
                Try Content Autopilot
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
        </div>
    </div>

    {{-- Related articles. Styled by publishing the package's partial into
         resources/views/vendor/content-ai/, again without touching the package. --}}
    <div class="mx-auto max-w-3xl px-6 pb-20 pt-6 lg:px-8">
        {!! $serfix_body_below !!}
    </div>
</x-marketing.page>
