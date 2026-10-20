<?php

namespace Tests\Feature\Notification;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Contract coverage for `notifications:prune-legacy-opt-in-grants`.
 *
 * The command is the reviewable half of the legacy residual of the owner
 * decision 2026-10-20 (D-45 / AD-9): the flagged rows of `user_galleries` are
 * ambiguous — an opt-in-only row (the defect) or a real grant that was
 * additionally subscribed to — and the migration therefore deletes nothing.
 * The command classifies every flagged row with the own grant reader and
 * proposes the deletion of the rows whose grant is only justified by
 * themselves.
 *
 * A candidate is not proof of an opt-in-only row, so the deletion is
 * restricted to the rows the operator selected. The tests below pin that
 * selection: an unselected candidate survives, a selected row that is no longer
 * a candidate is kept and reported, and the dry run deletes nothing.
 */
class PruneLegacyOptInGrantsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dry_run_lists_the_candidate_row_without_deleting_it(): void
    {
        [$client, $gallery] = $this->candidateRow();

        $this->assertSame(0, Artisan::call('notifications:prune-legacy-opt-in-grants'));

        $output = Artisan::output();
        $this->assertStringContainsString('candidate', $output);
        $this->assertStringContainsString($gallery->name, $output);

        // The dry run deletes nothing.
        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_the_dry_run_with_a_selection_deletes_nothing(): void
    {
        [$client, $gallery] = $this->candidateRow();

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-opt-in-grants --only=%s:%s',
            $client->id,
            $gallery->id,
        )));

        $this->assertStringContainsString('Dry run', Artisan::output());
        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_the_apply_run_deletes_the_selected_candidate_row(): void
    {
        [$client, $gallery] = $this->candidateRow();

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-opt-in-grants --only=%s:%s --apply',
            $client->id,
            $gallery->id,
        )));

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
        ]);
    }

    public function test_an_unselected_candidate_survives_the_apply_run(): void
    {
        [$selected, $selectedGallery] = $this->candidateRow();
        [$untouched, $untouchedGallery] = $this->candidateRow();

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-opt-in-grants --only=%s:%s --apply',
            $selected->id,
            $selectedGallery->id,
        )));

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $selected->id,
            'gallery_id' => $selectedGallery->id,
        ]);
        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $untouched->id,
            'gallery_id' => $untouchedGallery->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_a_selected_row_that_is_not_a_candidate_is_kept_and_reported(): void
    {
        // The client is still a member of the organization, so the gallery is
        // granted through the org scope. The flagged row is redundant: it may be
        // an explicit assignment of the admin and must survive.
        [$client, $gallery] = $this->redundantRow();

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-opt-in-grants --only=%s:%s --apply',
            $client->id,
            $gallery->id,
        )));

        $output = Artisan::output();
        $this->assertStringContainsString('redundant', $output);
        $this->assertStringContainsString('was NOT deleted', $output);
        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_the_apply_run_without_a_selection_deletes_every_candidate(): void
    {
        // The blunt instrument: without a selection, `--apply` removes all
        // candidates. It is documented as such — the per-row selection is the
        // safe path.
        [$first, $firstGallery] = $this->candidateRow();
        [$second, $secondGallery] = $this->candidateRow();

        $this->assertSame(0, Artisan::call('notifications:prune-legacy-opt-in-grants --apply'));

        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $first->id,
            'gallery_id' => $firstGallery->id,
        ]);
        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $second->id,
            'gallery_id' => $secondGallery->id,
        ]);
    }

    public function test_a_truncated_list_refuses_the_blanket_apply_run(): void
    {
        [$first, $firstGallery] = $this->candidateRow();
        [$second, $secondGallery] = $this->candidateRow();

        $this->assertSame(1, Artisan::call('notifications:prune-legacy-opt-in-grants --limit=1 --apply'));

        $this->assertStringContainsString('truncated', Artisan::output());
        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $first->id,
            'gallery_id' => $firstGallery->id,
            'wants_notifications' => 1,
        ]);
        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $second->id,
            'gallery_id' => $secondGallery->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_a_selected_row_behind_the_limit_is_still_inspected_and_deleted(): void
    {
        [$first, $firstGallery] = $this->candidateRow();
        [$second, $secondGallery] = $this->candidateRow();

        // The limit truncates the listing; the selected row is inspected
        // anyway, so the action set stays complete and explicit.
        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-opt-in-grants --limit=1 --only=%s:%s --apply',
            $second->id,
            $secondGallery->id,
        )));

        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $first->id,
            'gallery_id' => $firstGallery->id,
            'wants_notifications' => 1,
        ]);
        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $second->id,
            'gallery_id' => $secondGallery->id,
        ]);
    }

    public function test_a_row_without_a_user_or_a_gallery_is_listed_and_counted(): void
    {
        // A row whose user or gallery row is gone cannot be classified. It is
        // reported and counted, never silently dropped.
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $goneUserId = (string) Str::uuid();

        // The deferred pragma keeps the foreign key check from firing inside
        // the test transaction, so the row can outlive its user. The check is
        // deferred to the commit, and the test transaction is rolled back.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('user_galleries')->insert([
            'user_id' => $goneUserId,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 1,
        ]);

        $this->assertSame(0, Artisan::call('notifications:prune-legacy-opt-in-grants --apply'));

        $output = Artisan::output();
        $this->assertStringContainsString('orphaned', $output);
        $this->assertStringContainsString('orphaned: 1', $output);
        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $goneUserId,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_an_invalid_selection_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('notifications:prune-legacy-opt-in-grants --only=not-a-pair --apply'));

        $this->assertStringContainsString('Invalid --only value', Artisan::output());
    }

    public function test_a_selected_pair_that_matches_no_flagged_row_is_reported_and_fails(): void
    {
        // A typo in the gallery id must not look like a successful repair.
        [$client, $gallery] = $this->candidateRow();
        $typo = (string) Str::uuid();

        $this->assertSame(1, Artisan::call(sprintf(
            'notifications:prune-legacy-opt-in-grants --only=%s:%s',
            $client->id,
            $typo,
        )));

        $output = Artisan::output();
        $this->assertStringContainsString('unmatched', $output);
        $this->assertStringContainsString('matched no flagged row', $output);
        $this->assertStringContainsString('There is no pivot row for this pair at all', $output);
        $this->assertStringContainsString('unmatched: 1', $output);

        // Nothing was deleted.
        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_a_selected_pair_with_a_pivot_row_without_the_opt_in_flag_is_reported(): void
    {
        // The pivot row exists, but it carries no opt-in flag: there is nothing
        // to repair. The selection is still reported, and it is told apart from
        // a pair that was inspected and kept.
        $client = User::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        DB::table('user_galleries')->insert([
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 0,
        ]);

        // A second, flagged row: the database has something to repair, so the
        // unmatched selection is a failure and not a no-op.
        $this->candidateRow();

        $this->assertSame(1, Artisan::call(sprintf(
            'notifications:prune-legacy-opt-in-grants --only=%s:%s',
            $client->id,
            $gallery->id,
        )));

        $output = Artisan::output();
        $this->assertStringContainsString('unmatched', $output);
        $this->assertStringContainsString('it carries no opt-in flag', $output);
        $this->assertStringContainsString('unmatched: 1', $output);
        $this->assertDatabaseHas('user_galleries', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 0,
        ]);
    }

    public function test_a_selection_against_a_database_without_opt_in_rows_stays_green(): void
    {
        // The trade-off of the non-zero exit code: a database that holds no
        // flagged row needs no repair, so the run stays green instead of making
        // a script that selects rows look like a failure.
        $client = User::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery']);

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-opt-in-grants --only=%s:%s --apply',
            $client->id,
            $gallery->id,
        )));

        $output = Artisan::output();
        $this->assertStringContainsString('Nothing to repair', $output);
        $this->assertStringContainsString('matched no flagged row', $output);
    }

    public function test_the_command_does_nothing_without_a_flagged_row(): void
    {
        $this->assertSame(0, Artisan::call('notifications:prune-legacy-opt-in-grants --apply'));

        $this->assertStringContainsString('Nothing to repair', Artisan::output());
    }

    /**
     * A client who subscribed to an org gallery and lost the membership: the
     * flagged pivot row is the only thing that still grants the gallery.
     *
     * @return array{0: User, 1: Gallery}
     */
    private function candidateRow(): array
    {
        $org = Org::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $gallery->orgs()->attach($org);

        $client = User::factory()->create(['org_id' => $org->id]);
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        // The legacy write path of the opt-in: the row landed in the grant
        // pivot and carries the opt-in flag.
        DB::table('user_galleries')->insert([
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 1,
        ]);

        $client->forceFill(['org_id' => null])->save();

        return [$client->fresh(), $gallery];
    }

    /**
     * A flagged row whose gallery is granted through another path as well.
     *
     * @return array{0: User, 1: Gallery}
     */
    private function redundantRow(): array
    {
        $org = Org::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $gallery->orgs()->attach($org);

        $client = User::factory()->create(['org_id' => $org->id]);
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        DB::table('user_galleries')->insert([
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 1,
        ]);

        return [$client, $gallery];
    }
}
