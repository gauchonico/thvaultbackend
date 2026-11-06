<?php

return [

    // A playback session counts as a view once it has played this long, the same
    // idea as YouTube's ~30 seconds. Shorter sessions still add to watch time.
    'view_seconds' => (int) env('ANALYTICS_VIEW_SECONDS', 30),

    // Longest a single session may run (a live channel left playing overnight).
    'max_session_seconds' => 6 * 3600,

    // Heartbeats may claim at most the real time since the last one plus this
    // allowance, so a tampered client can't inflate watch time.
    'heartbeat_slack_seconds' => 15,

];
