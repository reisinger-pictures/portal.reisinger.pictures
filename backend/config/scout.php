<?php

return [
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

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY', 'local_meili_secret'),

        'index-settings' => [
            \App\Models\Photo::class => [
                'filterableAttributes' => ['gallery_id'],
            ],
            \App\Models\Gallery::class => [
                'filterableAttributes' => ['id'],
            ],
            \App\Models\Location::class => [
                // Die Suchreihenfolge (WICHTIG! PLZ und Name zuerst, ID wird ignoriert)
                'searchableAttributes' => ['postal_code', 'name', 'state', 'country'],
                'filterableAttributes' => ['type'],
                'sortableAttributes' => ['population', 'postal_code'],
            ],
            \App\Models\Customer::class => [
                'searchableAttributes' => ['name', 'company', 'email', 'zip', 'city', 'street', 'country', 'uid'],
                'filterableAttributes' => ['id'],
                'sortableAttributes' => ['created_at'],
            ],
            \App\Models\TextSnippet::class => [
                'searchableAttributes' => ['title', 'shortcut', 'content_html'],
                'filterableAttributes' => ['id'],
                'sortableAttributes' => ['created_at'],
            ],
        ],
    ],
];
