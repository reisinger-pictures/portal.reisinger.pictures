<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Production Operations
    |--------------------------------------------------------------------------
    |
    | These values control the supervised database worker started by the
    | production container. The deployment policy command rejects missing or
    | unsafe values before workers and the scheduler are started.
    |
    */

    'queue_worker_timeout' => (int) env('QUEUE_WORKER_TIMEOUT', 60),

    'queue_worker_restart_delay' => (int) env('QUEUE_WORKER_RESTART_DELAY', 5),
];
