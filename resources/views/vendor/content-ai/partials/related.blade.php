{{--
    Published override of the package's related-articles partial (it ships
    unstyled). Rendered by {!! $serfix_body_below !!} — Laravel resolves this
    copy ahead of the vendor one, so the package itself stays untouched.
--}}
@if ($related->isNotEmpty())
    <aside class="border-t border-slate-200 pt-10">
        <h2 class="text-lg font-semibold tracking-tight text-slate-900">Keep reading</h2>

        <ul class="mt-5 grid gap-4 sm:grid-cols-2">
            @foreach ($related as $item)
                <li class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:shadow-md">
                    <a href="{{ $item->url() }}" class="text-sm font-semibold text-slate-900 hover:text-orange-600">
                        {{ $item->title }}
                    </a>
                    <p class="mt-2 text-xs text-slate-500">{{ $item->readingMinutes() }} min read</p>
                </li>
            @endforeach
        </ul>
    </aside>
@endif
