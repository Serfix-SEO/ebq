<?php

namespace App\Support;

/**
 * Catalogue of article-image visual styles the onboarding image step offers.
 * Each style maps to: a human label, a one-line description for the picker, an
 * art-direction prompt fragment fed to the image LLM/Ideogram, and the
 * Ideogram `style_type` (AUTO|GENERAL|REALISTIC|DESIGN) that best matches.
 *
 * Niche-agnostic — these are the common categories for blog/website article
 * imagery, not tied to any single vertical.
 */
class ContentImageStyles
{
    /**
     * @return array<string, array{label:string, desc:string, prompt:string, ideogram:string}>
     */
    public static function all(): array
    {
        return [
            'photographic' => [
                'label' => 'Photographic',
                'desc' => 'Realistic photos, natural light',
                'prompt' => 'a high-resolution, realistic photograph with natural lighting and shallow depth of field',
                'ideogram' => 'REALISTIC',
            ],
            'cinematic' => [
                'label' => 'Cinematic',
                'desc' => 'Dramatic, film-like lighting',
                'prompt' => 'a cinematic, dramatic shot with moody lighting, high contrast and a filmic color grade',
                'ideogram' => 'REALISTIC',
            ],
            'digital_illustration' => [
                'label' => 'Digital illustration',
                'desc' => 'Painted, editorial illustration',
                'prompt' => 'a polished digital illustration in a modern editorial style, rich colors, clean shapes',
                'ideogram' => 'DESIGN',
            ],
            'anime' => [
                'label' => 'Anime / Manga',
                'desc' => 'Japanese anime art style',
                'prompt' => 'an anime / manga art style illustration, expressive characters, cel shading, vibrant colors',
                'ideogram' => 'GENERAL',
            ],
            'cartoon' => [
                'label' => 'Cartoon',
                'desc' => 'Fun, bold cartoon characters',
                'prompt' => 'a friendly cartoon illustration with bold outlines, bright flat colors and playful characters',
                'ideogram' => 'DESIGN',
            ],
            'threed' => [
                'label' => '3D render',
                'desc' => 'Rendered 3D, soft shadows',
                'prompt' => 'a clean 3D rendered scene, soft global illumination, smooth materials, subtle depth of field',
                'ideogram' => 'GENERAL',
            ],
            'flat_vector' => [
                'label' => 'Flat / Vector',
                'desc' => 'Minimal flat vector graphics',
                'prompt' => 'a flat vector illustration, simple geometric shapes, limited harmonious palette, no gradients',
                'ideogram' => 'DESIGN',
            ],
            'minimalist' => [
                'label' => 'Minimalist',
                'desc' => 'Clean, lots of negative space',
                'prompt' => 'a minimalist composition with generous negative space, a restrained palette and a single clear subject',
                'ideogram' => 'DESIGN',
            ],
            'watercolor' => [
                'label' => 'Watercolor',
                'desc' => 'Soft, painterly watercolor',
                'prompt' => 'a soft watercolor painting with organic bleeds, textured paper feel and gentle gradients',
                'ideogram' => 'GENERAL',
            ],
            'isometric' => [
                'label' => 'Isometric',
                'desc' => 'Isometric 3D-style scenes',
                'prompt' => 'an isometric illustration, 3/4 top-down angle, tidy geometry, soft shadows, cohesive palette',
                'ideogram' => 'DESIGN',
            ],
        ];
    }

    /** Valid style keys. */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function isValid(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::all());
    }

    /** Default style when none picked. */
    public static function default(): string
    {
        return 'photographic';
    }

    /** Art-direction prompt fragment for a style key (falls back to default). */
    public static function prompt(?string $key): string
    {
        $all = self::all();
        $entry = $all[$key] ?? $all[self::default()];

        return (string) $entry['prompt'];
    }

    /** Ideogram style_type for a style key. */
    public static function ideogramStyle(?string $key): string
    {
        $all = self::all();

        return (string) ($all[$key]['ideogram'] ?? 'AUTO');
    }
}
