<?php

namespace App\Services;

use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use Closure;
use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Serializes primary-photo mutations for registration, management, and owner paths.
 *
 * A short-lived cache lock serializes workers before the database lock; the
 * profile row is the durable cross-request mutex. Every mutation locks that
 * row with `lockForUpdate()` before changing any photo flags. The clear-and-
 * set sequence therefore commits as one serialized unit on databases with
 * transactional row locks (and SQLite serializes competing writers). No schema
 * migration is required for this application-level invariant.
 */
class ModelPhotoPrimaryService
{
    private const LOCK_TTL_SECONDS = 30;

    private const LOCK_WAIT_SECONDS = 10;

    /**
     * Promote one public photo and clear every other primary for the profile.
     *
     * @param  string  $validationKey  Field used when the target is not public.
     */
    public function promote(ModelProfile $profile, string $photoId, string $validationKey = 'is_primary'): ModelPhoto
    {
        $photo = $this->applyUpdates($profile, [
            'visibility' => [],
            'primary' => $photoId,
            'clear' => false,
        ], $validationKey);

        if ($photo === null) {
            throw new \LogicException('A primary photo promotion did not return its target.');
        }

        return $photo;
    }

    /**
     * Promote a photo from a caller-owned database transaction.
     *
     * Registration submissions already own a transaction for the invite and
     * profile metadata. This variant joins that transaction instead of opening
     * a nested savepoint, and keeps the cache lock until the outer transaction
     * commits or rolls back.
     */
    public function promoteInTransaction(ModelProfile $profile, string $photoId, string $validationKey = 'is_primary'): ModelPhoto
    {
        $updates = [
            'visibility' => [],
            'primary' => $photoId,
            'clear' => false,
        ];

        $photo = DB::connection()->transactionLevel() === 0
            ? $this->applyUpdates($profile, $updates, $validationKey)
            : $this->withProfileLockUntilTransactionEnd(
                $profile,
                fn (): ?ModelPhoto => $this->applyUpdatesInTransaction($profile, $updates, $validationKey),
            );

        if ($photo === null) {
            throw new \LogicException('A primary photo promotion did not return its target.');
        }

        return $photo;
    }

    /**
     * Clear the primary flag for all photos belonging to a profile.
     */
    public function clear(ModelProfile $profile): void
    {
        $this->applyUpdates($profile, [
            'visibility' => [],
            'primary' => null,
            'clear' => true,
        ]);
    }

    /**
     * Apply owner photo visibility and primary changes under one profile lock.
     *
     * @param  array{visibility: array<string, string>, primary: ?string, clear: bool, primary_error_key?: ?string}  $updates
     */
    public function applyUpdates(ModelProfile $profile, array $updates, string $validationKey = 'photos'): ?ModelPhoto
    {
        return $this->withProfileLock($profile, function () use ($profile, $updates, $validationKey): ?ModelPhoto {
            return DB::transaction(
                fn (): ?ModelPhoto => $this->applyUpdatesInTransaction($profile, $updates, $validationKey),
                3,
            );
        });
    }

    /**
     * Apply owner photo changes and the caller's metadata writes as one
     * profile-locked transaction.
     *
     * The cache lock deliberately surrounds the whole transaction, rather than
     * only the nested photo write. This keeps the profile row lock and the
     * cross-process mutex held until the outer owner transaction commits (or
     * rolls back).
     *
     * @param  array{visibility: array<string, string>, primary: ?string, clear: bool, primary_error_key?: ?string}  $updates
     */
    public function updateOwner(ModelProfile $profile, array $updates, Closure $callback, string $validationKey = 'photos'): mixed
    {
        if (! $this->hasPhotoUpdates($updates)) {
            return DB::transaction($callback);
        }

        return $this->withProfileLock($profile, function () use ($profile, $updates, $validationKey, $callback): mixed {
            return DB::transaction(function () use ($profile, $updates, $validationKey, $callback): mixed {
                $this->applyUpdatesInTransaction($profile, $updates, $validationKey);

                return $callback();
            }, 3);
        });
    }

    /**
     * Apply the already-validated photo updates inside the caller's profile
     * lock and database transaction.
     *
     * @param  array{visibility: array<string, string>, primary: ?string, clear: bool, primary_error_key?: ?string}  $updates
     */
    private function applyUpdatesInTransaction(ModelProfile $profile, array $updates, string $validationKey): ?ModelPhoto
    {
        $profileId = $this->lockProfile($profile);

        foreach (($updates['visibility'] ?? []) as $photoId => $visibility) {
            if (! in_array($visibility, [ModelPhoto::VISIBILITY_PUBLIC, ModelPhoto::VISIBILITY_INTERNAL], true)) {
                throw ValidationException::withMessages([
                    $validationKey => 'Ungültige Sichtbarkeit.',
                ]);
            }

            $photo = ModelPhoto::query()
                ->where('model_profile_id', $profileId)
                ->whereKey($photoId)
                ->lockForUpdate()
                ->first();

            if ($photo === null) {
                throw ValidationException::withMessages([
                    $validationKey => 'Unbekanntes Foto.',
                ]);
            }

            ModelPhoto::query()
                ->where('model_profile_id', $profileId)
                ->whereKey($photo->getKey())
                ->update(['visibility' => $visibility]);
        }

        $primaryId = $updates['primary'] ?? null;
        $clear = (bool) ($updates['clear'] ?? false);

        if ($primaryId !== null) {
            $photo = ModelPhoto::query()
                ->where('model_profile_id', $profileId)
                ->whereKey($primaryId)
                ->lockForUpdate()
                ->first();

            if ($photo === null) {
                throw ValidationException::withMessages([
                    $updates['primary_error_key'] ?? $validationKey => 'Unbekanntes Foto.',
                ]);
            }

            $this->assertPublic($photo, $updates['primary_error_key'] ?? $validationKey);

            ModelPhoto::query()
                ->where('model_profile_id', $profileId)
                ->update(['is_primary' => false]);

            $this->writePrimaryFlag($photo);

            return $photo;
        }

        if ($clear) {
            ModelPhoto::query()
                ->where('model_profile_id', $profileId)
                ->update(['is_primary' => false]);

            return null;
        }

        // Visibility-only owner updates still run under the same lock. Do not
        // leave a legacy duplicate or an internal primary behind after a
        // concurrent update has changed the photo set.
        $primaries = ModelPhoto::query()
            ->where('model_profile_id', $profileId)
            ->where('is_primary', true)
            ->orderBy('position')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($primaries->contains(
            static fn (ModelPhoto $photo): bool => $photo->visibility !== ModelPhoto::VISIBILITY_PUBLIC,
        )) {
            throw ValidationException::withMessages([
                $validationKey => 'Das Hauptbild muss öffentlich sein.',
            ]);
        }

        if ($primaries->count() > 1) {
            $keep = $primaries->first();
            ModelPhoto::query()
                ->where('model_profile_id', $profileId)
                ->update(['is_primary' => false]);
            $this->writePrimaryFlag($keep);
        }

        return null;
    }

