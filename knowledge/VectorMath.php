<?php

declare(strict_types=1);

namespace Hostorio\Knowledge;

/**
 * Vector packing and similarity.
 *
 * Embeddings are stored as packed little-endian float32 rather than JSON: a
 * 1536-dimension vector costs 6 KB packed against roughly 20 KB as JSON text,
 * which matters when the customer's whole hosting plan has a disk quota. The
 * precision lost going from PHP's float64 to float32 is far below the
 * threshold that affects ranking.
 */
final class VectorMath
{
    /**
     * @param array<int, float> $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...array_map('floatval', $vector));
    }

    /**
     * @return array<int, float>
     */
    public static function unpack(string $binary): array
    {
        if ($binary === '') {
            return [];
        }

        $values = unpack('g*', $binary);

        return $values === false ? [] : array_values($values);
    }

    /**
     * Cosine similarity, in [-1, 1]. Returns 0.0 for empty or mismatched input
     * rather than throwing — a single malformed row should down-rank itself,
     * not break the whole search.
     *
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    public static function cosine(array $a, array $b): float
    {
        $length = count($a);

        if ($length === 0 || $length !== count($b)) {
            return 0.0;
        }

        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $x = $a[$i];
            $y = $b[$i];

            $dot  += $x * $y;
            $magA += $x * $x;
            $magB += $y * $y;
        }

        if ($magA <= 0.0 || $magB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($magA) * sqrt($magB));
    }

    /**
     * Cosine similarity between an already-normalised query vector and a raw
     * one. Pre-normalising the query turns the per-chunk work into a dot
     * product plus one magnitude, which is measurably cheaper across a few
     * hundred candidates on shared hosting.
     *
     * @param array<int, float> $normalizedQuery
     * @param array<int, float> $candidate
     */
    public static function cosineWithNormalized(array $normalizedQuery, array $candidate): float
    {
        $length = count($normalizedQuery);

        if ($length === 0 || $length !== count($candidate)) {
            return 0.0;
        }

        $dot = 0.0;
        $magnitude = 0.0;

        for ($i = 0; $i < $length; $i++) {
            $dot       += $normalizedQuery[$i] * $candidate[$i];
            $magnitude += $candidate[$i] * $candidate[$i];
        }

        return $magnitude <= 0.0 ? 0.0 : $dot / sqrt($magnitude);
    }

    /**
     * Scale a vector to unit length.
     *
     * @param array<int, float> $vector
     * @return array<int, float>
     */
    public static function normalize(array $vector): array
    {
        $magnitude = 0.0;

        foreach ($vector as $value) {
            $magnitude += $value * $value;
        }

        if ($magnitude <= 0.0) {
            return $vector;
        }

        $magnitude = sqrt($magnitude);

        return array_map(static fn (float $v): float => $v / $magnitude, $vector);
    }
}
