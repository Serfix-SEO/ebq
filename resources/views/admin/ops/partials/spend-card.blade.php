{{--
    One monthly-spend card. Admin-only surface: budgets and caps are never
    mentioned on client-facing pages (see the client-facing copy rules).

    Renders in both modes on purpose:
      - cap set      → "$spent / $cap", amber at 80%, rose when exhausted;
      - cap disabled → "$spent — no cap" in neutral styling.

    The card used to be wrapped in `@if ($data['cap'] !== null)`, so turning a
    breaker off also deleted the only place the spend was visible. Tracking and
    enforcement are separate concerns: the meters keep counting real billed
    dollars whether or not anything stops at a threshold.

    @param string $label     e.g. "Content Autopilot image"
    @param array  $data      ['spent','cap','near','exhausted']
    @param string $env       env var that sets the cap (shown when exhausted)
    @param string $onExhaust what stops happening when the cap is hit
--}}
@php
    $capped = ($data['cap'] ?? null) !== null;
    $tone = ! $capped
        ? 'border-slate-200 bg-white text-slate-600'
        : ($data['exhausted']
            ? 'border-rose-200 bg-rose-50 text-rose-800'
            : ($data['near'] ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-white text-slate-600'));
@endphp

<div class="rounded-lg border px-4 py-3 text-sm {{ $tone }}">
    <span class="font-semibold">{{ $label }} spend this month:</span>
    ${{ number_format($data['spent'], 2) }}

    @if ($capped)
        / ${{ number_format($data['cap'], 2) }}
        @if ($data['exhausted'])
            — <span class="font-semibold">cap reached</span>: {{ $onExhaust }} Raise <code class="text-xs">{{ $env }}</code> to resume.
        @elseif ($data['near'])
            — approaching the cap (80%+).
        @endif
    @else
        — <span class="text-slate-400">no cap</span>
    @endif
</div>
