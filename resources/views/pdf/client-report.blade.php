<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 11px; line-height: 1.4; }
        table { border-collapse: collapse; }
        img { max-width: 100%; }
    </style>
</head>
<body>
    @include('reports._body', ['payload' => $payload, 'branding' => $branding, 'generatedAt' => $generatedAt ?? null])
</body>
</html>
