<?php

namespace App\Support;

use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Str;

/**
 * Database queue writer for the repository's UUID-keyed jobs table.
 *
 * Laravel's stock DatabaseQueue expects an auto-increment id, while the
 * repository's V001 jobs table uses a UUID primary key. The fallback writer
 * supplies that key explicitly so a dispatch failure can still be persisted
 * without changing the historical schema.
 */
final class UuidDatabaseQueue extends DatabaseQueue
{
    /**
     * Add the physical UUID key to every database-queue record.
     *
     * Laravel's bulk path also uses buildDatabaseRecord(), so keeping the key
     * there makes this writer compatible with push(), later(), bulk(), and
     * release() rather than only the fallback push path.
     *
     * @param  string|\UnitEnum|null  $queue
     * @param  string  $payload
     * @param  int  $availableAt
     * @param  int  $attempts
     * @return array<string, mixed>
     */
    protected function buildDatabaseRecord($queue, $payload, $availableAt, $attempts = 0)
    {
        $record = parent::buildDatabaseRecord($queue, $payload, $availableAt, $attempts);
        $record['id'] = (string) Str::uuid7();

        return $record;
    }

    /**
     * Insert a standard database-queue row with an explicit UUID.
     *
     * @param  string|\UnitEnum|null  $queue
     * @param  string  $payload
     * @param  int  $delay
     * @param  int  $attempts
     */
    protected function pushToDatabase($queue, $payload, $delay = 0, $attempts = 0)
    {
        $record = $this->buildDatabaseRecord(
            $this->getQueue($queue),
            $payload,
            $this->availableAt($delay),
            $attempts,
        );

        $this->database->table($this->table)->insert($record);

        return $record['id'];
    }
}
