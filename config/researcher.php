<?php

/*
|--------------------------------------------------------------------------
| Researcher Portal Sources
|--------------------------------------------------------------------------
|
| Every NMG outlet that feeds the search index, keyed by a short slug used on
| the command line (php artisan ingest:source ntv). Each entry names a driver
| — the class that knows how to read that outlet — plus the outlet's display
| name, which is what researchers filter and cite by.
|
| Changing an outlet's name after ingesting would file its new posts under a
| second name, so pick it once.
|
*/

return [

    'sources' => [

        'ntv' => [
            'outlet'  => 'NTV Uganda',
            'driver'  => 'wordpress',
            'url'     => 'https://ntv.co.ug',
            'enabled' => true,
        ],

        'kfm' => [
            'outlet'  => 'KFM',
            'driver'  => 'wordpress',
            'url'     => 'https://www.kfm.co.ug',
            'enabled' => true,
        ],

        // Monitor runs on Core Media behind Cloudflare and a paywall, so it needs
        // access from Monitor's digital team (a content API key or a nightly export)
        // before it can be read. Its stories are indexed in full but shown to
        // researchers as excerpts linking to monitor.co.ug.
        'monitor' => [
            'outlet'       => 'Daily Monitor',
            'driver'       => 'coremedia',
            'url'          => env('MONITOR_FEED_URL'),
            'token'        => env('MONITOR_FEED_TOKEN'),
            'access_level' => 'subscribers',
            'enabled'      => (bool) env('MONITOR_FEED_URL'),
        ],

    ],

    // Daily refresh: how many of each outlet's newest stories to re-check.
    'daily_max' => 50,

];
