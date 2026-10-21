<?php

namespace App\Console\Commands;

use App\Models\GalleryGroup;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The repair command for the legacy opt-in rows of `user_gallery_groups`.
 *
 * Owner decision 2026-10-21 (D-46 / AD-10) and
 * features/infrastructure/02-email-system.md §2.2.
 *
 * The defect: the group notification opt-in was written into `user_gallery_groups`,
 * the pivot that carries the client group grant. A subscription therefore produced
 * a grant — and a group grant covers the whole subtree of the group, so the row
 * granted every gallery below it. The application no longer writes into that
 * pivot, but the rows that were written before the migration still exist — and
 * they are ambiguous:
 *
 * - a flagged row is either an *opt-in-only* row (the defect: it acts as a
 *   grant over the whole subtree) or a *real grant* that was additionally
 *   subscribed to. The pivot carries no timestamp and no provenance, so the
 *   flag alone cannot tell the two apart. The migration therefore moves the
 *   subscription state and deletes nothing.
 *
 * This command makes the remainder reviewable instead of guessing: it classifies
 * every flagged row with the *own* grant reader
 * ({@see AuthorizationService::getAllowedGalleryIds()}), once with the row and
 * once without it, and reports the verdict. The unit of access is the subtree of
 * the group: a group grant hands out the galleries of the group and of every
 * group below it.
 *
 * A `candidate` — the row is the single current justification for the access to
 * the subtree of the group — is NOT proof of an opt-in-only row: the same shape
 * is produced by a real group grant that was additionally subscribed to and
 * whose other justification has since been revoked. The flag cannot separate
 * the two. The operator therefore decides per row:
 *
 * - the dry run (the default) lists every inspected row with its verdict and
 *   deletes nothing;
 * - `--only=<user_id>:<group_id>` (repeatable) or `--only-ids=<file>` selects
 *   the rows the operator has verified. `--apply` deletes **only** the selected
 *   rows that are candidates; a selected row that is not a candidate is
 *   reported and kept;
 * - `--apply` without a selection deletes **all** candidates. That is a blunt
 *   instrument: it removes the real grants among them as well. It exists
 *   because the list can be verified as a whole, but the per-row selection is
 *   the safer path.
 *
 * A selected pair that matches **no** flagged row is reported as well: it is
 * listed with the verdict `unmatched`, warned about individually, counted in
 * the summary, and it makes the run end with a non-zero exit code. A silent
 * no-match is the worst outcome of an operator tool: a typo in a group id
 * would look like a successful repair of a row that still exists. The one
 * exception is a database that holds **no** flagged row at all: there is
 * nothing to repair there, so the run stays green instead of making a script
 * that selects rows against a clean database look like a failure.
 *
 * The transient claims of a user (an active invite) are runtime state of the
 * request and are not visible from the database. The verdict therefore speaks
 * about the *persistent* grant paths only.
 *
 * The verdict `orphaned` is a defensive classification: both foreign keys of
 * `user_gallery_groups` cascade on delete (V001), so a deleted user or a
 * deleted group deletes the pivot row itself. It is only reachable when the
 * foreign key check is deferred — in an intact database the operator never
 * sees it.
 *
 * The listing holds the names of the user and of the group *and* the pair
 * `user_id:gallery_group_id` that `--only` takes: the names are for reading,
 * the pair is for the selection, and the operator must not have to map one to
 * the other by hand.
 */
class PruneLegacyGroupOptInGrants extends Command
{
    protected $signature = 'notifications:prune-legacy-group-opt-in-grants
                            {--apply : Delete the selected rows instead of only listing them}
                            {--limit=1000 : Maximum number of flagged pivot rows to inspect (0 = no limit)}
                            {--only=* : Restrict the action to these rows, given as user_id:group_id (repeatable)}
                            {--only-ids= : A file with one user_id:group_id pair per line}';

    protected $description = 'Lists (and with --apply removes) the legacy user_gallery_groups rows whose opt-in flag still acts as a client group grant.';

