<?php

namespace App\Support;

/**
 * What a generated article image must never contain.
 *
 * Incident (2026-08-16, TMC General Clinic): the hero image for a Dubai health
 * article carried the signage **"Al Noor Medical Center"** — a direct
 * competitor. Nobody asked for it. Two gaps produced it:
 *
 *  1. the art-director prompt was allowed to request the article title as a
 *     "bold text overlay", and once a diffusion model is drawing letters into a
 *     clinic reception it fills the wall signage too — inventing a plausible
 *     local clinic name;
 *  2. the "never include logos or real brand marks" rule was addressed to the
 *     LLM that WRITES prompts, never to the model that DRAWS. `IdeogramClient`
 *     has always accepted a `negative_prompt`; nothing passed one.
 *
 * ⚠️ **Measured against Ideogram v4 on 2026-08-18: `negative_prompt` DOES NOT
 * WORK.** The v4 endpoint rewrites every prompt into a ~3,900-character scene
 * description before drawing; the negatives below do not survive that, and
 * `magic_prompt=OFF` is ignored too. A clean, text-free prompt still produced
 * "NOVA CLINIC" on a reception wall for a real client. Worse, spelling the ban
 * out IN the prompt backfires — an explicit "no signage, no logos" clause left
 * 5 signage/wordmark terms in the expansion, versus 0 for a scene that simply
 * had no such surface.
 *
 * **The control that actually works is the SCENE**: never request a subject
 * containing a surface a name could sit on (reception desks, lobbies,
 * storefronts, facades, screens, uniforms, packaging). That rule lives in the
 * art-director system prompt in {@see \App\Jobs\GenerateContentImagesJob}.
 *
 * These negatives are kept as cheap defence-in-depth — harmless, and they do
 * bite on providers that honour the field — but nothing may DEPEND on them.
 */
final class ContentImageGuardrails
{
    /**
     * Sent as `negative_prompt` on EVERY generation. Ordered most-specific
     * first — the invented-business-name case is the one that reached a client.
     */
    public const NEGATIVE_PROMPT = 'business names, company names, clinic names, shop names, brand names, '
        .'logos, brand marks, trademarks, watermarks, signatures, '
        .'storefront signage, wall signage, banners, billboards, name plates, badges, '
        .'text, words, letters, lettering, captions, labels, subtitles, numbers, '
        .'celebrities, recognisable public figures';

    /**
     * Negatives for an image the CLIENT is directing themselves (the editor's
     * "regenerate with my own prompt"). Deliberately narrower: they may well
     * want their own name or a caption rendered — the TMC client typed
     * "change the name of the clinic to TMC General clinic" trying to fix this
     * very bug — so the blanket no-text rule must not fight their intent.
     * Competitor names and logos stay blocked.
     */
    public const MANUAL_NEGATIVE_PROMPT = 'logos, brand marks, trademarks, watermarks, '
        .'celebrities, recognisable public figures';

    /**
     * Extra negatives for a specific plan: the competitor domains this client
     * told us about, as bare brand words. Cheap insurance on top of the blanket
     * "no text" rule — a model that ignores the generic rule may still respect
     * a named term.
     *
     * @param  list<string>  $competitorDomains
     * @param  string  $base  NEGATIVE_PROMPT (pipeline) or MANUAL_NEGATIVE_PROMPT (client-directed)
     */
    public static function forCompetitors(array $competitorDomains, string $base = self::NEGATIVE_PROMPT): string
    {
        $names = [];
        foreach ($competitorDomains as $domain) {
            $host = strtolower(trim((string) $domain));
            if ($host === '') {
                continue;
            }
            // "alnoormedical.ae" → "alnoormedical": the registrable label is
            // what a model would render as a sign.
            $host = preg_replace('#^https?://#', '', $host) ?? $host;
            $host = preg_replace('#^www\.#', '', $host) ?? $host;
            $label = strtok($host, '.');
            if (is_string($label) && strlen($label) >= 4) {
                $names[] = $label;
            }
        }

        $names = array_slice(array_values(array_unique($names)), 0, 12);

        return $names === [] ? $base : $base.', '.implode(', ', $names);
    }
}
