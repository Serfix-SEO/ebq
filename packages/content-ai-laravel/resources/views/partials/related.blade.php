{{-- Unstyled on purpose — publish the views and restyle to match your design. --}}
<aside class="serfix-related">
    <h2>Related articles</h2>
    <ul>
        @foreach ($related as $item)
            <li>
                <a href="{{ $item->url() }}">{{ $item->title }}</a>
                <span>{{ $item->readingMinutes() }} min read</span>
            </li>
        @endforeach
    </ul>
</aside>
