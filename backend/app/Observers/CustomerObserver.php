<?php

namespace App\Observers;

use App\Models\Customer;
use App\Services\ModelFileStore;

/**
 * Generalschutz für DSGVO-Löschungen: räumt die verschlüsselten Model-Dateien
 * (Altersnachweis + Personen-Fotos) von der privaten `local`-Disk, egal über
 * welchen Pfad der Customer gelöscht wird (z. B. `CustomerController::destroy`).
 *
 * Die Pfade werden im `deleting`-Event gelesen: Die FK-Cascades entfernen
 * `model_profiles`/`model_photos` bereits mit dem DELETE, bevor `deleted` feuert.
 * Gelöscht wird synchron im `deleted`-Event (nach dem DELETE-Statement). Der
 * DSGVO-Endpunkt löscht den Customer außerhalb einer expliziten Transaktion, damit
 * ein Rollback nicht zu verwaisten DB-Referenzen auf bereits gelöschte Dateien
 * führt. (Ein Aufrufer, der `delete()` in eine Transaktion einbettet und diese
 * zurückrollt, würde die Dateien dennoch verlieren — bewusst in Kauf genommen,
 * da der Egress nur den regulären Pfad nutzt.)
 */
class CustomerObserver
{
    /**
     * File paths captured per customer id between `deleting` and `deleted`.
     *
     * @var array<string, array<int, string>>
     */
    private static array $pendingFiles = [];

    public function __construct(private readonly ModelFileStore $fileStore) {}

    public function deleting(Customer $customer): void
    {
        $paths = [];

        $ageProofPath = $customer->modelProfile?->age_proof_path;
        if (is_string($ageProofPath) && $ageProofPath !== '') {
            $paths[] = $ageProofPath;
        }

        foreach ($customer->modelPhotos()->pluck('path') as $path) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        if ($paths !== []) {
            self::$pendingFiles[$customer->getKey()] = array_values(array_unique($paths));
        }
    }

    public function deleted(Customer $customer): void
    {
        $paths = self::$pendingFiles[$customer->getKey()] ?? [];
        unset(self::$pendingFiles[$customer->getKey()]);

        if ($paths !== []) {
            $this->fileStore->delete($paths);
        }
    }
}
