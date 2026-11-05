<?php

namespace App\Console\Commands;

use App\Search\SearchIndexer;
use App\Search\SourceRegistry;
use Illuminate\Console\Command;

class IngestSource extends Command
{
    /**
     * php artisan ingest:source                     every enabled outlet
     * php artisan ingest:source ntv --max-posts=100
     * php artisan ingest:source kfm --since=2026-01-01
     */
    protected $signature = 'ingest:source {sources?* : Outlet keys from config/researcher.php (default: all enabled)}
        {--max-posts= : Stop after this many stories per outlet — use for a sample run before a full crawl}
        {--since= : Only stories published after this date (YYYY-MM-DD)}';

    protected $description = 'Read, chunk, embed and index outlets\' stories for the Researcher Portal';

    public function handle(SourceRegistry $registry, SearchIndexer $indexer): int
    {
        $keys = $this->argument('sources') ?: $registry->enabled();
        $max = $this->option('max-posts') ? (int) $this->option('max-posts') : null;
        $failed = false;

        foreach ($keys as $key) {
            try {
                $source = $registry->make($key);
                $this->info("Indexing {$key}" . ($max ? " (newest {$max})" : '') . '…');

                $bar = $this->output->createProgressBar();
                $bar->setFormat('%current% stories read');
                $stats = $indexer->run($source, $max, $this->option('since'), fn ($s) => $bar->setProgress($s['documents_seen']));
                $bar->finish();
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                $failed = true;
                continue;
            }

            $this->newLine(2);
            $this->table(
                ['Stories read', 'New or changed', 'Passages embedded', 'Errors'],
                [[$stats['documents_seen'], $stats['documents_changed'], $stats['chunks_embedded'], count($stats['errors'])]]
            );
            foreach ($stats['errors'] as $error) {
                $this->warn(" - {$error}");
            }
            $failed = $failed || $stats['errors'];
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