    protected $help = <<<'HELP'
The dry run lists every flagged row with its verdict and deletes nothing. The
column [selection] holds the pair <user_id>:<gallery_group_id> that --only
takes, so a verified row can be selected by copying that pair.

The verdict [orphaned] is a defensive classification: both foreign keys of
[user_gallery_groups] cascade on delete, so a deleted user or a deleted group
deletes the pivot row itself. It is only reachable when the foreign key check
is deferred — in an intact database it does not occur.

--apply deletes the selected rows that are [candidate]. Without a selection it
deletes every candidate, including the real grants among them.
HELP;

    private const VERDICT_REDUNDANT = 'redundant';

    private const VERDICT_INERT = 'inert';

    private const VERDICT_CANDIDATE = 'candidate';

    private const VERDICT_ORPHANED = 'orphaned';

    private const VERDICT_UNMATCHED = 'unmatched';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $apply = (bool) $this->option('apply');

        $selected = $this->selectedPairs();
        if ($selected === false) {
            return self::FAILURE;
        }

        $total = DB::table('user_gallery_groups')->where('wants_notifications', true)->count();

        if ($total === 0) {
            $this->info('No row in [user_gallery_groups] carries the opt-in flag. Nothing to repair.');

            // The selection may still name rows that do not exist. It is
            // reported, but it does not fail the run: there is nothing to
            // repair, so the operator's intent is already fulfilled.
            $unmatched = $this->resolveSelection(collect(), $selected)['unmatched'];
            $this->reportUnmatched($unmatched);

            return self::SUCCESS;
        }

