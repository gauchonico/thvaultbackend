<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One passage in the Researcher Portal's search index. Lives on the search
 * connection (Postgres + pgvector in production), not the app's MySQL.
 */
class SearchableChunk extends Model
{
    protected $table = 'search_chunks';

    protected $fillable = [
        'outlet', 'source_type', 'document_id', 'external_id', 'chunk_index',
        'source_title', 'source_url', 'source_ref', 'chunk_content', 'content_hash',
        'access_level', 'published_at', 'metadata', 'embedding',
    ];

    // pgvector reads and writes vectors as "[0.1,0.2,…]", which is also JSON, so
    // the array cast works on Postgres and on the JSON column used in tests.
    protected $casts = [
        'embedding'    => 'array',
        'source_ref'   => 'array',
        'metadata'     => 'array',
        'published_at' => 'datetime',
    ];

    protected $hidden = ['embedding'];

    public function getConnectionName(): ?string
    {
        return config('database.search_connection');
    }

    /**
     * Cosine similarity between this chunk's embedding and a query vector.
     * Both vectors are unit-normalized, so this reduces to a dot product.
     * Only used where the database can't compare vectors itself.
     */
    public function similarityTo(array $queryVector): float
    {
        if (!$this->embedding || count($this->embedding) !== count($queryVector)) {
            return 0.0;
        }

        $dot = 0.0;
        foreach ($this->embedding as $i => $value) {
            $dot += $value * $queryVector[$i];
        }

        return $dot;
    }
}
