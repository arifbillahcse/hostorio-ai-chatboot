<?php

declare(strict_types=1);

namespace Hostorio\Knowledge;

/**
 * Turns WordPress post content into plain text worth embedding.
 *
 * WordPress bodies are not clean prose: they carry Gutenberg block comments,
 * shortcodes, inline HTML, and often page-builder markup. Feeding that to an
 * embedding model wastes tokens on syntax and dilutes the semantic signal, so
 * everything non-textual is stripped before chunking.
 */
final class TextNormalizer
{
    /**
     * Elements whose *content* is markup rather than prose and must go with them.
     */
    private const STRIP_ELEMENTS = ['script', 'style', 'noscript', 'svg', 'iframe'];

    public function normalize(string $html): string
    {
        $text = $html;

        // Gutenberg block delimiters: <!-- wp:paragraph {...} --> … <!-- /wp:paragraph -->
        $text = preg_replace('/<!--\s*\/?wp:.*?-->/s', '', $text) ?? $text;

        // Any remaining HTML comments.
        $text = preg_replace('/<!--.*?-->/s', '', $text) ?? $text;

        // Drop script/style blocks entirely, contents included.
        foreach (self::STRIP_ELEMENTS as $element) {
            $text = preg_replace('#<' . $element . '\b[^>]*>.*?</' . $element . '>#is', ' ', $text) ?? $text;
            // Unclosed variants.
            $text = preg_replace('#<' . $element . '\b[^>]*/?>#i', ' ', $text) ?? $text;
        }

        // Block-level tags become paragraph breaks so chunking can split on
        // meaning rather than mid-sentence.
        $text = preg_replace(
            '#</(p|div|section|article|h[1-6]|li|tr|blockquote|pre)\s*>#i',
            "\n\n",
            $text
        ) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;

        // Self-closing shortcodes and shortcode wrappers: [gallery id="4"],
        // [button]Text[/button]. The inner text is kept; the tags are not.
        $text = preg_replace('/\[\/?[a-z0-9_\-]+(?:[^\]]*)?\]/i', ' ', $text) ?? $text;

        // Remaining tags.
        $text = strip_tags($text);

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Non-breaking spaces read as ordinary spaces to a model but break
        // naive whitespace collapsing.
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{FEFF}"], ' ', $text);

        return $this->collapseWhitespace($text);
    }

    /**
     * Normalise plain text that did not come from HTML — manual notes, mostly.
     * Paragraph structure is preserved; runaway blank lines are not.
     */
    public function normalizePlainText(string $text): string
    {
        $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean) ?? $clean;

        return $this->collapseWhitespace($clean);
    }

    private function collapseWhitespace(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Collapse horizontal runs but keep newlines meaningful.
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Stable fingerprint of a document's content, used to skip re-indexing —
     * and, more to the point, to avoid paying to re-embed text that has not
     * changed.
     */
    public function hash(string $title, string $body): string
    {
        return hash('sha256', $title . "\n\x1f\n" . $body);
    }
}