        $query = DB::table('user_gallery_groups')
            ->where('wants_notifications', true)
            ->orderBy('user_id')
            ->orderBy('gallery_group_id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get();

        $truncated = $rows->count() < $total;

        // A selected row is always inspected, even when it lies behind the
        // limit. The operator named it explicitly; the limit must not silently
        // skip it. A selected pair that matches no flagged row at all is
        // reported as `unmatched`.
        $resolution = $this->resolveSelection($rows, $selected);
        $inspected = $resolution['inspected'];
        $unmatched = $resolution['unmatched'];

        $this->info(sprintf(
            '%d flagged row(s) in [user_gallery_groups]; inspecting %d of them.',
            $total,
            $inspected->count(),
        ));

        if ($truncated) {
            $this->warn(sprintf(
                'The limit of %d truncated the list: %d of %d flagged rows were not inspected. Raise --limit (0 = no limit) for the complete list.',
                $limit,
                $total - $rows->count(),
                $total,
            ));
        }

        $findings = [];
        foreach ($inspected as $row) {
            $findings[] = $this->classify($row, $selected, $selected === []);
        }
        foreach ($unmatched as $pair => $reason) {
            $findings[] = $this->unmatchedFinding($pair, $reason);
        }

        if ($findings === []) {
            $this->info('Nothing could be classified.');

            return self::SUCCESS;
        }

        $this->table(
            ['user', 'group', 'selection', 'verdict', 'selected', 'action', 'reason'],
            array_map(fn (array $finding): array => [
                $finding['user'],
                $finding['group'],
                $finding['user_id'].':'.$finding['group_id'],
                $finding['verdict'],
                $finding['selected'] ? 'yes' : 'no',
                $finding['action'],
                $finding['reason'],
            ], $findings),
        );

        // The selection column is the pair the operator needs: the names are
        // for reading, the pair is for the selection.
        $this->line('The column [selection] holds the pair <user_id>:<gallery_group_id> of --only — it can be copied into the selection as it is.');

        $candidates = array_values(array_filter(
            $findings,
            fn (array $finding): bool => $finding['verdict'] === self::VERDICT_CANDIDATE,
        ));

        $orphaned = $this->countVerdict($findings, self::VERDICT_ORPHANED);

        $this->line(sprintf(
            'redundant: %d, inert: %d, candidate: %d, orphaned: %d, unmatched: %d, selected: %d',
            $this->countVerdict($findings, self::VERDICT_REDUNDANT),
            $this->countVerdict($findings, self::VERDICT_INERT),
            count($candidates),
            $orphaned,
            count($unmatched),
            count(array_filter($findings, fn (array $finding): bool => $finding['selected'])),
        ));

        if ($orphaned > 0) {
            // The verdict is defensive. In a database with intact foreign keys
            // the operator never sees it, and he should not wonder what it
            // means.
            $this->info('The [orphaned] rows are a defensive classification: both foreign keys of [user_gallery_groups] cascade on delete, so a deleted user or a deleted group deletes the pivot row itself. The verdict is only reachable when the foreign key check is deferred — in an intact database it does not occur.');
        }

        $this->reportUnmatched($unmatched);

        if (! $apply) {
            $this->info('Dry run: nothing was deleted.');

            if ($candidates !== []) {
                $this->info(sprintf(
                    'Re-run with --apply --only=<user_id>:<group_id> to delete the rows you have verified. Without a selection, --apply deletes all %d candidate(s) — including the ones that are a real grant that was additionally subscribed to.',
                    count($candidates),
                ));
            }

            return $unmatched === [] ? self::SUCCESS : self::FAILURE;
        }

        // A truncated list with a blanket `--apply` would delete only the
        // visible candidates and look like a complete repair. It is refused.
        if ($truncated && $selected === []) {
            $this->error('The list is truncated and no row was selected: nothing was deleted. Raise --limit (0 = no limit) or select the rows with --only.');

            return self::FAILURE;
        }

        // Without a selection the command is the blunt instrument: it removes
        // every candidate. With a selection it removes exactly the selected
        // candidates.
        $blanket = $selected === [];

        if ($blanket) {
            $this->warn('No row was selected: every candidate is deleted, including the ones that are a real grant that was additionally subscribed to.');
        }

        $selectedCandidates = array_values(array_filter(
            $candidates,
            fn (array $finding): bool => $blanket || $finding['selected'],
        ));

        $selectedButNotCandidate = array_values(array_filter(
            $findings,
            fn (array $finding): bool => ! $blanket && $finding['selected'] && $finding['verdict'] !== self::VERDICT_CANDIDATE,
        ));

        $candidateButNotSelected = array_values(array_filter(
            $candidates,
            fn (array $finding): bool => ! $blanket && ! $finding['selected'],
        ));

        foreach ($selectedButNotCandidate as $finding) {
            $this->warn(sprintf(
                'Selected row [%s / %s] is [%s] and was NOT deleted.',
                $finding['user_id'],
                $finding['group_id'],
                $finding['verdict'],
            ));
        }

        $deleted = 0;
        foreach ($selectedCandidates as $finding) {
            $deleted += DB::table('user_gallery_groups')
                ->where('user_id', $finding['user_id'])
                ->where('gallery_group_id', $finding['group_id'])
                ->delete();
        }

        $this->info(sprintf(
            'Deleted %d row(s) from [user_gallery_groups].',
            $deleted,
        ));

        if ($candidateButNotSelected !== []) {
            $this->info(sprintf(
                '%d candidate(s) were not selected and were kept.',
                count($candidateButNotSelected),
            ));
        }

        // The selected candidates were deleted, but the selection was not fully
        // satisfiable: the run reports that with a non-zero exit code.
        return $unmatched === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Report the selected pairs that matched no flagged row.
     *
     * @param  array<string, string>  $unmatched
     */
    private function reportUnmatched(array $unmatched): void
    {
        foreach ($unmatched as $pair => $reason) {
            $this->warn(sprintf('Selected pair [%s] matched no flagged row: %s', $pair, $reason));
        }
    }

    /**
     * A selected pair that matched no flagged row.
     *
     * @return array<string, mixed>
     */
    private function unmatchedFinding(string $pair, string $reason): array
    {
        [$userId, $groupId] = explode(':', $pair, 2);

        return $this->finding(
            $userId,
            $groupId,
            $userId,
            $groupId,
            self::VERDICT_UNMATCHED,
            true,
            'nothing to delete',
            $reason,
        );
    }

    /**
     * Classify one flagged pivot row with the own grant reader.
     *
     * The unit of access is the subtree of the group: a group grant hands out
     * the galleries of the group and of every group below it. The row is
     * therefore a candidate when it is the single current justification for
     * *any* gallery of that subtree.
     *
     * @param  array<string, true>  $selected
     * @return array<string, mixed>
     */
    private function classify(object $row, array $selected, bool $blanket = false): array
    {
        $userId = (string) $row->user_id;
        $groupId = (string) $row->gallery_group_id;
        $isSelected = isset($selected[$userId.':'.$groupId]);

        $user = User::find($userId);
        $group = GalleryGroup::find($groupId);

        if (! $user || ! $group) {
            return $this->finding(
                $userId,
                $groupId,
                $user?->email ?? $userId,
                $group?->name ?? $groupId,
                self::VERDICT_ORPHANED,
                $isSelected,
                'keep',
                'The user row or the group row no longer exists — the row is reported and kept. The verdict is defensive: with intact foreign keys the cascade deletes the pivot row itself, so it is only reachable when the foreign key check is deferred.',
            );
        }

        $grantsWithRow = $this->grantedSubtreeGalleryIds($user, $groupId, null);
        $grantsWithoutRow = $this->grantedSubtreeGalleryIds($user, $groupId, $row);

        $grantsWith = $grantsWithRow !== [];
        $grantsWithout = $grantsWithoutRow !== [];

        if ($grantsWith && $grantsWithout) {
            $verdict = self::VERDICT_REDUNDANT;
            $reason = 'Another grant path grants this group — the row is kept.';
        } elseif (! $grantsWith) {
            $verdict = self::VERDICT_INERT;
            $reason = 'The row grants nothing (empty group, brand mismatch, D-39 subtree lock) — the row is kept.';
        } else {
            $verdict = self::VERDICT_CANDIDATE;
            $reason = 'The row is the only current justification for the access to this group subtree. The flag cannot tell an opt-in-only row from a real grant that was additionally subscribed to — verify it individually.';
        }

        $action = match (true) {
            $verdict === self::VERDICT_CANDIDATE && ($blanket || $isSelected) => 'delete',
            $verdict === self::VERDICT_CANDIDATE => 'keep (not selected)',
            $isSelected => 'keep (not a candidate)',
            default => 'keep',
        };

        return $this->finding(
            $userId,
            $groupId,
            (string) $user->email,
            (string) $group->name,
            $verdict,
            $isSelected,
            $action,
            $reason,
        );
    }

    /**
     * The gallery ids of the group's subtree that the user may access.
     *
     * When `$rowToRemove` is given, the examined pivot row is removed around
     * the reader call and restored afterwards, so the reader itself decides
     * the answer: the command cannot drift away from the authorization layer.
     *
     * @param  object|null  $rowToRemove  the pivot row to hide from the reader
     * @return array<string>
     */
    private function grantedSubtreeGalleryIds(User $user, string $groupId, ?object $rowToRemove): array
    {
        $allowed = DB::transaction(function () use ($user, $rowToRemove): array {
            $removed = 0;

            if ($rowToRemove !== null) {
                $removed = DB::table('user_gallery_groups')
                    ->where('user_id', $rowToRemove->user_id)
                    ->where('gallery_group_id', $rowToRemove->gallery_group_id)
                    ->delete();
            }

            try {
                return app(AuthorizationService::class)->getAllowedGalleryIds($user);
            } finally {
                if ($removed > 0) {
                    DB::table('user_gallery_groups')->insert([
                        'user_id' => $rowToRemove->user_id,
                        'gallery_group_id' => $rowToRemove->gallery_group_id,
                        'wants_notifications' => $rowToRemove->wants_notifications,
                    ]);
                }
            }
        });

        $subtreeGroupIds = app(AuthorizationService::class)->getSubGroupIds([$groupId]);

        if ($subtreeGroupIds === []) {
            return [];
        }

        $subtreeGalleryIds = DB::table('galleries')
            ->whereIn('gallery_group_id', $subtreeGroupIds)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        return array_values(array_intersect($subtreeGalleryIds, $allowed));
    }

    /**
     * @param  array<string, true>  $selected
     * @return array<string, mixed>
     */
    private function finding(
        string $userId,
        string $groupId,
        string $user,
        string $group,
        string $verdict,
        bool $isSelected,
        string $action,
        string $reason,
    ): array {
        return [
            'user_id' => $userId,
            'group_id' => $groupId,
            'user' => $user,
            'group' => $group,
            'verdict' => $verdict,
            'selected' => $isSelected,
            'action' => $action,
            'reason' => $reason,
        ];
    }

    /**
     * The rows the operator selected, as `user_id:group_id` pairs.
     *
     * @return array<string, true>|false false on an invalid selection
     */
    private function selectedPairs(): array|false
    {
        $pairs = [];

        foreach ((array) $this->option('only') as $raw) {
            $pair = $this->parsePair((string) $raw);
            if ($pair === null) {
                $this->error(sprintf('Invalid --only value [%s]. Expected user_id:group_id.', $raw));

                return false;
            }

            $pairs[$pair] = true;
        }

        $file = $this->option('only-ids');
        if (is_string($file) && $file !== '') {
            if (! is_readable($file)) {
                $this->error(sprintf('The --only-ids file [%s] is not readable.', $file));

                return false;
            }

            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines === false ? [] : $lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }

                $pair = $this->parsePair($line);
                if ($pair === null) {
                    $this->error(sprintf('Invalid --only-ids entry [%s]. Expected user_id:group_id.', $line));

                    return false;
                }

                $pairs[$pair] = true;
            }
        }