    /**
     * @param  array{visibility?: array<string, string>, primary?: ?string, clear?: bool}  $updates
     */
    private function hasPhotoUpdates(array $updates): bool
    {
        return ($updates['visibility'] ?? []) !== []
            || ($updates['primary'] ?? null) !== null
            || (bool) ($updates['clear'] ?? false);
    }

    /**
     * Persist a true primary flag even when the model was already true in
     * memory. The preceding bulk clear changes the database row to false, but
     * Eloquent would otherwise consider `true -> true` clean and issue no
     * UPDATE. Resetting the model baseline makes the final write observable to
     * model events as well as to the database.
     */
    private function writePrimaryFlag(ModelPhoto $photo): void
    {
        $photo->forceFill(['is_primary' => false]);
        $photo->syncOriginalAttribute('is_primary');
        $photo->forceFill(['is_primary' => true]);

        if ($photo->save() !== true) {
            throw new \RuntimeException('Primary photo write was cancelled.');
        }
    }

    /**
     * Delete a photo and clear its primary flag in the same profile-locked
     * transaction. The caller removes the encrypted file after commit.
     *
     * @throws \RuntimeException when a model event cancels deletion
     */
    public function deletePhoto(ModelProfile $profile, string $photoId): ModelPhoto
    {
        return $this->withProfileLock($profile, function () use ($profile, $photoId): ModelPhoto {
            return DB::transaction(function () use ($profile, $photoId): ModelPhoto {
                $profileId = $this->lockProfile($profile);
                $photo = ModelPhoto::query()
                    ->where('model_profile_id', $profileId)
                    ->whereKey($photoId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $wasPrimary = $photo->is_primary;
                if ($photo->delete() !== true) {
                    throw new \RuntimeException('Model photo deletion was cancelled.');
                }

                if ($wasPrimary) {
                    ModelPhoto::query()
                        ->where('model_profile_id', $profileId)
                        ->update(['is_primary' => false]);
                }

                return $photo;
            }, 3);
        });
    }

    /**
     * Run a profile mutation in an already active transaction while retaining
     * the cache lock until the outermost transaction ends.
     */
    private function withProfileLockUntilTransactionEnd(ModelProfile $profile, Closure $callback): mixed
    {
        try {
            $lock = $this->acquireProfileLock($this->profileLockName($profile));
        } catch (LockTimeoutException) {
            $this->abortProfileLockTimeout();
        }

        $released = false;
        $release = static function () use (&$released, $lock): void {
            if ($released) {
                return;
            }

            $released = true;
            $lock->release();
        };

        try {
            DB::afterCommit($release);
            DB::afterRollBack($release);
        } catch (\Throwable $exception) {
            // A transaction manager is required for deferred release. Do not
            // leak the cache lock if callback registration itself fails.
            $release();

            throw $exception;
        }

        return $callback();
    }

    private function acquireProfileLock(string $lockName): CacheLock
    {
        /** @var CacheLock $lock */
        $lock = Cache::lock($lockName, self::LOCK_TTL_SECONDS);
        $startedAt = microtime(true);

        while (! $lock->acquire()) {
            if ((microtime(true) - $startedAt) >= self::LOCK_WAIT_SECONDS) {
                throw new LockTimeoutException;
            }

            usleep(250_000);
        }

        return $lock;
    }

    private function withProfileLock(ModelProfile $profile, Closure $callback): mixed
    {
        try {
            return Cache::lock($this->profileLockName($profile), self::LOCK_TTL_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, $callback);
        } catch (LockTimeoutException) {
            $this->abortProfileLockTimeout();
        }
    }

    private function profileLockName(ModelProfile $profile): string
    {
        return 'model-photo-primary:'.$profile->getKey();
    }

    private function abortProfileLockTimeout(): never
    {
        abort(response()->json([
            'error' => 'Das Hauptbild wird gerade aktualisiert. Bitte versuche es erneut.',
        ], 409));
    }

    private function lockProfile(ModelProfile $profile): string
    {
        return (string) ModelProfile::query()
            ->whereKey($profile->getKey())
            ->lockForUpdate()
            ->firstOrFail()
            ->getKey();
    }

    private function assertPublic(ModelPhoto $photo, string $validationKey): void
    {
        if ($photo->visibility !== ModelPhoto::VISIBILITY_PUBLIC) {
            throw ValidationException::withMessages([
                $validationKey => 'Das Hauptbild muss öffentlich sein.',
            ]);
        }
    }
}
