<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Make `gallery_groups.slug` unique per `(brand, slug)` instead of globally.
 *
 * V001 enforces `UNIQUE (slug)` (`gallery_groups_slug_unique`), so two brands
 * cannot own the same group slug. The owner decision is per-brand uniqueness:
 * a slug is scoped to its brand. This migration is the schema half of that
 * decision; `SlugService::makeUnique()` is the application half and is
 * brand-scoped by the same change, because an application-level check that
 * still queried slug-only would disagree with the looser index and could hand
 * out a slug the index then rejects.
 *
 * Fail-closed preflight: a composite unique index can only be created when no
 * existing row violates it, and dropping the global unique is the loosening
 * half of the change. The migration therefore refuses to run when the current
 * rows already contain a duplicate `(brand, slug)`. It reports the offending
 * rows and aborts without editing, deleting, merging or re-slugging anything.
 * On a database that still carries the V001 global unique such a duplicate
 * cannot exist, so this is a guard against a partially migrated or manually
 * edited database, not an expected event.
 *
 * NULL brands: `brand` is nullable (V025) and is the historical legacy marker
 * for a pre-brand row. A NULL is not a comparable value in a unique index —
 * MySQL and SQLite both treat NULLs as distinct — so `(brand, slug)` does not
 * constrain rows whose brand is NULL. Those rows are left untouched here; the
 * migration never rewrites a brand (that would be a data change and could
 * re-introduce the tenant leak the seeder guards against). The documented
 * consequence is that a NULL-brand slug is no longer protected by any unique
 * index after this migration. Application writes never produce such a row
 * (`GalleryService` always assigns a brand) and the seeder repairs a legacy
 * NULL row by claiming it for a brand.
 *
 * `gallery_groups_brand_index` is dropped. `(brand, slug)` has `brand` as its
 * leftmost column, so MySQL's leftmost-prefix rule and SQLite's B-tree both
 * serve `WHERE brand = ?` from the composite index; a second index on the same
 * leading column only adds write cost.
 */
