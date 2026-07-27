<?php

declare(strict_types=1);

namespace Hostorio\Knowledge\Sources;

use Hostorio\Core\Config;
use Hostorio\Core\Logger;
use Hostorio\Database\WordPressDatabase;
use Hostorio\Knowledge\Document;
use Hostorio\Knowledge\TextNormalizer;

/**
 * Pulls published WordPress content in as knowledge.
 *
 * READ-ONLY throughout — the connection it uses rejects writes before they
 * reach MySQL (see Hostorio\Database\Connection).
 *
 * Post types are configurable because hosting companies keep their real
 * documentation in a custom type more often than in `post`: `kb_article`,
 * `docs`, `faq`. Defaulting to post+page and letting the admin add theirs
 * beats guessing.
 */
final class WordPressSource
{
    public function __construct(
        private readonly WordPressDatabase $wordpress,
        private readonly TextNormalizer $normalizer = new TextNormalizer(),
    ) {
    }

    public static function make(): self
    {
        return new self(WordPressDatabase::instance());
    }

    public function isAvailable(): bool
    {
        return $this->wordpress->isConfigured();
    }

    /**
     * Fetch publishable content as Documents.
     *
     * @return array<int, Document>
     */
    public function fetch(): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        /** @var array<int, string> $postTypes */
        $postTypes = (array) Config::get('knowledge.wordpress.post_types', ['post', 'page']);
        /** @var array<int, string> $statuses */
        $statuses  = (array) Config::get('knowledge.wordpress.statuses', ['publish']);
        $minChars  = (int) Config::get('knowledge.wordpress.min_body_chars', 200);
        $maxDocs   = (int) Config::get('knowledge.wordpress.max_documents', 5000);

        if ($postTypes === [] || $statuses === []) {
            return [];
        }

        [$typePlaceholders, $typeBindings]     = $this->placeholders($postTypes, 't');
        [$statusPlaceholders, $statusBindings] = $this->placeholders($statuses, 's');

        $rows = $this->wordpress->select(
            sprintf(
                'SELECT ID, post_title, post_content, post_excerpt, post_name, post_type, post_modified_gmt
                   FROM `%s`
                  WHERE post_type IN (%s)
                    AND post_status IN (%s)
                    AND post_password = %s
                  ORDER BY post_modified_gmt DESC
                  LIMIT %d',
                $this->wordpress->table('posts'),
                implode(', ', $typePlaceholders),
                implode(', ', $statusPlaceholders),
                "''",
                max(1, $maxDocs)
            ),
            $typeBindings + $statusBindings
        );

        $siteUrl   = $this->siteUrl();
        $documents = [];
        $skipped   = 0;

        foreach ($rows as $row) {
            $title = trim((string) ($row['post_title'] ?? ''));
            $body  = $this->normalizer->normalize((string) ($row['post_content'] ?? ''));

            // Fall back to the excerpt when the body is page-builder markup
            // that normalises down to nothing — common with Elementor and Divi.
            if ($body === '') {
                $body = $this->normalizer->normalize((string) ($row['post_excerpt'] ?? ''));
            }

            if (mb_strlen($body, 'UTF-8') < $minChars) {
                $skipped++;
                continue;
            }

            $documents[] = new Document(
                source: Document::SOURCE_WORDPRESS,
                sourceRef: (string) ($row['ID'] ?? ''),
                title: $title,
                body: $body,
                url: $this->permalink($siteUrl, (int) ($row['ID'] ?? 0)),
                priority: 0,
            );
        }

        Logger::info('WordPress content fetched', [
            'documents' => count($documents),
            'skipped_too_short' => $skipped,
            'post_types' => $postTypes,
        ]);

        return $documents;
    }

    /**
     * @param array<int, string> $values
     * @return array{0: array<int, string>, 1: array<string, string>}
     */
    private function placeholders(array $values, string $prefix): array
    {
        $placeholders = [];
        $bindings     = [];

        foreach (array_values($values) as $i => $value) {
            $placeholders[]            = ':' . $prefix . $i;
            $bindings[$prefix . $i]    = (string) $value;
        }

        return [$placeholders, $bindings];
    }

    /**
     * Build a link back to the article.
     *
     * Uses `?p=ID` rather than reconstructing the pretty permalink: permalink
     * structure is configurable, varies by post type, and is stored in a
     * serialised option. The `?p=` form always resolves, and WordPress
     * redirects it to the pretty URL.
     */
    private function permalink(string $siteUrl, int $postId): ?string
    {
        if ($siteUrl === '' || $postId <= 0) {
            return null;
        }

        return rtrim($siteUrl, '/') . '/?p=' . $postId;
    }

    private function siteUrl(): string
    {
        try {
            $row = $this->wordpress->selectOne(
                sprintf(
                    "SELECT option_value FROM `%s` WHERE option_name = 'home' LIMIT 1",
                    $this->wordpress->table('options')
                )
            );

            return trim((string) ($row['option_value'] ?? ''));
        } catch (\Throwable $e) {
            Logger::warning('Could not read WordPress home URL', ['error' => $e->getMessage()]);

            return '';
        }
    }
}
