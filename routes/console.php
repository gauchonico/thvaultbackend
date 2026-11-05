<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Researcher Portal: every morning, pull each brand's newest posts (WordPress returns
// newest first). Posts already ingested are updated in place, never duplicated.
// Daily Monitor isn't here: it runs on a paywalled non-WordPress CMS with no public feed.
// The brand names must match earlier manual runs, or posts get stored twice.
$researcherSources = [
    'NTV Uganda' => 'https://ntv.co.ug',
    'KFM'        => 'https://www.kfm.co.ug',
];

foreach ($researcherSources as $brand => $domain) {
    Schedule::command('ingest:blogs', [$brand, $domain, '--max-posts=50'])
        ->dailyAt('05:00')
        ->timezone('Africa/Kampala')
        ->withoutOverlapping()
        ->name("ingest-blogs:{$brand}");
}
