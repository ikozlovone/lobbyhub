<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ranking points
    |--------------------------------------------------------------------------
    |
    | What a server's position in the top is made of. Kept in config rather than
    | in code because these weights are a product decision that will be argued
    | about and retuned, and every change is one recompute away from taking
    | effect.
    |
    | Only recent votes count: a server that was popular last year should not
    | outrank one that is popular now.
    |
    */

    /** Points per vote received inside the window below. */
    'vote_points' => (int) env('RANKING_VOTE_POINTS', 10),

    'vote_window_days' => (int) env('RANKING_VOTE_WINDOW', 30),

    /** Points per average concurrent player over the last week. */
    'player_points' => (float) env('RANKING_PLAYER_POINTS', 2),

    /** Full marks for a server that never went down; scaled by uptime. */
    'uptime_points' => (float) env('RANKING_UPTIME_POINTS', 100),

    /** A paid placement's equivalent of the competitor's "boost". */
    'promoted_points' => (int) env('RANKING_PROMOTED_POINTS', 2000),

];
