<?php

namespace App\Support;

use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Failed-job provider for the repository's UUID-keyed failed_jobs table.
 *
 * V001 defines both id and uuid as required UUID columns. Laravel's stock
 * database-uuids provider supplies only the logical uuid column, because its
 * normal schema gives id an auto-increment default. Supplying a physical UUID
 * keeps the standard failed-job contract without changing the existing schema.
 */
final class UuidDatabaseFailedJobProvider extends DatabaseUuidFailedJobProvider
{
    /**
     * @param  string  $connection
     * @param  string  $queue
     * @param  string  $payload
     * @param  \Throwable|string  $exception
     */
    public function log($connection, $queue, $payload, $exception)
    {
        $decoded = json_decode($payload, true);
        $uuid = is_array($decoded) ? ($decoded['uuid'] ?? null) : null;
        if (! is_string($uuid) || $uuid === '') {
            throw new RuntimeException('The failed job payload does not contain a valid UUID.');
        }

        $this->getTable()->insert([
            'id' => (string) Str::uuid7(),
            'uuid' => $uuid,
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $payload,
            'exception' => (string) mb_convert_encoding($exception, 'UTF-8'),
            'failed_at' => Date::now(),
        ]);

        return $uuid;
    }
}
