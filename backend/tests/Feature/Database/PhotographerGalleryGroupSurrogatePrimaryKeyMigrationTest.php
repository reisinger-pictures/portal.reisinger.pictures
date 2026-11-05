<?php

namespace Tests\Feature\Database;

use App\Models\GalleryGroup;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract coverage for V058, the surrogate row identity of
 * `photographer_gallery_groups`.
 *
 * V008 removed the composite primary key so `gallery_group_id` could be
 * nullable with ON DELETE SET NULL. The remaining unique key is not a row
 * identity: two rows may share the same user and a NULL group, which the
 * PostgreSQL cutover copy cannot chunk. This test restores the pre-V058 shape,
 * inserts that exact ambiguity plus a non-null assignment, runs the migration
 * and proves that no assignment is lost or rewritten.
 */
class PhotographerGalleryGroupSurrogatePrimaryKeyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'photographer_gallery_groups';

    public function test_v058_adds_a_distinct_generated_primary_key_without_rewriting_assignments(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The pre-V058 fixture rebuild uses PostgreSQL DDL; PostgreSQL is the canonical test database.');
        }

        $this->restorePreV058Shape();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $group = GalleryGroup::factory()->create();

        DB::table(self::TABLE)->insert([
            ['user_id' => $user->id, 'gallery_group_id' => null],
            ['user_id' => $user->id, 'gallery_group_id' => null],
            ['user_id' => $otherUser->id, 'gallery_group_id' => $group->id],
        ]);

        $before = $this->assignmentSnapshot();
        $this->assertCount(3, $before);

        $this->migration()->up();

        // Every assignment survives byte-for-byte; only the surrogate is new.
        $rows = DB::table(self::TABLE)->orderBy('id')->get();
        $this->assertCount(3, $rows);
        $this->assertSame($before, $this->assignmentSnapshot());

        // All preexisting rows received distinct, non-null generated IDs.
        $this->assertTrue($rows->every(static fn (object $row): bool => $row->id !== null));
        $this->assertSame(3, $rows->pluck('id')->unique()->count());

        $this->assertTrue(Schema::hasIndex(self::TABLE, ['id'], 'primary'));
        $this->assertTrue(Schema::hasIndex(self::TABLE, ['user_id', 'gallery_group_id'], 'unique'));

        $columns = collect(Schema::getColumns(self::TABLE))->keyBy('name');
        $this->assertSame('bigint', $columns['id']['type']);
        $this->assertTrue((bool) $columns['id']['auto_increment']);
        $this->assertFalse((bool) $columns['id']['nullable']);
        $this->assertTrue((bool) $columns['gallery_group_id']['nullable']);

        // A replay finds the shape V058 itself created and changes nothing.
        $this->migration()->up();
        $this->assertCount(3, DB::table(self::TABLE)->get());
        $this->assertSame($before, $this->assignmentSnapshot());

        // Duplicate (user_id, NULL) rows stay permitted and get generated IDs.
        $existingIds = $rows->pluck('id')->all();
        DB::table(self::TABLE)->insert([
            'user_id' => $user->id,
            'gallery_group_id' => null,
        ]);

        $newRow = DB::table(self::TABLE)
            ->where('user_id', $user->id)
            ->whereNull('gallery_group_id')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($newRow);
        $this->assertNotContains($newRow->id, $existingIds);
        $this->assertSame(
            3,
            DB::table(self::TABLE)->where('user_id', $user->id)->whereNull('gallery_group_id')->count(),
        );

        // The composite unique index still rejects a duplicate non-null grant.
        try {
            DB::transaction(static function () use ($otherUser, $group): void {
                DB::table(self::TABLE)->insert([
                    'user_id' => $otherUser->id,
                    'gallery_group_id' => $group->id,
                ]);
            });
            $this->fail('V058 lost the composite unique index over (user_id, gallery_group_id).');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(4, DB::table(self::TABLE)->count());
        }
    }

    /**
     * Rebuild the shape V008 left: no surrogate ID, no primary key.
     */
    private function restorePreV058Shape(): void
    {
        $this->assertTrue(Schema::hasColumn(self::TABLE, 'id'));

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropPrimary();
        });

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('id');
        });

        // The owned sequence is dropped with the column; keep the fixture
        // deterministic if a PostgreSQL version leaves it behind.
        DB::statement('DROP SEQUENCE IF EXISTS photographer_gallery_groups_id_seq');

        $this->assertFalse(Schema::hasColumn(self::TABLE, 'id'));
        $this->assertFalse(Schema::hasIndex(self::TABLE, ['id'], 'primary'));
    }

    /**
     * @return list<string>
     */
    private function assignmentSnapshot(): array
    {
        return DB::table(self::TABLE)
            ->select('user_id', 'gallery_group_id')
            ->get()
            ->map(static fn (object $row): string => (string) $row->user_id
                .'|'.($row->gallery_group_id === null ? 'NULL' : (string) $row->gallery_group_id))
            ->sort()
            ->values()
            ->all();
    }

    private function migration(): object
    {
        return require database_path('migrations/V058__photographer_gallery_group_surrogate_primary_key.php');
    }
}
