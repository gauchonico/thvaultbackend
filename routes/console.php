<?php

use App\Search\SourceRegistry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Researcher Portal: every morning, re-check each enabled outlet's newest stories
// (config/researcher.php). Unchanged stories cost nothing; new ones are embedded.
foreach (app(SourceRegistry::class)->enabled() as $source) {
    Schedule::command('ingest:source', [$source, '--max-posts=' . config('researcher.daily_max')])
        ->dailyAt('05:00')
        ->timezone('Africa/Kampala')
        ->withoutOverlapping()
        ->name("ingest-source:{$source}");
}
