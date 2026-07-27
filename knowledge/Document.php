<?php

declare(strict_types=1);

namespace Hostorio\Knowledge;

/**
 * One knowledge item, before it is chunked.
 *
 * Both a synced WordPress article and a hand-typed note become this — which is
 * what "manual notes are a first-class source" means concretely: they take the
 * same path through chunking, embedding and retrieval, and differ only by
 * `priority`.
 */
final class Document
{
    public const SOURCE_WORDPRESS = 'wordpress';
    public const SOURCE_MANUAL    = 'manual';

    public function __construct(
        public readonly string $source,
        public readonly string $sourceRef,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $url = null,
        public readonly int $priority = 0,
        public readonly ?string $expiresAt = null,
    ) {
    }

    /**
     * The text actually indexed. The title is prepended because it usually
     * carries the strongest topical signal ("Resetting your cPanel password"),
     * and a chunk retrieved without it can read as orphaned prose.
     */
    public function indexableText(): string
    {
        return $this->title === '' ? $this->body : $this->title . "\n\n" . $this->body;
    }

    public function hash(TextNormalizer $normalizer): string
    {
        return $normalizer->hash($this->title, $this->body);
    }
}
