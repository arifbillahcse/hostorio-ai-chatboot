<?php

declare(strict_types=1);

use Hostorio\Core\Env;

/**
 * Knowledge base / RAG settings. Merged into Config under the `knowledge.` prefix.
 */
return [
    /*
     * Embeddings.
     *
     * Anthropic does not offer an embeddings endpoint, so semantic search needs
     * a key from a provider that does. Rather than force customers to sign up
     * for another API, the default driver is `none`: the knowledge base then
     * runs on MySQL full-text search alone, which handles a hosting FAQ
     * perfectly well. Add a key to upgrade retrieval to hybrid search — the
     * schema and indexer are identical either way.
     *
     * Drivers: none | openai
     */
    'embeddings' => [
        'driver' => Env::get('EMBEDDING_DRIVER', 'none'),

        'openai' => [
            'api_key'  => Env::get('EMBEDDING_API_KEY', Env::get('OPENAI_API_KEY', '')),
            'model'    => Env::get('EMBEDDING_MODEL', 'text-embedding-3-small'),
            'endpoint' => 'https://api.openai.com/v1/embeddings',
            // USD per 1M tokens. Verify against the provider's pricing page.
            'cost_per_million' => 0.02,
        ],

        // Chunks embedded per API call. Keeps request bodies small enough for
        // shared hosting's memory limits while staying cheap on round trips.
        'batch_size' => 32,
    ],

    /*
     * Chunking.
     *
     * Sized so a retrieved chunk is big enough to answer a question on its own
     * but small enough that six of them fit in a prompt without dominating the
     * bill. Overlap stops an answer being split across a boundary.
     */
    'chunk' => [
        'max_chars'     => 1200,
        'overlap_chars' => 150,
        // Below this, a trailing fragment is merged into the previous chunk
        // rather than stored as a uselessly small chunk of its own.
        'min_chars'     => 120,
    ],

    /*
     * Retrieval.
     *
     * Full-text search selects candidates, then vector similarity re-ranks them
     * when embeddings are available. That order matters: it avoids loading
     * every embedding in the table into PHP memory to score it.
     */
    'search' => [
        'candidates'     => 60,   // rows pulled from MySQL for re-ranking
        'results'        => 6,    // chunks handed to the model
        'lexical_weight' => 0.4,  // weights are used only in hybrid mode
        'vector_weight'  => 0.6,
        // A document's priority nudges its chunks up the ranking. Manual notes
        // default to priority 10 so a hand-written outage notice outranks a
        // year-old article on the same topic.
        'priority_weight' => 0.02,
    ],

    /*
     * WordPress ingestion.
     */
    'wordpress' => [
        'post_types'         => ['post', 'page'],
        'statuses'           => ['publish'],
        // Skip anything shorter than this after HTML stripping — usually
        // navigation stubs or placeholder pages, which only add noise.
        'min_body_chars'     => 200,
        'max_documents'      => 5000,
    ],

    /*
     * Manual notes default to this priority, above WordPress articles (0).
     */
    'manual_default_priority' => 10,
];
