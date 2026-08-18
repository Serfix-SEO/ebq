<?php

namespace App\Services\Content\Publishing\RichText;

use App\Services\Content\Publishing\RichText\Blocks\Blockquote;
use App\Services\Content\Publishing\RichText\Blocks\Heading;
use App\Services\Content\Publishing\RichText\Blocks\ImageBlock;
use App\Services\Content\Publishing\RichText\Blocks\ListBlock;
use App\Services\Content\Publishing\RichText\Blocks\Paragraph;
use App\Services\Content\Publishing\RichText\Blocks\TableBlock;

/**
 * Blocks → Markdown, for clients whose platform we cannot publish into
 * automatically (a Hostinger Horizon site, a hand-built site, any builder with
 * no content API). Third adapter over the shared {@see HtmlBlockParser}, after
 * Ricos (Wix) and Portable Text (Sanity).
 *
 * Generated from the article's CURRENT html — never from `content_articles.
 * markdown`, which `ArticleReview::save()` copies verbatim from the previous
 * version and is therefore stale the moment a client edits anything.
 */
class MarkdownAdapter
{
    /** @param  list<\App\Services\Content\Publishing\RichText\Blocks\Block>  $blocks */
    public function render(array $blocks): string
    {
        $out = [];

        foreach ($blocks as $block) {
            $out[] = match (true) {
                $block instanceof Heading => str_repeat('#', $block->level).' '.$this->inlines($block->inlines),
                $block instanceof Paragraph => $this->inlines($block->inlines),
                $block instanceof Blockquote => '> '.$this->inlines($block->inlines),
                $block instanceof ImageBlock => $this->image($block),
                $block instanceof ListBlock => $this->list($block),
                $block instanceof TableBlock => $this->table($block),
                default => '',
            };
        }

        // Blank line between blocks; collapse the runs an empty block leaves.
        $md = implode("\n\n", array_filter($out, static fn ($s) => trim((string) $s) !== ''));

        return trim(preg_replace("/\n{3,}/", "\n\n", $md) ?? $md)."\n";
    }

    /** @param  list<Inline>  $inlines */
    private function inlines(array $inlines): string
    {
        $out = '';
        foreach ($inlines as $i) {
            $text = $this->escape($i->text);
            if (trim($text) === '') {
                $out .= $text; // keep meaningful whitespace between runs
                continue;
            }
            // Emphasis markers must hug the words, or Markdown renders the
            // asterisks literally: "** bold **" is not bold.
            [$lead, $core, $tail] = $this->split($text);
            if ($i->bold) {
                $core = '**'.$core.'**';
            }
            if ($i->italic) {
                $core = '*'.$core.'*';
            }
            if ($i->href !== null && $i->href !== '') {
                $core = '['.$core.']('.$i->href.')';
            }
            $out .= $lead.$core.$tail;
        }

        return trim($out);
    }

    /** Split leading/trailing whitespace off so wrappers hug the text. */
    private function split(string $text): array
    {
        preg_match('/^(\s*)(.*?)(\s*)$/us', $text, $m);

        return [$m[1] ?? '', $m[2] ?? $text, $m[3] ?? ''];
    }

    private function image(ImageBlock $b): string
    {
        return '!['.$this->escape($b->alt).']('.$b->src.')';
    }

    private function list(ListBlock $b): string
    {
        $lines = [];
        foreach ($b->items as $n => $item) {
            $marker = $b->ordered ? (($n + 1).'.') : '-';
            $lines[] = $marker.' '.$this->inlines(is_array($item) ? $item : [$item]);
        }

        return implode("\n", $lines);
    }

    private function table(TableBlock $b): string
    {
        if ($b->rows === []) {
            return '';
        }
        $rows = array_values($b->rows);
        $header = array_map(fn ($c) => $this->escapeCell((string) $c), (array) $rows[0]);
        $lines = ['| '.implode(' | ', $header).' |'];
        $lines[] = '| '.implode(' | ', array_fill(0, count($header), '---')).' |';

        foreach (array_slice($rows, 1) as $row) {
            $cells = array_map(fn ($c) => $this->escapeCell((string) $c), (array) $row);
            // Pad/trim so every row matches the header width — a ragged table
            // renders as literal pipes.
            $cells = array_slice(array_pad($cells, count($header), ''), 0, count($header));
            $lines[] = '| '.implode(' | ', $cells).' |';
        }

        return implode("\n", $lines);
    }

    /** Escape the characters that would otherwise become Markdown syntax. */
    private function escape(string $text): string
    {
        return preg_replace('/(?<!\\\\)([\\\\`*_\[\]<>])/u', '\\\\$1', $text) ?? $text;
    }

    private function escapeCell(string $text): string
    {
        return str_replace('|', '\\|', $this->escape(trim($text)));
    }
}
