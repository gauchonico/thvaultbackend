<?php

namespace App\Console\Commands;

use App\Models\Episode;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class BackfillEpisodeAiredDates extends Command
{
    /**
     * php artisan episodes:backfill-aired-dates --dry-run
     * php artisan episodes:backfill-aired-dates --years=3
     */
    protected $signature = 'episodes:backfill-aired-dates
        {--years=3 : How far back the random dates may go}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Give episodes with no aired_at a plausible random air date (for sample/demo data). Never touches episodes that already have one.';

    public function handle(): int
    {
        $years = max(1, (int) $this->option('years'));
        $dryRun = (bool) $this->option('dry-run');
        $today = Carbon::today();
        $updated = 0;

        // Per show, walk episodes in broadcast order from a random start date, roughly weekly,
        // so the dates look like a real run (S1E1 before S1E2) and spread across months/years.
        $byShow = Episode::whereNull('aired_at')
            ->orderBy('season')->orderBy('episode_number')->orderBy('id')
            ->get()
            ->groupBy('show_id');

        foreach ($byShow as $showId => $episodes) {
            $spanDays = $episodes->count() * 7;
            $earliest = $today->copy()->subYears($years);
            $latestStart = $today->copy()->subDays($spanDays);
            $start = $latestStart->lessThanOrEqualTo($earliest)
                ? $earliest
                : Carbon::createFromTimestamp(random_int($earliest->timestamp, $latestStart->timestamp))->startOfDay();

            $date = $start;
            foreach ($episodes as $episode) {
                $airedAt = $date->greaterThan($today) ? $today->copy() : $date->copy();

                if ($dryRun) {
                    $this->line("show {$showId} · S{$episode->season}E{$episode->episode_number} \"{$episode->title}\" → {$airedAt->toDateString()}");
                } else {
                    // Direct query so updated_at and model events are left alone.
                    Episode::whereKey($episode->id)->update(['aired_at' => $airedAt->toDateString()]);
                }

                $updated++;
                $date = $date->copy()->addDays(random_int(5, 9));
            }
        }

        $this->info(($dryRun ? 'Would update' : 'Updated') . " {$updated} episode(s).");
        return self::SUCCESS;
    }
}
