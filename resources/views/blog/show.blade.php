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
--}}
<x-marketing.page
    :title="$meta['title']"
    :description="$meta['description']"
    :canonical="$meta['canonical']"
    :robots="$meta['robots']"
    :ogImage="$article->featuredImage()?->url()"
>
    <x-slot:schema>
        <x-content-ai::schema :article="$article" />
    </x-slot:schema>

    <article class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
        <nav class="text-sm text-slate-500">
            <a href="{{ route('landing') }}" class="hover:text-slate-700">Home</a>
            <span class="mx-1.5">/</span>
            <a href="{{ route('content-ai.index') }}" class="hover:text-slate-700">Blog</a>
        </nav>

        <header class="mt-4">
            <h1 class="text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                {{ $article->h1 ?: $article->title }}
            </h1>

            <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-slate-500">
                @if ($article->published_at)
                    <time datetime="{{ $article->published_at->toIso8601String() }}">
                        {{ $article->published_at->toFormattedDateString() }}
                    </time>
                    <span aria-hidden="true">·</span>
                @endif
                <span>{{ $article->readingMinutes() }} min read</span>
            </div>

            @unless ($article->isPublished())
                <p class="mt-4 rounded-lg bg-orange-50 px-4 py-3 text-sm font-medium text-orange-800 ring-1 ring-orange-200">
                    Preview — this article is not published yet.
                </p>
            @endunless
        </header>

        {{-- The article itself. Typography comes from OUR stylesheet, not the
             package: it ships unstyled precisely so this is our decision. --}}
        <div class="prose prose-slate mt-10 max-w-none prose-headings:scroll-mt-24 prose-headings:tracking-tight prose-a:text-orange-600 prose-img:rounded-xl">
            {!! $serfix_body !!}
        </div>
    </article>

    {{-- Related articles. Styled by publishing the package's partial into
         resources/views/vendor/content-ai/, again without touching the package. --}}
    <div class="mx-auto max-w-3xl px-6 pb-20 lg:px-8">
        {!! $serfix_body_below !!}
    </div>
</x-marketing.page>
