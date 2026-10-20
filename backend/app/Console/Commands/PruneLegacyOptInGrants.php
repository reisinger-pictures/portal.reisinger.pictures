<?php

namespace App\Console\Commands;

use App\Models\Gallery;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The repair command for the legacy opt-in rows of `user_galleries`.
 *
 * Owner decision 2026-10-20 (D-45 / AD-9) and
 * features/infrastructure/02-email-system.md §2.1.
 *
 * The defect: the gallery notification opt-in was written into `user_galleries`,
 * the pivot that carries the client grant. A subscription therefore created a
 * grant, and for a delivery gallery it outlived the membership that justified
 * it. The application no longer writes into that pivot, but the rows that were
 * written before the migration still exist — and they are ambiguous:
 *
 * - a flagged row is either an *opt-in-only* row (the defect: it acts as a
 *   grant) or a *real grant* that was additionally subscribed to. The pivot
 *   carries no timestamp and no provenance, so the flag alone cannot tell the
 *   two apart. The migration therefore moves the subscription state and deletes
 *   nothing.
 *
 * This command makes the remainder reviewable instead of guessing: it classifies
 * every flagged row with the *own* grant reader
 * ({@see AuthorizationService::getAllowedGalleryIds()}), once with the row and
 * once without it, and reports the verdict.
 *
 * A `candidate` — the row is the single current justification for the access
 * to a delivery gallery — is NOT proof of an opt-in-only row: the same shape is
 * produced by a real direct grant that was additionally subscribed to and whose
 * other justification has since been revoked. The flag cannot separate the two.
 * The operator therefore decides per row:
 *
 * - the dry run (the default) lists every inspected row with its verdict and
 *   deletes nothing;
 * - `--only=<user_id>:<gallery_id>` (repeatable) or `--only-ids=<file>` selects
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
 * no-match is the worst outcome of an operator tool: a typo in a gallery id
 * would look like a successful repair of a row that still exists. The one
 * exception is a database that holds **no** flagged row at all: there is
 * nothing to repair there, so the run stays green instead of making a script
 * that selects rows against a clean database look like a failure.
 *
 * The transient claims of a user (an active invite) are runtime state of the
 * request and are not visible from the database. The verdict therefore speaks
 * about the *persistent* grant paths only.
 */
class PruneLegacyOptInGrants extends Command
{
    protected $signature = 'notifications:prune-legacy-opt-in-grants
                            {--apply : Delete the selected rows instead of only listing them}
                            {--limit=1000 : Maximum number of flagged pivot rows to inspect (0 = no limit)}
                            {--only=* : Restrict the action to these rows, given as user_id:gallery_id (repeatable)}
                            {--only-ids= : A file with one user_id:gallery_id pair per line}';

    protected $description = 'Lists (and with --apply removes) the legacy user_galleries rows whose opt-in flag still acts as a client grant.';

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

        $total = DB::table('user_galleries')->where('wants_notifications', true)->count();

        if ($total === 0) {
            $this->info('No row in [user_galleries] carries the opt-in flag. Nothing to repair.');

            // The selection may still name rows that do not exist. It is
            // reported, but it does not fail the run: there is nothing to
            // repair, so the operator's intent is already fulfilled.
            $unmatched = $this->resolveSelection(collect(), $selected)['unmatched'];
            $this->reportUnmatched($unmatched);

            return self::SUCCESS;
        }

