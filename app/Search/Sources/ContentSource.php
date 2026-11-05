<?php

namespace App\Search\Sources;

use App\Search\SourceDocument;

/**
 * Reads one outlet's content. Adding an outlet (Monitor, ePapers, broadcast
 * transcripts) means writing one of these and registering its driver in
 * SourceRegistry; the index, search and citations need no changes.
 */
interface ContentSource
{
    /**
     * Newest first. Throws if the outlet can't be read; documents already
     * yielded are kept.
     *
     * @param int|null $max Stop after this many documents.
     * @param string|null $since Only documents published after this date (YYYY-MM-DD).
     * @return iterable<SourceDocument>
     */
    public function documents(?int $max = null, ?string $since = null): iterable;
}
