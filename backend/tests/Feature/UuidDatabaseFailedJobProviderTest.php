<?php

namespace Tests\Feature;

use App\Support\UuidDatabaseFailedJobProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression: the failed-job provider threw when a queued payload had no
 * string `uuid`. Because it runs in the worker's failure path, the throw
 * prevented the `failed_jobs` insert and left the job in `jobs` to be retried
 * forever, past `tries`.
 */
class UuidDatabaseFailedJobProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid_less_payload_is_recorded_with_a_synthesized_uuid(): void
    {
        $provider = $this->provider();

        $uuid = $provider->log(
            'database',
            'default',
            json_encode(['job' => 'Illuminate\\Queue\\CallQueuedHandler@call'], JSON_THROW_ON_ERROR),
            new \RuntimeException('boom'),
        );

        $this->assertTrue(Str::isUuid($uuid));
        $this->assertDatabaseCount('failed_jobs', 1);
        $row = DB::table('failed_jobs')->first();
        $this->assertNotNull($row);
        $this->assertSame($uuid, $row->uuid);
        $this->assertTrue(Str::isUuid($row->id));
        $this->assertSame('database', $row->connection);
        $this->assertSame('default', $row->queue);
        $this->assertStringContainsString('boom', $row->exception);
    }

    public function test_payload_uuid_is_preserved_when_present(): void
    {
        $provider = $this->provider();
        $payloadUuid = (string) Str::uuid7();

        $uuid = $provider->log(
            'database',
            'default',
            json_encode(['uuid' => $payloadUuid, 'displayName' => 'Example'], JSON_THROW_ON_ERROR),
            new \RuntimeException('boom'),
        );

        $this->assertSame($payloadUuid, $uuid);
        $this->assertDatabaseHas('failed_jobs', [
            'uuid' => $payloadUuid,
        ]);
    }

    public function test_non_json_payload_is_recorded_with_a_synthesized_uuid(): void
    {
        $provider = $this->provider();

        $uuid = $provider->log('database', 'default', 'not-json', new \RuntimeException('boom'));

        $this->assertTrue(Str::isUuid($uuid));
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame($uuid, DB::table('failed_jobs')->value('uuid'));
    }

    private function provider(): UuidDatabaseFailedJobProvider
    {
        return new UuidDatabaseFailedJobProvider(
            app('db'),
            config('queue.failed.database'),
            config('queue.failed.table', 'failed_jobs'),
        );
    }
}
