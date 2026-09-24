<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;
use RuntimeException;

class E2ELocationSeeder extends Seeder
{
    /**
     * Mutable columns for an idempotent fixture upsert. IDs stay stable so the
     * checked-in fixture can safely be rerun against a fresh E2E database.
     */
    private const MUTABLE_COLUMNS = [
        'type',
        'name',
        'state',
        'country',
        'iso_country',
        'postal_code',
        'population',
    ];

    public function run(): void
    {
        $path = database_path('fixtures/e2e-locations.json');
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("E2E location fixture is missing: {$path}");
        }

        try {
            $locations = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("E2E location fixture is invalid JSON: {$path}", previous: $exception);
        }

        if (! is_array($locations) || ! array_is_list($locations) || $locations === []) {
            throw new RuntimeException("E2E location fixture must be a non-empty JSON list: {$path}");
        }

        $validator = Validator::make(['locations' => $locations], [
            'locations.*' => ['array'],
            'locations.*.id' => ['required', 'uuid', 'distinct'],
            'locations.*.type' => ['required', 'in:city,country'],
            'locations.*.name' => ['required', 'string', 'max:255'],
            'locations.*.state' => ['nullable', 'string', 'max:255'],
            'locations.*.country' => ['nullable', 'string', 'max:255'],
            'locations.*.iso_country' => ['required', 'string', 'size:2'],
            'locations.*.postal_code' => ['nullable', 'string', 'max:20'],
            'locations.*.population' => ['required', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw new RuntimeException("E2E location fixture failed validation: {$validator->errors()->toJson()}");
        }

        DB::table('locations')->upsert(
            $locations,
            ['id'],
            self::MUTABLE_COLUMNS,
        );
    }
}
