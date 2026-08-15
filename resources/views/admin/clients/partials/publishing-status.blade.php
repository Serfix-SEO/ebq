{{-- Whether this client has actually wired a publishing destination, and
     whether it's healthy — rolled up across every website they own.

     Counts come from the listing's sub-selects (integrations_connected /
     _error / _total); the platform names come from the eager-loaded
     websites.contentIntegrations, so this costs no extra query per row.

     Expects: $client --}}
@php
    $sites = (int) ($client->websites_count ?? 0);
    $connected = (int) ($client->integrations_connected ?? 0);
    $errored = (int) ($client->integrations_error ?? 0);
    $totalIntegrations = (int) ($client->integrations_total ?? 0);

    $platforms = $client->relationLoaded('websites')
        ? $client->websites
            ->flatMap(fn ($w) => $w->relationLoaded('contentIntegrations') ? $w->contentIntegrations : collect())
            ->map(fn ($i) => $i->platformLabel())
            ->unique()
            ->values()
            ->all()
        : [];

    // Sites with at least one connected destination — "2/3 sites" reads better
    // than a raw integration count when a client runs several websites.
    $connectedSites = $client->relationLoaded('websites')
        ? $client->websites->filter(fn ($w) => $w->relationLoaded('contentIntegrations')
            && $w->contentIntegrations->contains(fn ($i) => $i->isConnected()))->count()
        : 0;

    if ($sites === 0) {
        $pub = ['label' => 'No website', 'tone' => 'empty', 'note' => 'nothing added yet'];
    } elseif ($totalIntegrations === 0) {
        $pub = ['label' => 'Not connected', 'tone' => 'warn', 'note' => 'no destination set up'];
    } elseif ($connected > 0) {
        $pub = [
            'label' => 'Connected',
            'tone' => 'ok',
            'note' => ($sites > 1 ? $connectedSites.'/'.$sites.' sites · ' : '').implode(', ', $platforms),
        ];
    } elseif ($errored > 0) {
        $pub = ['label' => 'Connection error', 'tone' => 'bad', 'note' => implode(', ', $platforms)];
    } else {
        $pub = ['label' => 'Pending', 'tone' => 'warn', 'note' => 'setup not finished'];
    }
@endphp
<div class="min-w-0">
    <span @class([
        'inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-semibold',
        'border border-emerald-200 bg-emerald-50 text-emerald-700' => $pub['tone'] === 'ok',
        'border border-rose-200 bg-rose-50 text-rose-700' => $pub['tone'] === 'bad',
        'border border-amber-200 bg-amber-50 text-amber-700' => $pub['tone'] === 'warn',
        'border border-dashed border-slate-300 text-slate-400' => $pub['tone'] === 'empty',
    ])>
        @if ($pub['tone'] === 'ok')
            <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
        @elseif ($pub['tone'] === 'bad')
            <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
        @endif
        {{ $pub['label'] }}
    </span>
    @if ($pub['note'] !== '')
        <p class="mt-0.5 truncate text-[10px] text-slate-400">{{ $pub['note'] }}</p>
    @endif
</div>
