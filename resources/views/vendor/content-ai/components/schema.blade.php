{{-- JSON-LD for a Content AI article. Usage: <x-content-ai::schema :article="$article" /> --}}
@php($json = app(\Serfix\ContentAi\Services\SchemaBuilder::class)->toJson($article))
@if ($json !== '')
<script type="application/ld+json">{!! $json !!}</script>
@endif
