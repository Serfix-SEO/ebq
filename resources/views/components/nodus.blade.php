{{--
    Nodus — the Serfix Content Autopilot mascot (character sheet 2026-07-23):
    a glowing signal core with two "search point" eyes and nodes orbiting an
    asymmetric halo. Implemented as a self-contained inline SVG so it needs
    NOTHING from the compiled Tailwind bundle (see project memory: uncompiled
    utility classes silently render nothing).

    Usage: <x-nodus state="searching" :size="96" class="text-slate-400 dark:text-slate-500" />
      - state: idle | searching | analyzing | success | confused
        (expression system = motion + light, never face swaps:
         searching → eyes scan, curious pace; analyzing → fast orbit, quick pulse;
         success → strong steady glow, calm orbit; confused → orbit wobbles,
         one node runs backwards)
      - size:  rendered px (square). Eyes auto-hide below 40px (micro tier).
      - text-* class controls the halo ring color (stroke: currentColor).

    Motion: nodes ride the ellipse via SMIL animateMotion (works everywhere,
    keeps dots round — no scaleY squash), split into back/front halves with
    clip-paths so nodes pass BEHIND the core on the far side and IN FRONT on
    the near side. Expression states are CSS-only. prefers-reduced-motion
    swaps moving nodes for a static pose and stops every CSS animation.

    Colors are the SERFIX brand palette (#F26419 / #C44E0E — NOT the concept
    sheet's #FF7A00; exact brand hex is a hard rule) + the sheet's soft-blue
    accent for one node.
--}}
@props(['state' => 'idle', 'size' => 96])
@php
    $state = in_array($state, ['idle', 'searching', 'analyzing', 'success', 'confused'], true) ? $state : 'idle';
    // Orbit periods (s) per node, tuned per expression state.
    [$d1, $d2, $d3] = match ($state) {
        'searching' => [7.5, 11, 14.5],
        'analyzing' => [3.6, 5.2, 6.6],
        'success' => [16, 22, 28],
        'confused' => [8.5, 12, 7],
        default => [12, 17, 23],
    };
    // The halo ellipse (plane-local; the -16° tilt is applied via CSS on the plane).
    $orbit = 'M107 60 A47 19 0 1 1 13 60 A47 19 0 1 1 107 60';
    $micro = $size < 40; // micro/icon tier: drop the eyes (sheet's scale system)
@endphp
@once
<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <defs>
        <radialGradient id="nodusCoreG" cx="38%" cy="32%" r="78%">
            <stop offset="0%" stop-color="#FFC9A3"/>
            <stop offset="42%" stop-color="#F26419"/>
            <stop offset="100%" stop-color="#C44E0E"/>
        </radialGradient>
        <radialGradient id="nodusGlowG">
            <stop offset="0%" stop-color="#F26419" stop-opacity="0.5"/>
            <stop offset="70%" stop-color="#F26419" stop-opacity="0.16"/>
            <stop offset="100%" stop-color="#F26419" stop-opacity="0"/>
        </radialGradient>
        {{-- Far/near halves of the orbit plane (plane-local coords, 1px overlap kills the seam) --}}
        <clipPath id="nodusClipBack"><rect x="-10" y="-10" width="140" height="70.5"/></clipPath>
        <clipPath id="nodusClipFront"><rect x="-10" y="59.5" width="140" height="70.5"/></clipPath>
    </defs>
</svg>
<style>
    .nodus { display: inline-block; vertical-align: middle; }
    .nodus__plane { transform: rotate(-16deg); transform-origin: 60px 60px; }
    .nodus__ring { fill: none; stroke: currentColor; stroke-opacity: .35; stroke-width: 1.4; }
    .nodus__core { filter: drop-shadow(0 2px 7px rgba(242, 100, 25, .45)); }
    .nodus__glow { transform-origin: 60px 60px; animation: nodus-glow 3.8s ease-in-out infinite; }
    .nodus__eye { transform-box: fill-box; transform-origin: center; animation: nodus-blink 5.6s ease-in-out infinite; }
    .nodus__eyes { transform-origin: 60px 58px; }
    .nodus__node-static { display: none; }

    @keyframes nodus-glow { 0%, 100% { opacity: .55; transform: scale(1); } 50% { opacity: .9; transform: scale(1.1); } }
    @keyframes nodus-blink { 0%, 92.5%, 100% { transform: scaleY(1); } 95% { transform: scaleY(.12); } 97.5% { transform: scaleY(1); } }
    @keyframes nodus-scan { 0%, 100% { transform: translateX(0); } 28% { transform: translateX(-3.5px); } 66% { transform: translateX(3.5px); } }
    @keyframes nodus-wobble { 0%, 100% { transform: rotate(-16deg); } 30% { transform: rotate(-7deg); } 70% { transform: rotate(-25deg); } }
    @keyframes nodus-glow-strong { 0%, 100% { opacity: .85; transform: scale(1.05); } 50% { opacity: 1; transform: scale(1.2); } }

    .nodus--searching .nodus__eyes { animation: nodus-scan 3.2s ease-in-out infinite; }
    .nodus--analyzing .nodus__glow { animation-duration: 1.5s; }
    .nodus--success .nodus__glow { animation: nodus-glow-strong 2.6s ease-in-out infinite; }
    .nodus--confused .nodus__plane { animation: nodus-wobble 2.8s ease-in-out infinite; }

    @media (prefers-reduced-motion: reduce) {
        .nodus *, .nodus { animation: none !important; }
        .nodus__node-move { display: none; }
        .nodus__node-static { display: inline; }
    }
</style>
@endonce
<svg {{ $attributes->class(['nodus', 'nodus--'.$state]) }} width="{{ $size }}" height="{{ $size }}"
     viewBox="0 0 120 120" fill="none" aria-hidden="true" focusable="false">
    <circle class="nodus__glow" cx="60" cy="60" r="37" fill="url(#nodusGlowG)"/>

    {{-- Far side of the halo: ring + nodes pass BEHIND the core --}}
    <g class="nodus__plane">
        <g clip-path="url(#nodusClipBack)">
            <path class="nodus__ring" d="{{ $orbit }}"/>
            <circle class="nodus__node-move" r="4.5" fill="#F26419">
                <animateMotion dur="{{ $d1 }}s" begin="0s" repeatCount="indefinite" path="{{ $orbit }}"/>
            </circle>
            <circle class="nodus__node-move" r="3.4" fill="#fff" stroke="#94A3B8" stroke-width="1">
                <animateMotion dur="{{ $d2 }}s" begin="-{{ round($d2 * 0.4, 2) }}s" repeatCount="indefinite" path="{{ $orbit }}"/>
            </circle>
            <circle class="nodus__node-move" r="2.8" fill="#9AD6FF">
                <animateMotion dur="{{ $d3 }}s" begin="-{{ round($d3 * 0.72, 2) }}s" repeatCount="indefinite" path="{{ $orbit }}"
                    @if ($state === 'confused') keyPoints="1;0" keyTimes="0;1" calcMode="linear" @endif/>
            </circle>
            <circle class="nodus__node-static" cx="44" cy="42.1" r="4.5" fill="#F26419"/>
            <circle class="nodus__node-static" cx="98.5" cy="70.9" r="3.4" fill="#fff" stroke="#94A3B8" stroke-width="1"/>
            <circle class="nodus__node-static" cx="15.8" cy="66.5" r="2.8" fill="#9AD6FF"/>
        </g>
    </g>

    {{-- Signal core + search points --}}
    <circle class="nodus__core" cx="60" cy="60" r="21" fill="url(#nodusCoreG)"/>
    <circle cx="52" cy="51" r="7.5" fill="#fff" opacity=".16"/>
    @unless ($micro)
        <g class="nodus__eyes" fill="#fff">
            <circle class="nodus__eye" cx="53" cy="58" r="3.2"/>
            <circle class="nodus__eye" cx="67" cy="58" r="3.2"/>
        </g>
    @endunless

    {{-- Near side of the halo: ring + nodes pass IN FRONT of the core --}}
    <g class="nodus__plane">
        <g clip-path="url(#nodusClipFront)">
            <path class="nodus__ring" d="{{ $orbit }}"/>
            <circle class="nodus__node-move" r="4.5" fill="#F26419">
                <animateMotion dur="{{ $d1 }}s" begin="0s" repeatCount="indefinite" path="{{ $orbit }}"/>
            </circle>
            <circle class="nodus__node-move" r="3.4" fill="#fff" stroke="#94A3B8" stroke-width="1">
                <animateMotion dur="{{ $d2 }}s" begin="-{{ round($d2 * 0.4, 2) }}s" repeatCount="indefinite" path="{{ $orbit }}"/>
            </circle>
            <circle class="nodus__node-move" r="2.8" fill="#9AD6FF">
                <animateMotion dur="{{ $d3 }}s" begin="-{{ round($d3 * 0.72, 2) }}s" repeatCount="indefinite" path="{{ $orbit }}"
                    @if ($state === 'confused') keyPoints="1;0" keyTimes="0;1" calcMode="linear" @endif/>
            </circle>
            <circle class="nodus__node-static" cx="44" cy="42.1" r="4.5" fill="#F26419"/>
            <circle class="nodus__node-static" cx="98.5" cy="70.9" r="3.4" fill="#fff" stroke="#94A3B8" stroke-width="1"/>
            <circle class="nodus__node-static" cx="15.8" cy="66.5" r="2.8" fill="#9AD6FF"/>
        </g>
    </g>
</svg>
