{{--
    Blog index — host side. `$articles` is the paginator the package controller
    passes in; everything else is ours.
--}}
<x-marketing.page
    title="Blog — Serfix"
    description="SEO guides, product notes and practical playbooks from the Serfix team."
    :canonical="route('content-ai.index')"
>
    <div class="mx-auto max-w-5xl px-6 py-16 lg:px-8 lg:py-20">
        <header class="max-w-2xl">
            <h1 class="text-balance text-4xl font-semibold tracking-tight text-slate-900 sm:text-5xl">
                The Serfix blog
            </h1>
            <p class="mt-5 text-balance text-[17px] leading-8 text-slate-600">
                Guides, product notes and practical playbooks on ranking, links and content.
            </p>
        </header>

        <div class="mt-12 grid gap-8 sm:grid-cols-2">
            @forelse ($articles as $article)
                <article class="flex flex-col rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200 transition hover:shadow-md">
                    @if ($image = $article->featuredImage())
                        <a href="{{ $article->url() }}" class="mb-5 block overflow-hidden rounded-xl">
                            <img src="{{ $image->url() }}" alt="{{ $image->alt_text ?? $article->title }}"
                                 loading="lazy" class="aspect-[16/9] w-full object-cover">
                        </a>
                    @endif

                    <h2 class="text-lg font-semibold tracking-tight text-slate-900">
                        <a href="{{ $article->url() }}" class="hover:text-orange-600">{{ $article->title }}</a>
                    </h2>

                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">{{ $article->summary() }}</p>

                    <div class="mt-4 flex items-center gap-x-2 text-xs text-slate-500">
                        @if ($article->published_at)
                            <time datetime="{{ $article->published_at->toIso8601String() }}">
                                {{ $article->published_at->toFormattedDateString() }}
                            </time>
                            <span aria-hidden="true">·</span>
                        @endif
                        <span>{{ $article->readingMinutes() }} min read</span>
                    </div>
                </article>
            @empty
                <p class="text-slate-600">No articles published yet.</p>
            @endforelse
        </div>

        <div class="mt-12">{{ $articles->links() }}</div>
    </div>
</x-marketing.page>
