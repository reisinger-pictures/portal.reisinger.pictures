<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    */
    'driver' => env('SCOUT_DRIVER', 'meilisearch'),

    'prefix' => env('SCOUT_PREFIX', ''),
    'queue' => env('SCOUT_QUEUE', false),
    'after_commit' => false,

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],
    'soft_delete' => false,
    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    */
    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        // Fallback passend zur docker-compose.local.yml gesetzt:
        'key' => env('MEILISEARCH_KEY', 'local_meili_secret'),

        // WICHTIG: Damit whereIn() in Scout funktioniert, müssen wir Meilisearch mitteilen,
        // welche Attribute filterbar sind.
        'index-settings' => [
            \App\Models\Photo::class => [
                'filterableAttributes' => ['gallery_id'],
            ],
            \App\Models\Gallery::class => [
                'filterableAttributes' => ['id'],
            ],
            \App\Models\Location::class => [
                'filterableAttributes' => ['type'],
            ],
        ],
    ],
];
