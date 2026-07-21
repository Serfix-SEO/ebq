{{-- Minimal fallback layout, used only when content-ai.views.layout is null. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @yield('head')
</head>
<body>
    <main>@yield('content')</main>
</body>
</html>
