<?php

namespace Tests\Feature\Notification;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Contract coverage for `notifications:prune-legacy-group-opt-in-grants`.
 *
 * The command is the reviewable half of the legacy residual of the owner
 * decision 2026-10-21 (D-46 / AD-10): the flagged rows of `user_gallery_groups`
 * are ambiguous — an opt-in-only row (the defect) or a real grant that was
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
class PruneLegacyGroupOptInGrantsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dry_run_lists_the_candidate_row_without_deleting_it(): void
    {
        [$client, $group] = $this->candidateRow();

        $this->assertSame(0, Artisan::call('notifications:prune-legacy-group-opt-in-grants'));

        $output = Artisan::output();
        $this->assertStringContainsString('candidate', $output);
        $this->assertStringContainsString($group->name, $output);

        // The dry run deletes nothing.
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_the_dry_run_with_a_selection_deletes_nothing(): void
    {
        [$client, $group] = $this->candidateRow();

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-group-opt-in-grants --only=%s:%s',
            $client->id,
            $group->id,
        )));

        $this->assertStringContainsString('Dry run', Artisan::output());
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_the_apply_run_deletes_the_selected_candidate_row(): void
    {
        [$client, $group] = $this->candidateRow();

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-group-opt-in-grants --only=%s:%s --apply',
            $client->id,
            $group->id,
        )));

        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_the_deleted_row_keeps_the_subscription_in_the_subscription_table(): void
    {
        // The repair removes the grant row, not the subscription: the state was
        // moved into its own table by the migration.
        [$client, $group] = $this->candidateRow();

        DB::table('group_notification_subscriptions')->insert([
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-group-opt-in-grants --only=%s:%s --apply',
            $client->id,
            $group->id,
        )));

        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
        $this->assertDatabaseHas('group_notification_subscriptions', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_an_unselected_candidate_survives_the_apply_run(): void
    {
        [$selected, $selectedGroup] = $this->candidateRow();
        [$untouched, $untouchedGroup] = $this->candidateRow();

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-group-opt-in-grants --only=%s:%s --apply',
            $selected->id,
            $selectedGroup->id,
        )));

        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $selected->id,
            'gallery_group_id' => $selectedGroup->id,
        ]);
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $untouched->id,
            'gallery_group_id' => $untouchedGroup->id,
            'wants_notifications' => 1,
        ]);
    }

    #[Group('smoke')]
    public function test_a_selected_row_that_is_not_a_candidate_is_kept_and_reported(): void
    {
        // The client is still a member of the organization, so the group subtree
        // is granted through the org scope. The flagged row is redundant: it
        // may be an explicit assignment of the admin and must survive.
        [$client, $group] = $this->redundantRow();

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-group-opt-in-grants --only=%s:%s --apply',
            $client->id,
            $group->id,
        )));

        $output = Artisan::output();
        $this->assertStringContainsString('redundant', $output);
        $this->assertStringContainsString('was NOT deleted', $output);
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_a_row_that_grants_nothing_is_reported_as_inert_and_kept(): void
    {
        // An empty group grants no gallery at all: the row can neither be the
        // justification of an access nor a grant that has to be repaired.
        [$client, $group] = $this->inertRow();

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-group-opt-in-grants --only=%s:%s --apply',
            $client->id,
            $group->id,
        )));

        $output = Artisan::output();
        $this->assertStringContainsString('inert', $output);
        $this->assertStringContainsString('inert: 1', $output);
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_the_apply_run_without_a_selection_deletes_every_candidate(): void
    {
        // The blunt instrument: without a selection, `--apply` removes all
        // candidates. It is documented as such — the per-row selection is the
        // safe path.
        [$first, $firstGroup] = $this->candidateRow();
        [$second, $secondGroup] = $this->candidateRow();

        $this->assertSame(0, Artisan::call('notifications:prune-legacy-group-opt-in-grants --apply'));

        $this->assertStringContainsString('every candidate is deleted', Artisan::output());
        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $first->id,
            'gallery_group_id' => $firstGroup->id,
        ]);
        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $second->id,
            'gallery_group_id' => $secondGroup->id,
        ]);
    }

    public function test_a_truncated_list_refuses_the_blanket_apply_run(): void
    {
        [$first, $firstGroup] = $this->candidateRow();
        [$second, $secondGroup] = $this->candidateRow();

        $this->assertSame(1, Artisan::call('notifications:prune-legacy-group-opt-in-grants --limit=1 --apply'));

        $this->assertStringContainsString('truncated', Artisan::output());
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $first->id,
            'gallery_group_id' => $firstGroup->id,
            'wants_notifications' => 1,
        ]);
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $second->id,
            'gallery_group_id' => $secondGroup->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_a_selected_row_behind_the_limit_is_still_inspected_and_deleted(): void
    {
        [$first, $firstGroup] = $this->candidateRow();
        [$second, $secondGroup] = $this->candidateRow();

        // The limit truncates the listing; the selected row is inspected
        // anyway, so the action set stays complete and explicit.
        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-group-opt-in-grants --limit=1 --only=%s:%s --apply',
            $second->id,
            $secondGroup->id,
        )));

        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $first->id,
            'gallery_group_id' => $firstGroup->id,
            'wants_notifications' => 1,
        ]);
        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $second->id,
            'gallery_group_id' => $secondGroup->id,
        ]);
    }

    public function test_a_selection_from_a_file_is_applied(): void
    {
        [$client, $group] = $this->candidateRow();

        $file = tempnam(sys_get_temp_dir(), 'group-opt-in-');
        file_put_contents($file, $client->id.':'.$group->id."\n");

        try {
            $this->assertSame(0, Artisan::call(sprintf(
                'notifications:prune-legacy-group-opt-in-grants --only-ids=%s --apply',
                $file,
            )));
        } finally {
            @unlink($file);
        }

        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_a_row_without_a_user_or_a_group_is_listed_and_counted(): void
    {
        // A row whose user or group row is gone cannot be classified. It is
        // reported and counted, never silently dropped.
        $group = GalleryGroup::factory()->create();
        $goneUserId = (string) Str::uuid();

        // The deferred pragma keeps the foreign key check from firing inside
        // the test transaction, so the row can outlive its user. The check is
        // deferred to the commit, and the test transaction is rolled back.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('user_gallery_groups')->insert([
            'user_id' => $goneUserId,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);

        $this->assertSame(0, Artisan::call('notifications:prune-legacy-group-opt-in-grants --apply'));

        $output = Artisan::output();
        $this->assertStringContainsString('orphaned', $output);
        $this->assertStringContainsString('orphaned: 1', $output);
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $goneUserId,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_the_dry_run_prints_the_selection_pair_next_to_the_names(): void
    {
        // The names are for reading, the pair is for the selection: the
        // operator must not have to map the e-mail and the group name to the
        // `user_id:gallery_group_id` pair of `--only` by hand.
        [$client, $group] = $this->candidateRow();

        $this->assertSame(0, Artisan::call('notifications:prune-legacy-group-opt-in-grants'));

        $output = Artisan::output();

        // The human-readable columns stay.
        $this->assertStringContainsString($client->email, $output);
        $this->assertStringContainsString($group->name, $output);

        // The pair of the selection is printed with them.
        $this->assertStringContainsString($client->id.':'.$group->id, $output);
        $this->assertStringContainsString('selection', $output);
    }

    public function test_the_orphaned_verdict_says_that_it_is_a_defensive_classification(): void
    {
        // Both foreign keys of `user_gallery_groups` cascade on delete, so a
        // deleted user or a deleted group deletes the pivot row itself. In an
        // intact database the operator never sees this verdict — the output
        // has to say so, or the classification looks like a defect.
        $group = GalleryGroup::factory()->create();
        $goneUserId = (string) Str::uuid();

        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('user_gallery_groups')->insert([
            'user_id' => $goneUserId,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);

        $this->assertSame(0, Artisan::call('notifications:prune-legacy-group-opt-in-grants'));

        $output = Artisan::output();

        $this->assertStringContainsString('orphaned', $output);
        $this->assertStringContainsString('cascade on delete', $output);
        $this->assertStringContainsString('foreign key check is deferred', $output);
    }

    public function test_an_invalid_selection_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('notifications:prune-legacy-group-opt-in-grants --only=not-a-pair --apply'));

        $this->assertStringContainsString('Invalid --only value', Artisan::output());
    }

    public function test_a_selected_pair_that_matches_no_flagged_row_is_reported_and_fails(): void
    {
        // A typo in the group id must not look like a successful repair.
        [$client, $group] = $this->candidateRow();
        $typo = (string) Str::uuid();

        $this->assertSame(1, Artisan::call(sprintf(
            'notifications:prune-legacy-group-opt-in-grants --only=%s:%s',
            $client->id,
            $typo,
        )));

        $output = Artisan::output();
        $this->assertStringContainsString('unmatched', $output);
        $this->assertStringContainsString('matched no flagged row', $output);
        $this->assertStringContainsString('There is no pivot row for this pair at all', $output);
        $this->assertStringContainsString('unmatched: 1', $output);

        // Nothing was deleted.
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_a_selected_pair_with_a_pivot_row_without_the_opt_in_flag_is_reported(): void
    {
        // The pivot row exists, but it carries no opt-in flag: there is nothing
        // to repair. The selection is still reported, and it is told apart from
        // a pair that was inspected and kept.
        $client = User::factory()->create();
        $group = GalleryGroup::factory()->create();
        DB::table('user_gallery_groups')->insert([
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 0,
        ]);

        // A second, flagged row: the database has something to repair, so the
        // unmatched selection is a failure and not a no-op.
        $this->candidateRow();

        $this->assertSame(1, Artisan::call(sprintf(
            'notifications:prune-legacy-group-opt-in-grants --only=%s:%s',
            $client->id,
            $group->id,
        )));

        $output = Artisan::output();
        $this->assertStringContainsString('unmatched', $output);
        $this->assertStringContainsString('it carries no opt-in flag', $output);
        $this->assertStringContainsString('unmatched: 1', $output);
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 0,
        ]);
    }

    public function test_a_selection_against_a_database_without_opt_in_rows_stays_green(): void
    {
        // The trade-off of the non-zero exit code: a database that holds no
        // flagged row needs no repair, so the run stays green instead of making
        // a script that selects rows look like a failure.
        $client = User::factory()->create();
        $group = GalleryGroup::factory()->create();

        $this->assertSame(0, Artisan::call(sprintf(
            'notifications:prune-legacy-group-opt-in-grants --only=%s:%s --apply',
            $client->id,
            $group->id,
        )));

        $output = Artisan::output();
        $this->assertStringContainsString('Nothing to repair', $output);
        $this->assertStringContainsString('matched no flagged row', $output);
    }

    public function test_the_command_does_nothing_without_a_flagged_row(): void
    {
        $this->assertSame(0, Artisan::call('notifications:prune-legacy-group-opt-in-grants --apply'));

        $this->assertStringContainsString('Nothing to repair', Artisan::output());
    }

    /**
     * A client whose flagged pivot row is the only thing that still grants the
     * subtree of the group.
     *
     * @return array{0: User, 1: GalleryGroup}
     */
    private function candidateRow(): array
    {
        $group = GalleryGroup::factory()->create();
        Gallery::factory()->create(['type' => 'delivery', 'gallery_group_id' => $group->id]);

        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        // The legacy write path of the opt-in: the row landed in the grant
        // pivot and carries the opt-in flag.
        DB::table('user_gallery_groups')->insert([
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);

        return [$client->fresh(), $group];
    }

    /**
     * A flagged row whose group subtree is granted through another path as
     * well.
     *
     * @return array{0: User, 1: GalleryGroup}
     */
    private function redundantRow(): array
    {
        $org = Org::factory()->create();
        $group = GalleryGroup::factory()->create();
        Gallery::factory()->create(['type' => 'delivery', 'gallery_group_id' => $group->id]);
        $org->galleryGroups()->attach($group->id);

        $client = User::factory()->create(['org_id' => $org->id]);
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        DB::table('user_gallery_groups')->insert([
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);

        return [$client, $group];
    }

    /**
     * A flagged row whose group grants nothing at all.
     *
     * @return array{0: User, 1: GalleryGroup}
     */
    private function inertRow(): array
    {
        $group = GalleryGroup::factory()->create();

        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        DB::table('user_gallery_groups')->insert([
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);

        return [$client, $group];
    }
}
