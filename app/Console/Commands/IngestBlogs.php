<?php

namespace App\Console\Commands;

use App\Services\WordPressIngestionService;
use Illuminate\Console\Command;

class IngestBlogs extends Command
{
    /**
     * php artisan ingest:blogs "NTV Uganda" https://ntv.co.ug --max-posts=100
     * php artisan ingest:blogs KFM https://kfm.co.ug --since=2026-01-01
     */
    protected $signature = 'ingest:blogs {brand} {domain}
        {--max-posts= : Stop after this many posts total — use for a sample run before a full crawl}
        {--since= : Only ingest posts published after this date (YYYY-MM-DD) — for incremental catch-up runs}';

    protected $description = 'Fetch, chunk, embed, and store posts from a WordPress site\'s REST API for the Researcher Portal';

    public function handle(WordPressIngestionService $service): int
    {
        $brand = $this->argument('brand');
        $domain = $this->argument('domain');
        $maxPosts = $this->option('max-posts') ? (int) $this->option('max-posts') : null;
        $since = $this->option('since');

        $this->info("Ingesting \"{$brand}\" from {$domain}" . ($maxPosts ? " (sample of {$maxPosts} posts)" : '') . '...');
        $bar = null;

        try {
            $stats = $service->ingest($brand, $domain, function (array $stats) use (&$bar) {
                if (!$bar) {
                    $bar = $this->output->createProgressBar();
                    $bar->setFormat('%current% posts processed');
                }
                $bar->setProgress($stats['posts_seen']);
            }, $maxPosts, $since);
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $bar?->finish();
        $this->newLine(2);

        $this->table(
            ['Posts seen', 'Posts ingested', 'Chunks upserted', 'Errors'],
            [[$stats['posts_seen'], $stats['posts_ingested'], $stats['chunks_upserted'], count($stats['errors'])]]
        );

        foreach ($stats['errors'] as $error) {
            $this->warn(" - {$error}");
        }

        return empty($stats['errors']) ? self::SUCCESS : self::FAILURE;
    }
}
