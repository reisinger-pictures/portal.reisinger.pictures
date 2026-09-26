<?php

namespace App\Services;

use App\Enums\Brand;
use App\Models\PhotoJob;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Serializes board ordering changes and keeps status columns dense.
 *
 * There is intentionally no schema migration in this change.  The shared
 * cache lock covers the empty-column case (where there is no row to lock),
 * while the database transaction and row locks protect the actual reads and
 * writes on databases with transactional row locking.
 */
class BoardPositionService
{
    private const LOCK_TTL_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 10;

    /**
     * Run a board mutation under one brand-wide ordering lock.
     *
     * @param  list<string>  $models  Model keys: projects, photo_jobs, or both.
     */
    public function transaction(string|Brand|null $brand, array $models, Closure $callback): mixed
    {
        $brandId = $brand instanceof Brand ? $brand->value : $brand;
        $lockName = 'board-position:'.rawurlencode($brandId ?? '__legacy_null__');

        try {
            return Cache::lock($lockName, self::LOCK_TTL_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, function () use ($brandId, $models, $callback): mixed {
                    return DB::transaction(function () use ($brandId, $models, $callback): mixed {
                        $this->lockRows($brandId, $models);

                        return $callback();
                    }, 3);
                });
        } catch (LockTimeoutException) {
            abort(409, 'Board ordering is busy. Please retry.');
        }
    }

    /**
     * Append an item to a dense status column and return its new position.
     * The supplied builder is intentionally already brand/owner-scoped by
     * the caller; ordering must not broaden the mutation scope.
     */
    public function appendPosition(Builder $query, string $status): int
    {
        $this->reindexColumn($query, $status);

        return (int) (clone $query)->where('status', $status)->count();
    }

    /**
     * Move an item to a status column, preserving the current relative order
     * of all other items and clamping an oversized requested position to the
     * end of the column.
     */
    public function positionItem(
        Builder $query,
        Model $item,
        string $newStatus,
        ?int $requestedPosition,
    ): Model {
        $oldStatus = (string) $item->status;
        $targetQuery = (clone $query)
            ->where('status', $newStatus)
            ->where($item->getKeyName(), '!=', $item->getKey());
        $targetCount = (int) (clone $targetQuery)->count();
        $effectivePosition = $requestedPosition === null
            ? $targetCount
            : min(max($requestedPosition, 0), $targetCount);

        $item->status = $newStatus;
        $item->position = $effectivePosition;
        // A position-only move intentionally counts as board activity and
        // refreshes updated_at; the business-age semantics are documented in
        // features/b2b/11-kanban-board.md.
        $item->save();

        if ($oldStatus !== $newStatus) {
            $this->reindexColumn($query, $oldStatus);
        }

        $this->reindexColumn($query, $newStatus, (string) $item->getKey(), $effectivePosition);

        return $item;
    }

    /**
     * Reindex one status column as 0..n-1.
     *
     * The pinned item is removed from the stable order and inserted at the
     * requested index.  `id` is the final tie-breaker, so reindexing does not
     * depend on mutable updated_at timestamps.
     */
    public function reindexColumn(
        Builder $query,
        string $status,
        ?string $pinnedItemId = null,
        ?int $pinnedPosition = null,
    ): void {
        $items = (clone $query)
            ->where('status', $status)
            ->orderBy('position')
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $pinned = null;
        if ($pinnedItemId !== null) {
            $pinned = $items->first(
                static fn (Model $item): bool => (string) $item->getKey() === $pinnedItemId
            );

            if ($pinned === null) {
                throw new \LogicException('Pinned board item was not found in its target column.');
            }
        }

        $ordered = $items
            ->reject(static fn (Model $item): bool => $pinned !== null && (string) $item->getKey() === $pinnedItemId)
            ->values()
            ->all();

        if ($pinned !== null) {
            $insertAt = $pinnedPosition === null
                ? count($ordered)
                : min(max($pinnedPosition, 0), count($ordered));
            array_splice($ordered, $insertAt, 0, [$pinned]);
        }

        foreach ($ordered as $position => $item) {
            if ((int) $item->position !== $position) {
                $item->position = $position;
                $item->saveQuietly();
            }
        }
    }

    /**
     * Reindex the position column owned by one user.
     *
     * Visibility through an assignee does not grant a caller permission to
     * renumber another owner's rows. The same owner-scoped helper is used by
     * privileged cleanup for every owner whose column lost an item.
     */
    public function reindexOwnerColumn(Builder $query, string $status, string $ownerId): void
    {
        $ownerQuery = (clone $query)->where('owner_id', $ownerId);

        $this->reindexColumn($ownerQuery, $status);
    }

    /**
     * @param  list<string>  $models
     */
    private function lockRows(string|Brand|null $brand, array $models): void
    {
        // A board column can be empty, so there may be no board row to lock.
        // The first brand-compatible user row is a stable database anchor for
        // that case; every board mutation for the brand locks the same row.
        $anchor = User::query()
            ->where(function (Builder $query) use ($brand): void {
                if ($brand === null) {
                    $query->whereNull('brand');

                    return;
                }

                $query->where('brand', $brand)->orWhereNull('brand');
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->first(['id']);

        // A malformed legacy dataset can have board rows but no compatible
        // user anchor.  The cache lock still serializes normal requests; the
        // global fallback gives database-backed drivers a row to lock as well.
        if ($anchor === null) {
            User::query()
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id']);
        }

        $queries = [
            'projects' => Project::query()->forBrand($brand),
            'photo_jobs' => PhotoJob::query()->forBrand($brand),
        ];

        $modelKeys = array_values(array_unique($models));
        sort($modelKeys, SORT_STRING);

        foreach ($modelKeys as $modelKey) {
            if (! isset($queries[$modelKey])) {
                continue;
            }

            $queries[$modelKey]
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }
    }
}
