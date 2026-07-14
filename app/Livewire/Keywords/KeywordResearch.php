<?php

namespace App\Livewire\Keywords;

use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Tabbed shell hosting the three keyword-research tools (Ideas · Volume ·
 * Competitor Gap) as one surface. Renders only the active child (each child
 * polls async and Gap's mount hits the DB, so mounting all three is wasteful).
 *
 * The cross-tool funnel: a child dispatches `research-handoff` with target +
 * keywords; we switch tabs and hand the payload to the target child via a
 * preset + a nonce-bumped key that forces a fresh, prefilled mount.
 */
class KeywordResearch extends Component
{
    private const TABS = ['ideas', 'volume'];

    #[Url]
    public string $tab = 'ideas';

    /**
     * Cross-PAGE handoff: the standalone Competitor Gap page (own Orbit
     * route since 2026-07-14, so its Livewire events can't reach this
     * component) sends keywords via `?kw=` instead.
     */
    #[Url]
    public string $kw = '';

    /** @var array{target?: string, keywords?: list<string>, mode?: ?string, nonce?: string} */
    public array $handoff = [];

    public function mount(): void
    {
        // Competitor Gap left the hub for its own page — old ?tab=gap
        // bookmarks/links land there.
        if ($this->tab === 'gap') {
            $this->redirectRoute('keyword-gap.index');

            return;
        }
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'ideas';
        }

        if ($this->kw !== '') {
            $this->handoff = [
                'target' => $this->tab,
                'keywords' => [$this->kw],
                'mode' => $this->tab === 'ideas' ? 'seeds' : null,
                'nonce' => (string) Str::uuid(),
            ];
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
            $this->handoff = []; // manual navigation → clean child
        }
    }

    /**
     * Receive a keyword handoff from a child tool and route it to the target tab.
     *
     * @param  list<string>  $keywords
     */
    #[On('research-handoff')]
    public function onHandoff(string $target, array $keywords = [], ?string $mode = null): void
    {
        if (! in_array($target, self::TABS, true)) {
            return;
        }
        $this->tab = $target;
        $this->handoff = [
            'target' => $target,
            'keywords' => array_values(array_filter(array_map('strval', $keywords))),
            'mode' => $mode,
            'nonce' => (string) Str::uuid(),
        ];
    }

    /** Preset for the active child — only when the handoff actually targets it. */
    public function presetForActiveTab(): ?array
    {
        if (($this->handoff['target'] ?? null) !== $this->tab) {
            return null;
        }

        return [
            'keywords' => $this->handoff['keywords'] ?? [],
            'mode' => $this->handoff['mode'] ?? null,
        ];
    }

    public function render()
    {
        return view('livewire.keywords.keyword-research', [
            'preset' => $this->presetForActiveTab(),
            'nonce' => $this->handoff['nonce'] ?? 'none',
        ]);
    }
}
