<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give `photographer_gallery_groups` a stable surrogate row identity.
 *
 * V005 created a composite primary key over `(user_id, gallery_group_id)`.
 * V008 had to drop it: `gallery_group_id` becomes nullable with ON DELETE SET
 * NULL, and the remaining unique key over the same pair is not a row identity,
 * because SQL permits any number of rows with the same user and a NULL group.
 * The PostgreSQL cutover copy chunks each table by primary key, so this table
 * cannot be copied safely without a real key.
 *
 * V058 adds an auto-generated bigint `id` primary key on MariaDB, PostgreSQL
 * and SQLite. Every existing row receives a distinct generated value; no
 * assignment is deleted, merged or rewritten. The nullable group column, both
 * foreign keys and the composite unique index stay exactly as V008 left them.
 * The application pivot relation ignores the surrogate, and the copy carries
 * it verbatim and resets the PostgreSQL sequence afterwards.
 *
 * Schema/backfill/rollback decision: additive column and primary key only.
 * `down()` stays empty because down() methods are never executed in this
 * repository; abandoning the cutover leaves the added ID on the MariaDB source
 * without a row rewrite.
 */
return new class extends Migration
{
    private const TABLE = 'photographer_gallery_groups';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('V058 requires table ['.self::TABLE.'].');
        }

        foreach (['user_id', 'gallery_group_id'] as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                throw new RuntimeException('V058 requires column ['.self::TABLE.'.'.$column.'].');
            }
        }

        $primary = collect(Schema::getIndexes(self::TABLE))
            ->first(static fn (array $index): bool => (bool) ($index['primary'] ?? false));

        if (Schema::hasColumn(self::TABLE, 'id')) {
            $this->assertExistingIdIsGeneratedPrimaryKey($primary);

            return;
        }

        if ($primary !== null) {
            throw new RuntimeException(
                'V058 cannot add a surrogate primary key because ['.self::TABLE
                .'] already has a primary key over ['.implode(', ', $this->indexColumns($primary)).'].'
            );
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->addSqlitePrimaryKey();

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException('V058 does not support database driver ['.$driver.'].');
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->bigIncrements('id')->first();
        });
    }

    public function down(): void
    {
        // down() is never executed in this repository.
    }

    /**
     * SQLite cannot add a primary-key column in place, so rebuild the table
     * with the same nullable group behavior and recreate the composite unique
     * index after the rename under its canonical name.
     */
    private function addSqlitePrimaryKey(): void
    {
        $temporary = self::TABLE.'_v058_tmp';

        Schema::create($temporary, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('gallery_group_id')->nullable()->constrained('gallery_groups')->onDelete('set null');
        });

        DB::table($temporary)->insertUsing(
            ['user_id', 'gallery_group_id'],
            DB::table(self::TABLE)->select('user_id', 'gallery_group_id'),
        );

        Schema::drop(self::TABLE);
        Schema::rename($temporary, self::TABLE);

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique(['user_id', 'gallery_group_id']);
        });
    }

    /**
     * A replayed migration may only find the shape V058 itself creates.
     */
    private function assertExistingIdIsGeneratedPrimaryKey(?array $primary): void
    {
        $id = collect(Schema::getColumns(self::TABLE))->firstWhere('name', 'id');

        if ($id === null) {
            throw new RuntimeException('V058 could not inspect ['.self::TABLE.'.id].');
        }

        $primaryColumns = $primary === null ? [] : $this->indexColumns($primary);

        if ($primaryColumns !== ['id']) {
            throw new RuntimeException(
                'V058 found ['.self::TABLE.'.id] but it is not the primary key (current primary key: ['
                .implode(', ', $primaryColumns).']); refusing to continue.'
            );
        }

        if (! (bool) ($id['auto_increment'] ?? false)) {
            throw new RuntimeException(
                'V058 found ['.self::TABLE.'.id] but it is not auto-generated; refusing to continue.'
            );
        }

        $type = strtolower((string) ($id['type'] ?? ''));
        $expectedType = DB::connection()->getDriverName() === 'sqlite' ? 'integer' : 'bigint';

        if (! str_starts_with($type, $expectedType)) {
            throw new RuntimeException(
                'V058 found ['.self::TABLE.'.id] of type ['.$type.'] instead of ['
                .$expectedType.']; refusing to continue.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $index
     * @return list<string>
     */
    private function indexColumns(array $index): array
    {
        return array_map(
            static fn (mixed $column): string => strtolower(trim((string) $column)),
            (array) ($index['columns'] ?? []),
        );
    }
};
