<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchableChunk extends Model
{
    protected $fillable = [
        'brand_name', 'source_title', 'source_url', 'external_id',
        'chunk_index', 'chunk_content', 'embedding', 'published_at', 'metadata',
    ];

    protected $casts = [
        'embedding'    => 'array',
        'metadata'     => 'array',
        'published_at' => 'datetime',
    ];

    /**
     * Cosine similarity between this chunk's embedding and a query vector.
     * Both vectors are assumed to already be unit-normalized (OpenAI's
     * embeddings are), so this reduces to a plain dot product.
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
