<x-mail::message>
# {{ __('Website invitation') }}

{{ __('You\'ve been invited to collaborate on') }} **{{ $invitation->website->domain }}** {{ __('in') }} {{ config('app.name') }}.

<x-mail::button :url="route('register', ['invite' => $plainToken])">
{{ __('Create your account') }}
</x-mail::button>

{{ __('This invitation expires on') }} {{ $invitation->expires_at->toFormattedDateString() }}.

{{ __('If you did not expect this email, you can ignore it.') }}

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
