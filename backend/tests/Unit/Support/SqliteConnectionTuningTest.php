<?php

namespace Tests\Unit\Support;

use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/**
 * Guards the SQLite connection tuning that keeps a file-backed run from dying
 * with "database is locked".
 *
 * The regression is not a timing property, so these tests need neither a fork
 * nor a sleep: the pragma readback proves the connector applies the values, and
 * the BEGIN IMMEDIATE probe proves the transaction mode actually takes the write
 * lock. Both run against a real file database, not the `:memory:` test database
 * where the pragmas are silently a no-op.
 */
class SqliteConnectionTuningTest extends TestCase
{
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();

        $database = tempnam(sys_get_temp_dir(), 'portal-sqlite-tuning-');
        if ($database === false) {
            $this->fail('Unable to create a temporary SQLite database.');
        }

        $this->database = $database;
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite_tuning_immediate');
        DB::purge('sqlite_tuning_deferred');

        foreach ([$this->database, $this->database.'-wal', $this->database.'-shm'] as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function test_sqlite_tuning_defaults_are_explicit_and_not_null(): void
    {
        $sqlite = config('database.connections.sqlite');

        // The historical defect: all three were null, so Laravel skipped the
        // pragma and left PHP's implicit behaviour in place.
        $this->assertNotNull($sqlite['busy_timeout'], 'busy_timeout must be explicit');
        $this->assertNotNull($sqlite['journal_mode'], 'journal_mode must be explicit');
        $this->assertNotNull($sqlite['synchronous'], 'synchronous must be explicit');

        // WAL lets readers and one writer coexist; IMMEDIATE removes the
        // read->write lock upgrade that failed under contention.
        $this->assertSame('WAL', strtoupper((string) $sqlite['journal_mode']));
        $this->assertSame('IMMEDIATE', $sqlite['transaction_mode']);
    }

    public function test_connector_applies_the_configured_pragmas_to_a_file_connection(): void
    {
        $config = config('database.connections.sqlite');
        $config['database'] = $this->database;

        $pdo = (new SQLiteConnector)->connect($config);

        $this->assertSame(
            (int) $config['busy_timeout'],
            (int) $pdo->query('pragma busy_timeout')->fetchColumn(),
        );
        $this->assertSame(
            strtolower((string) $config['journal_mode']),
            strtolower((string) $pdo->query('pragma journal_mode')->fetchColumn()),
        );
        // NORMAL is synchronous level 1.
        $this->assertSame(1, (int) $pdo->query('pragma synchronous')->fetchColumn());
    }

    public function test_immediate_transaction_mode_holds_the_write_lock_at_begin(): void
    {
        $config = array_merge(config('database.connections.sqlite'), [
            'database' => $this->database,
            'busy_timeout' => 50,
        ]);

        config([
            'database.connections.sqlite_tuning_immediate' => array_merge($config, ['transaction_mode' => 'IMMEDIATE']),
            'database.connections.sqlite_tuning_deferred' => array_merge($config, ['transaction_mode' => 'DEFERRED']),
        ]);

        // DEFERRED: a read-only transaction holds no write lock, so a competing
        // BEGIN IMMEDIATE still succeeds.
        DB::purge('sqlite_tuning_deferred');
        $deferred = DB::connection('sqlite_tuning_deferred');
        $deferred->beginTransaction();
        $deferred->select('select count(*) from sqlite_master');

        $probe = $this->openProbe();
        $probe->exec('BEGIN IMMEDIATE');
        $probe->exec('ROLLBACK');
        $deferred->rollBack();

        // IMMEDIATE: the write lock is taken at BEGIN, so the same probe is
        // rejected at once instead of failing later during a lock upgrade.
        DB::purge('sqlite_tuning_immediate');
        $immediate = DB::connection('sqlite_tuning_immediate');
        $immediate->beginTransaction();
        $immediate->select('select count(*) from sqlite_master');

        $probe = $this->openProbe();

        $this->expectExceptionMessage('database is locked');

        try {
            $probe->exec('BEGIN IMMEDIATE');
        } finally {
            $immediate->rollBack();
        }
    }

    private function openProbe(): PDO
    {
        $pdo = new PDO('sqlite:'.$this->database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // Fail fast instead of waiting, so the test asserts lock acquisition and
        // not the busy timeout.
        $pdo->exec('PRAGMA busy_timeout = 1');

        return $pdo;
    }
}
