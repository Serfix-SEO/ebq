<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ __('Email preferences') }} · {{ config('app.name') }}</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color: #1e293b; line-height: 1.6; margin: 0; padding: 0; background: #f1f5f9; }
        .container { max-width: 480px; margin: 0 auto; padding: 60px 16px; }
        .card { background: #ffffff; border-radius: 14px; padding: 32px 28px; text-align: center; }
        h1 { font-size: 20px; font-weight: 800; color: #0f172a; margin: 0 0 12px; }
        p { font-size: 14px; color: #475569; margin: 0 0 18px; }
        .btn { display: inline-block; background: #F26419; color: #ffffff; text-decoration: none; border: none; border-radius: 10px; padding: 12px 24px; font-size: 14px; font-weight: 700; cursor: pointer; }
        .muted { font-size: 12px; color: #94a3b8; margin-top: 20px; margin-bottom: 0; }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        @if ($done)
            <h1>{{ __("You're unsubscribed") }}</h1>
            <p>{{ __("We won't send product tips or check-in emails to :email anymore.", ['email' => $user->email]) }}</p>
            <p class="muted">{{ __('Account and billing emails are unaffected.') }}</p>
        @else
            <h1>{{ __('Unsubscribe from these emails?') }}</h1>
            <p>{{ __("We'll stop sending product tips and check-in emails to :email.", ['email' => $user->email]) }}</p>
            <form method="POST" action="{{ url()->full() }}">
                @csrf
                <button type="submit" class="btn">{{ __('Unsubscribe') }}</button>
            </form>
            <p class="muted">{{ __('Account and billing emails are unaffected.') }}</p>
        @endif
    </div>
</div>
</body>
</html>
