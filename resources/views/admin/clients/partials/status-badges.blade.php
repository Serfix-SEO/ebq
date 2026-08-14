{{-- Admin / Active / Disabled badges. Shared by table row and mobile card.

     Expects: $client --}}
<div class="flex flex-wrap gap-1">
    @if ($client->is_admin)
        <span class="inline-flex items-center gap-1 rounded border border-orange-200 bg-orange-50 px-1.5 py-0.5 text-[10px] font-semibold text-orange-700">
            <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20"><path d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/></svg>
            Admin
        </span>
    @endif
    @if ($client->is_disabled)
        <span class="inline-flex rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">Disabled</span>
    @elseif (! $client->is_admin)
        <span class="inline-flex rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">Active</span>
    @endif
</div>
