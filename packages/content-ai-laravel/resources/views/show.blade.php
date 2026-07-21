{{--
  Deliberately unstyled and layout-agnostic: publish it
  (`php artisan vendor:publish --tag=content-ai-views`) and restyle freely.
  Set content-ai.views.layout to wrap it in your own layout.

  This view uses the same global chunks the package exposes to YOUR templates,
  so what you see here is exactly what you get when you build your own design:

      <head> {!! $serfix_head !!}
      <body> {!! $serfix_body !!}
             {!! $serfix_body_below !!}
--}}
@php($layout = config('content-ai.views.layout'))
@extends($layout ?? 'content-ai::layout')

@section('head')
    {!! $serfix_head !!}
@endsection

@section('content')
    <article>
        <header>
            <h1>{{ $article->h1 ?: $article->title }}</h1>
            <p>
                @if ($article->published_at)
                    <time datetime="{{ $article->published_at->toIso8601String() }}">
                        {{ $article->published_at->toFormattedDateString() }}
                    </time>
                @endif
                · {{ $article->readingMinutes() }} min read
            </p>
            @unless ($article->isPublished())
                <p><strong>Preview — this article is not published.</strong></p>
            @endunless
        </header>

        {{-- Trusted: authored by Content AI, signature-verified in transit, and
             optionally sanitised at import (content.sanitize_html). --}}
        {!! $serfix_body !!}
    </article>

    {!! $serfix_body_below !!}
@endsection
