{{-- Head tags for a Content AI article. Usage: <x-content-ai::meta :article="$article" /> --}}
@php($m = app(\Serfix\ContentAi\Services\MetaBuilder::class)->for($article))
<title>{{ $m['title'] }}</title>
<meta name="description" content="{{ $m['description'] }}">
<meta name="robots" content="{{ $m['robots'] }}">
<link rel="canonical" href="{{ $m['canonical'] }}">
@foreach ($m['og'] as $property => $content)
<meta property="{{ $property }}" content="{{ $content }}">
@endforeach
@foreach ($m['twitter'] as $name => $content)
<meta name="{{ $name }}" content="{{ $content }}">
@endforeach