        $query = DB::table('user_galleries')
            ->where('wants_notifications', true)
            ->orderBy('user_id')
            ->orderBy('gallery_id');

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
            '%d flagged row(s) in [user_galleries]; inspecting %d of them.',
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
            ['user', 'gallery', 'type', 'verdict', 'selected', 'action', 'reason'],
            array_map(fn (array $finding): array => [
                $finding['user'],
                $finding['gallery'],
                $finding['gallery_type'],
                $finding['verdict'],
                $finding['selected'] ? 'yes' : 'no',
                $finding['action'],
                $finding['reason'],
            ], $findings),
        );

        $candidates = array_values(array_filter(
            $findings,
            fn (array $finding): bool => $finding['verdict'] === self::VERDICT_CANDIDATE,
        ));

        $this->line(sprintf(
            'redundant: %d, inert: %d, candidate: %d, orphaned: %d, unmatched: %d, selected: %d',
            $this->countVerdict($findings, self::VERDICT_REDUNDANT),
            $this->countVerdict($findings, self::VERDICT_INERT),
            count($candidates),
            $this->countVerdict($findings, self::VERDICT_ORPHANED),
            count($unmatched),
            count(array_filter($findings, fn (array $finding): bool => $finding['selected'])),
        ));

        $this->reportUnmatched($unmatched);

        if (! $apply) {
            $this->info('Dry run: nothing was deleted.');

            if ($candidates !== []) {
                $this->info(sprintf(
                    'Re-run with --apply --only=<user_id>:<gallery_id> to delete the rows you have verified. Without a selection, --apply deletes all %d candidate(s) — including the ones that are a real grant that was additionally subscribed to.',
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
                $finding['gallery_id'],
                $finding['verdict'],
            ));
        }

        $deleted = 0;
        foreach ($selectedCandidates as $finding) {
            $deleted += DB::table('user_galleries')
                ->where('user_id', $finding['user_id'])
                ->where('gallery_id', $finding['gallery_id'])
                ->delete();
        }

        $this->info(sprintf(
            'Deleted %d row(s) from [user_galleries].',
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
        [$userId, $galleryId] = explode(':', $pair, 2);

        return $this->finding(
            $userId,
            $galleryId,
            $userId,
            $galleryId,
            'unknown',
            self::VERDICT_UNMATCHED,
            true,
            'nothing to delete',
            $reason,
        );
    }

    /**
     * Classify one flagged pivot row with the own grant reader.
     *
     * @param  array<string, true>  $selected
     * @return array<string, mixed>
     */
    private function classify(object $row, array $selected, bool $blanket = false): array
    {
        $userId = (string) $row->user_id;
        $galleryId = (string) $row->gallery_id;
        $isSelected = isset($selected[$userId.':'.$galleryId]);

        $user = User::find($userId);
        $gallery = Gallery::find($galleryId);

        if (! $user || ! $gallery) {
            return $this->finding(
                $userId,
                $galleryId,
                $user?->email ?? $userId,
                $gallery?->name ?? $galleryId,
                $gallery?->type !== null ? (string) $gallery->type : 'unknown',
                self::VERDICT_ORPHANED,
                $isSelected,
                'keep',
                'The user row or the gallery row no longer exists — the row is reported and kept.',
            );
        }

        $grantsWithRow = app(AuthorizationService::class)->getAllowedGalleryIds($user);
        $grantsWithoutRow = $this->allowedGalleryIdsWithoutRow($user, $row);

        $grantsWith = in_array($galleryId, $grantsWithRow, true);
        $grantsWithout = in_array($galleryId, $grantsWithoutRow, true);

        if ($grantsWith && $grantsWithout) {
            $verdict = self::VERDICT_REDUNDANT;
            $reason = 'Another grant path grants this gallery — the row is kept.';
        } elseif (! $grantsWith) {
            $verdict = self::VERDICT_INERT;
            $reason = 'The row grants nothing (selection gallery per D-39, brand mismatch) — the row is kept.';
        } else {
            $verdict = self::VERDICT_CANDIDATE;
            $reason = 'The row is the only current justification for the access to this delivery gallery. The flag cannot tell an opt-in-only row from a real grant that was additionally subscribed to — verify it individually.';
        }

        $action = match (true) {
            $verdict === self::VERDICT_CANDIDATE && ($blanket || $isSelected) => 'delete',
            $verdict === self::VERDICT_CANDIDATE => 'keep (not selected)',
            $isSelected => 'keep (not a candidate)',
            default => 'keep',
        };

        return $this->finding(
            $userId,
            $galleryId,
            (string) $user->email,
            (string) $gallery->name,
            (string) $gallery->type,
            $verdict,
            $isSelected,
            $action,
            $reason,
        );
    }

    /**
     * @param  array<string, true>  $selected
     * @return array<string, mixed>
     */
    private function finding(
        string $userId,
        string $galleryId,
        string $user,
        string $gallery,
        string $galleryType,
        string $verdict,
        bool $isSelected,
        string $action,
        string $reason,
    ): array {
        return [
            'user_id' => $userId,
            'gallery_id' => $galleryId,
            'user' => $user,
            'gallery' => $gallery,
            'gallery_type' => $galleryType,
            'verdict' => $verdict,
            'selected' => $isSelected,
            'action' => $action,
            'reason' => $reason,
        ];
    }

    /**
     * The allowed gallery ids of the user *without* the examined pivot row.
     *
     * The row is removed and restored around the reader call, so the reader
     * itself decides the answer: the command cannot drift away from the
     * authorization layer.
     *
     * @return array<string>
     */
    private function allowedGalleryIdsWithoutRow(User $user, object $row): array
    {
        return DB::transaction(function () use ($user, $row): array {
            $removed = DB::table('user_galleries')
                ->where('user_id', $row->user_id)
                ->where('gallery_id', $row->gallery_id)
                ->delete();

            try {
                return app(AuthorizationService::class)->getAllowedGalleryIds($user);
            } finally {
                if ($removed > 0) {
                    DB::table('user_galleries')->insert([
                        'user_id' => $row->user_id,
                        'gallery_id' => $row->gallery_id,
                        'wants_notifications' => $row->wants_notifications,
                    ]);
                }
            }
        });
    }

    /**
     * The rows the operator selected, as `user_id:gallery_id` pairs.
     *
     * @return array<string, true>|false false on an invalid selection
     */
    private function selectedPairs(): array|false
    {
        $pairs = [];

        foreach ((array) $this->option('only') as $raw) {
            $pair = $this->parsePair((string) $raw);
            if ($pair === null) {
                $this->error(sprintf('Invalid --only value [%s]. Expected user_id:gallery_id.', $raw));

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
                    $this->error(sprintf('Invalid --only-ids entry [%s]. Expected user_id:gallery_id.', $line));

                    return false;
                }

                $pairs[$pair] = true;
            }
        }

        return $pairs;
    }

    /**
     * Normalize a `user_id:gallery_id` pair.
     */
    private function parsePair(string $raw): ?string
    {
        $parts = explode(':', trim($raw));
        if (count($parts) !== 2) {
            return null;
        }

        $userId = trim($parts[0]);
        $galleryId = trim($parts[1]);
        if ($userId === '' || $galleryId === '') {
            return null;
        }

        return $userId.':'.$galleryId;
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
            ->map(fn (object $row): string => (string) $row->user_id.':'.(string) $row->gallery_id)
            ->all();

        $unmatched = [];

        foreach (array_diff(array_keys($selected), $seen) as $pair) {
            [$userId, $galleryId] = explode(':', $pair, 2);

            $row = DB::table('user_galleries')
                ->where('user_id', $userId)
                ->where('gallery_id', $galleryId)
                ->where('wants_notifications', true)
                ->first();

            if ($row !== null) {
                $inspected->push($row);

                continue;
            }

            $unmatched[$pair] = $this->unmatchedReason($userId, $galleryId);
        }

        return [
            'inspected' => $inspected
                ->sortBy(fn (object $row): string => (string) $row->user_id.':'.(string) $row->gallery_id)
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
    private function unmatchedReason(string $userId, string $galleryId): string
    {
        $exists = DB::table('user_galleries')
            ->where('user_id', $userId)
            ->where('gallery_id', $galleryId)
            ->exists();

        return $exists
            ? 'A pivot row exists for this pair, but it carries no opt-in flag — there is nothing to repair.'
            : 'There is no pivot row for this pair at all — check the user and the gallery id.';
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
