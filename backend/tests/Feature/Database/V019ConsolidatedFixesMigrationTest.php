<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * V019 adds coupon columns with MariaDB `CHANGE`/`AFTER` DDL. A PostgreSQL
 * replay must take a portable path instead, and the rename must keep the
 * stored limit rather than drop and recreate the column.
 *
 * A fresh database already carries these columns from V018, so the guarded
 * MariaDB statements only run on a database that predates them. The test
 * therefore rebuilds the V018 schema in an isolated PostgreSQL schema,
 * reconstructs the pre-V019 coupon shape and executes V019 itself.
 */
class V019ConsolidatedFixesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private string $schema = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('V019 carries a MariaDB fallback that only PostgreSQL can exercise here; PostgreSQL is the canonical test database.');
        }

        $this->schema = 'v019_replay_'.str_replace('-', '', (string) Str::uuid());
        DB::statement('CREATE SCHEMA '.$this->quote($this->schema));
        DB::statement('SET search_path TO '.$this->quote($this->schema));
    }

    protected function tearDown(): void
    {
        try {
            DB::statement('SET search_path TO public');

            if ($this->schema !== '') {
                DB::statement('DROP SCHEMA IF EXISTS '.$this->quote($this->schema).' CASCADE');
            }
        } catch (Throwable) {
            // The surrounding RefreshDatabase transaction rolls the isolated
            // schema back even when the explicit drop is not possible.
        } finally {
            parent::tearDown();
        }
    }

    public function test_v019_renames_and_adds_coupon_columns_on_postgresql(): void
    {
        $this->migrateThroughV018();

        // Shape the table the way V019 found it before V018 had created the
        // newer columns: `max_uses` still exists under its old name, and the
        // two columns V019 introduces are absent.
        DB::statement('ALTER TABLE coupons DROP COLUMN max_uses_per_account');
        DB::statement('ALTER TABLE coupons DROP COLUMN created_by');
        DB::statement('ALTER TABLE coupons RENAME COLUMN max_uses_global TO max_uses');

        $this->assertTrue(Schema::hasColumn('coupons', 'max_uses'));
        $this->assertFalse(Schema::hasColumn('coupons', 'max_uses_global'));
        $this->assertFalse(Schema::hasColumn('coupons', 'max_uses_per_account'));
        $this->assertFalse(Schema::hasColumn('coupons', 'created_by'));

        $couponId = DB::table('coupons')->insertGetId([
            'brand' => 'rp',
            'code' => 'V019-'.Str::upper(Str::random(8)),
            'type' => 'fixed',
            'value' => 500,
            'max_uses' => 7,
            'used_count' => 0,
            'active' => true,
        ]);

        $migration = require database_path('migrations/V019__consolidated_fixes.php');
        $migration->up();

        $columns = collect(Schema::getColumns('coupons'))->keyBy('name');

        $this->assertFalse(Schema::hasColumn('coupons', 'max_uses'));
        $this->assertTrue(Schema::hasColumn('coupons', 'max_uses_global'));
        $this->assertSame('integer', $columns['max_uses_global']['type']);
        $this->assertTrue((bool) $columns['max_uses_global']['nullable']);

        // The rename keeps the stored limit; it does not drop and recreate it.
        $this->assertSame(7, (int) DB::table('coupons')->where('id', $couponId)->value('max_uses_global'));

        $this->assertSame('integer', $columns['max_uses_per_account']['type']);
        $this->assertTrue((bool) $columns['max_uses_per_account']['nullable']);
        $this->assertNull($columns['max_uses_per_account']['default']);

        $this->assertSame('character(36)', $columns['created_by']['type']);
        $this->assertTrue((bool) $columns['created_by']['nullable']);
        $this->assertNull($columns['created_by']['default']);

        // V019 restores the settings primary key; the replay must leave it intact.
        $this->assertTrue(Schema::hasIndex('settings', ['key', 'brand'], 'primary'));

        // A second run on the repaired shape must not try to add the columns
        // again, and must not disturb the stored limit.
        $migration->up();

        $this->assertSame(7, (int) DB::table('coupons')->where('id', $couponId)->value('max_uses_global'));
        $this->assertTrue(Schema::hasColumn('coupons', 'max_uses_per_account'));
        $this->assertTrue(Schema::hasColumn('coupons', 'created_by'));
    }

    /**
     * Rebuild the schema V019 ran against, without applying V019.
     */
    private function migrateThroughV018(): void
    {
        $files = glob(database_path('migrations/V*.php'));
        if ($files === false) {
            $this->fail('Unable to list the migration files.');
        }

        sort($files);

        foreach ($files as $file) {
            if (! preg_match('#/V(\d{3})__#', $file, $matches) || (int) $matches[1] > 18) {
                continue;
            }

            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('coupons'));
        $this->assertTrue(Schema::hasTable('tenants'));
    }

    private function quote(string $identifier): string
    {
        return DB::connection()->getQueryGrammar()->wrapTable($identifier);
    }
}
