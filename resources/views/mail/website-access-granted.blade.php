<x-mail::message>
# {{ __('Access granted') }}

{{ __('You now have access to :domain in :app.', ['domain' => '**'.$website->domain.'**', 'app' => config('app.name')]) }}

<x-mail::button :url="route('dashboard')">
{{ __('Open dashboard') }}
</x-mail::button>

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
