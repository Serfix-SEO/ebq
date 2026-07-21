@php($layout = config('content-ai.views.layout'))
@extends($layout ?? 'content-ai::layout')

@section('content')
    <h1>{{ config('content-ai.seo.site_name') }} blog</h1>

    @forelse ($articles as $article)
        <article>
            <h2><a href="{{ $article->url() }}">{{ $article->title }}</a></h2>
            <p>{{ $article->summary() }}</p>
            <p>
                @if ($article->published_at)
                    <time datetime="{{ $article->published_at->toIso8601String() }}">
                        {{ $article->published_at->toFormattedDateString() }}
                    </time>
                @endif
                · {{ $article->readingMinutes() }} min read
            </p>
        </article>
    @empty
        <p>No articles yet.</p>
    @endforelse

    {{ $articles->links() }}
@endsection
