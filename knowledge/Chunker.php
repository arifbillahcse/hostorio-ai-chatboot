<?php

declare(strict_types=1);

namespace Hostorio\Knowledge;

use Hostorio\Core\Config;

/**
 * Splits a document into retrievable pieces.
 *
 * Splits on natural boundaries — paragraphs first, then sentences — rather than
 * a fixed character count, because a chunk cut mid-sentence embeds poorly and
 * reads badly when it ends up quoted in an answer.
 *
 * Consecutive chunks overlap by a configurable amount so an answer that
 * straddles a boundary is still fully present in at least one chunk.
 */
final class Chunker
{
    /**
     * @return array<int, string>
     */
    public function chunk(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $maxChars     = max(200, (int) Config::get('knowledge.chunk.max_chars', 1200));
        $overlapChars = max(0, (int) Config::get('knowledge.chunk.overlap_chars', 150));
        $minChars     = max(0, (int) Config::get('knowledge.chunk.min_chars', 120));

        // Overlap must stay below the chunk size or the walk never advances.
        $overlapChars = min($overlapChars, (int) floor($maxChars / 2));

        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return [$text];
        }

        $chunks  = [];
        $current = '';

        foreach ($this->splitIntoUnits($text, $maxChars) as $unit) {
            $candidate = $current === '' ? $unit : $current . "\n\n" . $unit;

            if (mb_strlen($candidate, 'UTF-8') <= $maxChars) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
                $current  = $this->overlapTail($current, $overlapChars);
                $current  = $current === '' ? $unit : $current . "\n\n" . $unit;

                // The overlap plus this unit may itself exceed the limit.
                if (mb_strlen($current, 'UTF-8') > $maxChars) {
                    $chunks[] = mb_substr($current, 0, $maxChars, 'UTF-8');
                    $current  = '';
                }

                continue;
            }

            $current = $unit;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $this->mergeUndersizedTail($chunks, $minChars, $maxChars);
    }

    /**
     * Break text into paragraph-sized units, splitting any paragraph that is
     * itself larger than a whole chunk down to sentences, then to hard slices.
     *
     * @return array<int, string>
     */
    private function splitIntoUnits(string $text, int $maxChars): array
    {
        $units = [];

        foreach (preg_split('/\n{2,}/u', $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph, 'UTF-8') <= $maxChars) {
                $units[] = $paragraph;
                continue;
            }

            foreach ($this->splitSentences($paragraph) as $sentence) {
                if (mb_strlen($sentence, 'UTF-8') <= $maxChars) {
                    $units[] = $sentence;
                    continue;
                }

                // A single "sentence" longer than a chunk is usually a log dump
                // or a minified blob. Slice it; there is no better boundary.
                foreach ($this->hardSlice($sentence, $maxChars) as $slice) {
                    $units[] = $slice;
                }
            }
        }

        return $units;
    }

    /**
     * @return array<int, string>
     */
    private function splitSentences(string $paragraph): array
    {
        // Split after ., ! or ? followed by whitespace. Deliberately simple —
        // an over-eager split costs a little retrieval quality, whereas a
        // locale-aware sentence tokeniser is a dependency we cannot install.
        $parts = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @return array<int, string>
     */
    private function hardSlice(string $text, int $maxChars): array
    {
        $slices = [];
        $length = mb_strlen($text, 'UTF-8');

        for ($offset = 0; $offset < $length; $offset += $maxChars) {
            $slices[] = mb_substr($text, $offset, $maxChars, 'UTF-8');
        }

        return $slices;
    }

    /**
     * The tail of a chunk, rounded to a word boundary, to prepend to the next one.
     */
    private function overlapTail(string $chunk, int $overlapChars): string
    {
        if ($overlapChars <= 0) {
            return '';
        }

        $tail = mb_substr($chunk, -$overlapChars, null, 'UTF-8');

        // Drop a leading partial word so the overlap starts cleanly.
        $spacePosition = mb_strpos($tail, ' ', 0, 'UTF-8');

        if ($spacePosition !== false && $spacePosition < mb_strlen($tail, 'UTF-8') - 1) {
            $tail = mb_substr($tail, $spacePosition + 1, null, 'UTF-8');
        }

        return trim($tail);
    }

    /**
     * Fold a too-small final chunk back into its predecessor.
     *
     * A 20-character trailing chunk ("Thanks!") matches queries poorly and
     * wastes an embedding call, but the same text is useful as context on the
     * end of the chunk before it.
     *
     * @param array<int, string> $chunks
     * @return array<int, string>
     */
    private function mergeUndersizedTail(array $chunks, int $minChars, int $maxChars): array
    {
        $count = count($chunks);

        if ($count < 2 || $minChars <= 0) {
            return $chunks;
        }

        $last = $chunks[$count - 1];

        if (mb_strlen($last, 'UTF-8') >= $minChars) {
            return $chunks;
        }

        $merged = $chunks[$count - 2] . "\n\n" . $last;

        // Only merge if the result stays within a reasonable margin; otherwise
        // an oversized chunk is worse than a small one.
        if (mb_strlen($merged, 'UTF-8') > $maxChars + $minChars) {
            return $chunks;
        }

        array_splice($chunks, $count - 2, 2, [$merged]);

        return $chunks;
    }
}
