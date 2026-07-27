<?php

declare(strict_types=1);

namespace Hostorio\Llm;

use Hostorio\Core\Config;

/**
 * Decides what kind of question the customer asked, so the router can pick a
 * proportionate model.
 *
 * Rule-based on purpose. Classifying with an LLM would add a billed round trip
 * to every message — the exact cost this routing exists to avoid — and would
 * make the routing decision itself non-deterministic and hard to audit.
 *
 * The trade-off is accepted deliberately: keyword matching mis-classifies some
 * questions. The failure is cheap in one direction (a simple question sent to
 * an expensive model costs a fraction of a cent) and guarded in the other —
 * anything resembling an account action is caught first and sent to a
 * tool-capable provider.
 */
final class QueryClassifier
{
    public function classify(string $message): Classification
    {
        $normalized = $this->normalize($message);

        if ($normalized === '') {
            return new Classification(
                Classification::SIMPLE,
                'empty message; defaulted'
            );
        }

        // Order matters: account actions are checked first because getting one
        // wrong is the most expensive mistake available.
        $actionMatch = $this->firstMatch($normalized, $this->keywords('action'));

        if ($actionMatch !== null) {
            return new Classification(
                Classification::ACTION,
                sprintf('matched action phrase "%s"', $actionMatch),
                true
            );
        }

        $complexMatch = $this->firstMatch($normalized, $this->keywords('complex'));

        if ($complexMatch !== null) {
            return new Classification(
                Classification::COMPLEX,
                sprintf('matched troubleshooting phrase "%s"', $complexMatch)
            );
        }

        $threshold = (int) Config::get('routing.complex_length_threshold', 320);

        if ($threshold > 0 && mb_strlen($normalized, 'UTF-8') > $threshold) {
            return new Classification(
                Classification::COMPLEX,
                sprintf('message longer than %d characters', $threshold)
            );
        }

        $default = (string) Config::get('routing.default_type', Classification::SIMPLE);

        return new Classification(
            Classification::isValidType($default) ? $default : Classification::SIMPLE,
            'no signals matched; used default'
        );
    }

    /**
     * Lower-case and collapse whitespace so multi-word phrases match regardless
     * of how the customer formatted their message. Curly apostrophes are
     * folded to straight ones — people paste from Word and phone keyboards
     * substitute them automatically, and "doesn't" should match either way.
     */
    private function normalize(string $message): string
    {
        $lower = mb_strtolower(trim($message), 'UTF-8');
        $lower = str_replace(["\u{2019}", "\u{2018}", "\u{02BC}"], "'", $lower);

        return preg_replace('/\s+/u', ' ', $lower) ?? $lower;
    }

    /**
     * Return the first keyword found, or null.
     *
     * Single words match on a word boundary so "download" does not trigger on
     * "down"; multi-word phrases match as substrings, since their length makes
     * an accidental hit unlikely.
     *
     * @param array<int, string> $keywords
     */
    private function firstMatch(string $haystack, array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            $needle = mb_strtolower(trim($keyword), 'UTF-8');

            if ($needle === '') {
                continue;
            }

            if (str_contains($needle, ' ')) {
                if (str_contains($haystack, $needle)) {
                    return $needle;
                }

                continue;
            }

            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $haystack) === 1) {
                return $needle;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $type): array
    {
        $keywords = Config::get('routing.keywords.' . $type, []);

        return is_array($keywords) ? array_values(array_filter($keywords, 'is_string')) : [];
    }
}