        return $pairs;
    }

    /**
     * Normalize a `user_id:group_id` pair.
     */
    private function parsePair(string $raw): ?string
    {
        $parts = explode(':', trim($raw));
        if (count($parts) !== 2) {
            return null;
        }

        $userId = trim($parts[0]);
        $groupId = trim($parts[1]);
        if ($userId === '' || $groupId === '') {
            return null;
        }

        return $userId.':'.$groupId;
    }

    /**
     * The inspected rows and the selected pairs that matched no flagged row.
     *
     * A selected pair that lies behind the `--limit` is still inspected: the
     * operator named it explicitly, the limit must not silently skip it. A
     * selected pair that matches no flagged row at all is reported as
     * unmatched — it is never silently dropped.
     *
     * @param  array<string, true>  $selected
     * @return array{inspected: Collection<int, object>, unmatched: array<string, string>}
     */
    private function resolveSelection(Collection $rows, array $selected): array
    {
        $inspected = collect($rows);
        $seen = $inspected
            ->map(fn (object $row): string => (string) $row->user_id.':'.(string) $row->gallery_group_id)
            ->all();

        $unmatched = [];

        foreach (array_diff(array_keys($selected), $seen) as $pair) {
            [$userId, $groupId] = explode(':', $pair, 2);

            $row = DB::table('user_gallery_groups')
                ->where('user_id', $userId)
                ->where('gallery_group_id', $groupId)
                ->where('wants_notifications', true)
                ->first();

            if ($row !== null) {
                $inspected->push($row);

                continue;
            }

            $unmatched[$pair] = $this->unmatchedReason($userId, $groupId);
        }

        return [
            'inspected' => $inspected
                ->sortBy(fn (object $row): string => (string) $row->user_id.':'.(string) $row->gallery_group_id)
                ->values(),
            'unmatched' => $unmatched,
        ];
    }

    /**
     * Why a selected pair matched no flagged row.
     *
     * The two cases are told apart: a pivot row that exists but carries no
     * opt-in flag is a row that needs no repair; a pair without any pivot row
     * is most likely a typo in the id.
     */
    private function unmatchedReason(string $userId, string $groupId): string
    {
        $exists = DB::table('user_gallery_groups')
            ->where('user_id', $userId)
            ->where('gallery_group_id', $groupId)
            ->exists();

        return $exists
            ? 'A pivot row exists for this pair, but it carries no opt-in flag — there is nothing to repair.'
            : 'There is no pivot row for this pair at all — check the user and the group id.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     */
    private function countVerdict(array $findings, string $verdict): int
    {
        return count(array_filter(
            $findings,
            fn (array $finding): bool => $finding['verdict'] === $verdict,
        ));
    }
}