return new class extends Migration
{
    private const TABLE = 'gallery_groups';

    private const OLD_INDEX = 'gallery_groups_slug_unique';

    private const NEW_INDEX = 'gallery_groups_brand_slug_unique';

    private const BRAND_INDEX = 'gallery_groups_brand_index';

    private const MAX_REPORT_GROUPS = 20;

    private const MAX_REPORT_IDS = 20;

    public function up(): void
    {
        $this->assertSupportedDriver();

        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('V046 requires table ['.self::TABLE.'].');
        }

        // A completed replay is a no-op. A half-applied attempt (only one of
        // the two index states present) still runs the preflight before it is
        // repaired.
        if ($this->hasUniqueIndex(self::NEW_INDEX, ['brand', 'slug'])
            && ! Schema::hasIndex(self::TABLE, self::OLD_INDEX)) {
            return;
        }

        $duplicates = $this->findDuplicateGroups(['brand', 'slug']);
        if ($duplicates !== []) {
            $report = $this->duplicateReport($duplicates, 'brand, slug');
            Log::error('V046 gallery-group slug preflight failed', ['report' => $report]);

            throw new RuntimeException($report);
        }

        if (Schema::hasIndex(self::TABLE, self::OLD_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::OLD_INDEX);
            });
        }

        $this->ensureUniqueIndex(self::NEW_INDEX, ['brand', 'slug']);

        if (Schema::hasIndex(self::TABLE, self::BRAND_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::BRAND_INDEX);
            });
        }
    }

    /**
     * Reversing to the global unique is only possible while no slug is shared
     * by two brands: `UNIQUE (slug)` cannot be created otherwise. Refuse loudly
     * instead of letting the index creation fail with an opaque driver error,
     * and never drop a brand's group to make room.
     */
    public function down(): void
    {
        $this->assertSupportedDriver();

        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('V046 requires table ['.self::TABLE.'].');
        }

        $collisions = $this->findDuplicateGroups(['slug']);
        if ($collisions !== []) {
            $report = $this->duplicateReport($collisions, 'slug');
            Log::error('V046 gallery-group slug down() failed', ['report' => $report]);

            throw new RuntimeException($report);
        }

        if (Schema::hasIndex(self::TABLE, self::NEW_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::NEW_INDEX);
            });
        }

        $this->ensureUniqueIndex(self::OLD_INDEX, ['slug']);

        if (! Schema::hasIndex(self::TABLE, self::BRAND_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('brand', self::BRAND_INDEX);
            });
        }
    }

    private function assertSupportedDriver(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException('V046 does not support database driver ['.$driver.'].');
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasUniqueIndex(string $name, array $columns): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['name'] ?? null) !== $name) {
                continue;
            }

            return (bool) ($index['unique'] ?? false)
                && $this->indexColumns($index) === array_map('strtolower', $columns);
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureUniqueIndex(string $name, array $columns): void
    {
        if ($this->hasUniqueIndex($name, $columns)) {
            return;
        }

        // An interrupted attempt may have left an index with the right name but
        // the wrong shape. Replace it, but only after the preflight above.
        if (Schema::hasIndex(self::TABLE, $name)) {
            Schema::table(self::TABLE, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns, $name): void {
            $table->unique($columns, $name);
        });
    }

    /**
     * @param  array<string, mixed>  $index
     * @return list<string>
     */
    private function indexColumns(array $index): array
    {
        $columns = $index['columns'] ?? [];
        if (is_string($columns)) {
            $columns = explode(',', $columns);
        }

        return array_map(
            static fn (mixed $column): string => strtolower(trim((string) $column)),
            is_array($columns) ? $columns : [],
        );
    }

    /**
     * Group the table by the given columns and return only the groups with more
     * than one row.
     *
     * Values are lowercased and trimmed before grouping: MySQL/MariaDB's
     * default collation compares strings case-insensitively, so a preflight
     * that compared case-sensitively would predict an index creation that the
     * database then rejects. SQLite is stricter than the database here, which
     * is the safe direction for a fail-closed guard.
     *
     * A NULL brand is kept as its own distinct group, matching the SQL
     * `GROUP BY` semantics on both drivers.
     *
     * @param  list<string>  $columns
     * @return array<string, array{key: list<string|null>, ids: list<string>}>
     */
    private function findDuplicateGroups(array $columns): array
    {
        $groups = [];

        DB::table(self::TABLE)
            ->select([...$columns, 'id'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$groups, $columns): void {
                foreach ($rows as $row) {
                    $keyValues = [];
                    foreach ($columns as $column) {
                        $value = $row->{$column};
                        $keyValues[] = $value === null
                            ? null
                            : strtolower(trim((string) $value));
                    }

                    $key = implode("\0", array_map(
                        static fn (?string $value): string => $value ?? "\x01NULL\x01",
                        $keyValues,
                    ));

                    $groups[$key] ??= ['key' => $keyValues, 'ids' => []];
                    $groups[$key]['ids'][] = (string) $row->id;
                }
            });

        ksort($groups, SORT_STRING);

        return array_filter(
            $groups,
            static fn (array $group): bool => count($group['ids']) > 1,
        );
    }

    /**
     * @param  array<string, array{key: list<string|null>, ids: list<string>}>  $duplicates
     */
    private function duplicateReport(array $duplicates, string $keyColumns): string
    {
        $lines = [
            'V046 gallery-group slug preflight failed: existing rows violate uniqueness on ('.$keyColumns.').',
            'No gallery group was renamed, deleted or merged. Resolve the reported rows before retrying V046.',
        ];

        foreach (array_slice($duplicates, 0, self::MAX_REPORT_GROUPS, true) as $group) {
            $keyLabel = implode('|', array_map(
                static fn (?string $value): string => $value ?? 'NULL',
                $group['key'],
            ));

            $ids = $group['ids'];
            sort($ids, SORT_STRING);

            $lines[] = sprintf(
                'gallery_groups key=(%s) count=%d ids=%s',
                $keyLabel,
                count($ids),
                implode(',', array_slice($ids, 0, self::MAX_REPORT_IDS)),
            );
        }

        if (count($duplicates) > self::MAX_REPORT_GROUPS) {
            $lines[] = sprintf(
                'gallery_groups ... %d additional duplicate groups omitted.',
                count($duplicates) - self::MAX_REPORT_GROUPS,
            );
        }

        return implode("\n", $lines);
    }
};
