<?php

namespace App\Observers;

use App\Jobs\SyncCustomerSearchJob;
use App\Models\Customer;
use App\Models\ModelPhoto;
use App\Models\ModelProfile;
use App\Services\CustomerSearchSyncService;
use App\Services\ModelFileCleanupService;

/**
 * Generalschutz für DSGVO-Löschungen: räumt die verschlüsselten Model-Dateien
 * (Altersnachweis + Personen-Fotos) von der privaten `local`-Disk, egal über
 * welchen Pfad der Customer gelöscht wird (z. B. `CustomerController::destroy`).
 *
 * Die Pfade werden während des `deleting`-Events direkt aus der autoritativen
 * DB-Projektion gelesen und mit den betroffenen Zeilen gesperrt. Dadurch werden
 * weder eine veraltete Relation noch ein vor der Transaktion angefertigter
 * Snapshot als Cleanup-Menge verwendet. Die eigentliche Löschung wird erst nach
 * einem erfolgreichen Commit gequeued; ein Rollback kann daher keine Datei
 * entfernen. Scout wird für dieselbe Löschung über einen ID-only Job
 * nach-commit entfernt.
 */
class CustomerObserver
{
    /**
     * File paths captured at the actual deletion boundary.
     *
     * @var array<string, array<int, string>>
     */
    private static array $pendingFiles = [];

    /**
     * Customer ids whose deletion is owned by the model erasure service.
     *
     * @var array<string, bool>
     */
    private static array $deferredFileCleanup = [];

    public function __construct(
        private readonly ModelFileCleanupService $cleanup,
        private readonly CustomerSearchSyncService $searchSync,
    ) {}

    /**
     * Mark a transactional caller. The observer still owns the post-commit
     * dispatch so direct and service-driven deletions have the same guarantees.
     */
    public static function deferFileCleanupFor(Customer|string $customer): void
    {
        $key = $customer instanceof Customer ? (string) $customer->getKey() : $customer;
        self::$deferredFileCleanup[$key] = true;
    }

    /**
     * Clear observer state when a deletion was cancelled or rolled back before
     * the `deleted` event could run.
     */
    public static function clearDeletionStateFor(Customer|string $customer): void
    {
        $key = $customer instanceof Customer ? (string) $customer->getKey() : $customer;
        unset(self::$pendingFiles[$key], self::$deferredFileCleanup[$key]);
    }

    /**
     * Read the authoritative paths while the child rows still exist. The lock is
     * effective on transactional databases; the profile/photo writes used by
     * owner and admin flows must wait until the customer delete has completed.
     */
    public function deleting(Customer $customer): void
    {
        $key = (string) $customer->getKey();
        $paths = [];

        $ageProofPath = ModelProfile::query()
            ->where('customer_id', $customer->getKey())
            ->lockForUpdate()
            ->value('age_proof_path');
        if (is_string($ageProofPath) && $ageProofPath !== '') {
            $paths[] = $ageProofPath;
        }

        $photoPaths = ModelPhoto::query()
            ->where('customer_id', $customer->getKey())
            ->lockForUpdate()
            ->pluck('path');
        foreach ($photoPaths as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        self::$pendingFiles[$key] = array_values(array_unique($paths));
    }

    public function deleted(Customer $customer): void
    {
        $key = (string) $customer->getKey();
        $paths = self::$pendingFiles[$key] ?? [];
        $deferred = self::$deferredFileCleanup[$key] ?? false;
        unset(self::$pendingFiles[$key], self::$deferredFileCleanup[$key]);

        if ($paths !== []) {
            $this->cleanup->afterCommit(
                $paths,
                $deferred ? 'model_profile_erase' : 'customer_delete',
                $key,
            );
        }

        // Search engines cannot participate in the database transaction. The
        // Customer model suppresses Scout during the delete event, so every
        // hard delete gets an ID-only, post-commit removal job here.
        $this->searchSync->defer($key, SyncCustomerSearchJob::REMOVE);
    }
}
