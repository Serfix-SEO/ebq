<x-layouts.app>
    {{-- Content Autopilot is a separate product on its own Cashier
         subscription, so the dashboard panel (which reads `default`) can never
         show it. The content panel renders nothing for users with no content
         relationship, so an SEO-only billing page is unchanged.

         Order follows what the customer actually pays for: a content-only
         client would otherwise scroll past the whole SEO plan grid to reach
         the one subscription they are being charged for. --}}
    @php $contentFirst = (bool) auth()->user()?->isContentOnly(); @endphp

    @if ($contentFirst)
        <livewire:billing.content-subscription-panel :first="true" />
    @endif

    <livewire:billing.subscription-panel />

    @if (! $contentFirst)
        <livewire:billing.content-subscription-panel />
    @endif
</x-layouts.app>
