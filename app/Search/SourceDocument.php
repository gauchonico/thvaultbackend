<?php

namespace App\Search;

/**
 * One story, ePaper page or broadcast, as read from an outlet and ready to be
 * split into passages and indexed. Every source produces these, so the indexer
 * never needs to know where a document came from.
 */
final class SourceDocument
{
    /**
     * @param string $id Stable across re-ingests, e.g. "ntv-uganda:48213".
     * @param string $text Plain text, no markup.
     * @param array<string, mixed>|null $ref Where a citation points when a URL isn't enough.
     * @param array<string, mixed> $metadata Author, categories, image…
     */
    public function __construct(
        public readonly string $id,
        public readonly string $outlet,
        public readonly string $title,
        public readonly string $text,
        public readonly ?string $url = null,
        public readonly ?string $publishedAt = null,
        public readonly string $sourceType = 'article',
        public readonly string $accessLevel = 'public',
        public readonly ?array $ref = null,
        public readonly array $metadata = [],
    ) {
    }
}
