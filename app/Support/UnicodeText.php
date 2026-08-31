<?php

namespace App\Support;

/**
 * Unicode-aware text helpers for the content pipeline.
 *
 * Why this exists (prod 2026-08-31, namesforfreefire.com): PHP's
 * str_word_count() counts only Latin letters — a complete ~2,000-word Arabic
 * article measured 39 words (its few Latin tokens), and exact keyword
 * matching treated "اسماء" (bare alif, how people type it in search) and
 * "أسماء" (hamza, how it is correctly written) as different strings. Both
 * together pinned every version of an Arabic article at ~50 and the topic
 * regenerated 64 times before the version counter overflowed.
 */
final class UnicodeText
{
    /** Count words in any script (sequences of letters/digits). */
    public static function wordCount(string $text): int
    {
        return (int) preg_match_all('/[\p{L}\p{N}]+/u', $text);
    }

    /**
     * Case-fold + Arabic orthography normalization for MATCHING (never for
     * display). Mirrors the standard Arabic search normalization (Lucene's
     * ArabicNormalizer): strip tashkeel/tatweel, alef variants → ا,
     * dotless ya ى → ي, teh marbuta ة → ه, hamza-on-ya ئ → ي.
     * Latin text is only lowercased — behavior unchanged for existing sites.
     */
    public static function fold(string $text): string
    {
        $text = mb_strtolower($text);
        // Tashkeel (harakat), superscript alef, tatweel.
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);

        return strtr($text, [
            "\u{0623}" => "\u{0627}", // أ → ا
            "\u{0625}" => "\u{0627}", // إ → ا
            "\u{0622}" => "\u{0627}", // آ → ا
            "\u{0671}" => "\u{0627}", // ٱ → ا
            "\u{0649}" => "\u{064A}", // ى → ي
            "\u{0626}" => "\u{064A}", // ئ → ي
            "\u{0629}" => "\u{0647}", // ة → ه
        ]);
    }
}
