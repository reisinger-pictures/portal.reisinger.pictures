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
                // Explizite searchableAttributes: verhindert, dass Meilisearch die Reihenfolge
                // nicht-deterministisch ableitet (IDs/UUIDs dominieren sonst). Textfelder zuerst.
                'searchableAttributes' => [
                    'title', 'keywords', 'headline', 'description', 'artist',
                    'city', 'location', 'state', 'country',
                ],
                'filterableAttributes' => ['gallery_id', 'is_hidden'],
                // Typo-Toleranz explizit aktivieren + Feinjustierung (Default: oneTypo 5 / twoTypos 9).
                // Mit oneTypo=4 reicht bereits ein 4-Zeichen-Wort für 1 Tippfehler-Korrektur.
                'typoTolerance' => [
                    'enabled' => true,
                    'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                ],
            ],
            \App\Models\Gallery::class => [
                'searchableAttributes' => ['name'],
                'filterableAttributes' => ['id', 'is_hidden'],
                'typoTolerance' => [
                    'enabled' => true,
                    'minWordSizeForTypos' => ['oneTypo' => 4, 'twoTypos' => 8],
                ],
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
