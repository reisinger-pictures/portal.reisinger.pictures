<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Domain\DepreciationCalculator;
use App\Eur\Reference\EurYear;
use App\Exceptions\EurDocumentPositionException;
use App\Models\EurAsset;
use App\Models\EurAssetDocument;
use App\Models\EurAssetGroup;
use App\Models\EurAuditLog;
use App\Models\EurDocument;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * EAR · Beleg-Positionen: Split und Merge sind zwei Richtungen derselben
 * Verknüpfung (Portal #59, D-90).
 *
 * Eine Position ist eine angereicherte Zeile der bestehenden Pivot
 * `eur_asset_documents`; es gibt keine zweite Wahrheitsquelle. Der Split
 * legt je Position ein eigenes Anlagegut an und benutzt dafür exakt denselben
 * Anlagepfad wie `/assets`. Der Merge ordnet alle mit den Belegen verknüpften
 * Güter einer bestehenden Gruppe zu (eine Abrechnungseinheit je D-89).
 * Alle Geldbeträge sind ganze Cent, alle Abfragen brand-scoped.
 */
final class EurDocumentPositionService
{
    private const METHODS = [DepreciationCalculator::METHOD_LINEAR, DepreciationCalculator::METHOD_DEGRESSIVE];

    public function __construct(
        private readonly EurAssetCreationService $creationService,
        private readonly EurAssetGroupService $groupService,
    ) {}

    /**
     * Reine Allokationsarithmetik: ganze Cent, kein Runden, kein Restverlust.
     * Ein Restbetrag ist zulässig; eine Überschreitung des Belegbetrags nicht.
     *
     * @param  list<array<string, mixed>>  $positions
     * @return array{allocated_cents: int, remaining_cents: int|null}
     */
    public function allocationTotals(array $positions, ?int $totalCents, bool $enforce = true): array
    {
        $allocated = 0;

        foreach ($positions as $position) {
            $amount = $position['amount_cents'] ?? null;
            if ($amount === null) {
                continue;
            }

            $allocated += (int) $amount;
        }

        if ($enforce && $totalCents !== null && $allocated > $totalCents) {
            throw new EurDocumentPositionException(EurDocumentPositionException::OVER_ALLOCATION_MESSAGE);
        }

        return [
            'allocated_cents' => $allocated,
            'remaining_cents' => $totalCents === null ? null : $totalCents - $allocated,
        ];
    }

    /**
     * Ein Beleg → N unabhängig abgerechnete Anlagegüter.
     *
     * @param  list<array<string, mixed>>  $positions
     * @return list<EurAssetDocument>
     */
    public function createPositions(EurDocument $document, array $positions, ?int $totalCents): array
    {
        if ($this->hasPositions($document)) {
            throw new EurDocumentPositionException(EurDocumentPositionException::DOUBLE_SPLIT_MESSAGE);
        }

        if ($positions === []) {
            throw new EurDocumentPositionException('Ein Beleg benötigt mindestens eine Position.');
        }

        $total = $totalCents ?? ($document->total_cents === null ? null : (int) $document->total_cents);
        $this->allocationTotals($positions, $total);

        return DB::transaction(function () use ($document, $positions, $totalCents): array {
            $created = [];
            $ordinal = 1;

            foreach ($positions as $position) {
                $validated = $this->validatePosition($position);
                $actualOrdinal = $validated['ordinal'] ?? $ordinal;
                // Der Positionsschlüssel bleibt über Trennen/Wiedergruppieren
                // stabil und hält Entnahmen bei gemeinsamer Belegnummer getrennt.
                $positionKey = sprintf('%d-%d', $document->id, $actualOrdinal);

                $asset = $this->creationService->create([
                    'brand' => BrandRegistry::currentId(),
                    'name' => $validated['name'],
                    'invoice_no' => $document->receipt_no ?? sprintf('BD-%d', $document->id),
                    'supplier' => sprintf('Beleg #%d', $document->id),
                    'purchase_date' => $this->purchaseDate($document),
                    'cost_cents' => $validated['amount_cents'],
                    'useful_life_years' => $validated['useful_life_years'],
                    'business_share' => $validated['business_share'],
                    'scope' => $validated['business_share'] < 50 ? 'private' : 'business',
                    'method' => $validated['method'],
                    'degressive_rate' => $validated['degressive_rate'],
                    'eur_asset_group_id' => null,
                ], null, $positionKey);

                $created[] = EurAssetDocument::create([
                    'brand' => BrandRegistry::currentId(),
                    'eur_asset_id' => $asset->id,
                    'eur_document_id' => $document->id,
                    'label' => $validated['label'],
                    'amount_cents' => $validated['amount_cents'],
                    'ordinal' => $actualOrdinal,
                ]);

                $ordinal++;
            }

            if ($totalCents !== null) {
                $document->update(['total_cents' => $totalCents]);
            }

            $this->audit('positions_created', $document, [
                'count' => count($created),
                'allocated_cents' => $this->allocationTotals($positions, null)['allocated_cents'],
            ]);

            return $created;
        });
    }

    /** Entfernt NUR die Pivot-Zeile; das Gut und seine Buchung bleiben. */
    public function deletePosition(int $pivotId): void
    {
        $pivot = EurAssetDocument::forCurrentBrand()->findOrFail($pivotId);
        $document = EurDocument::forCurrentBrand()->findOrFail($pivot->eur_document_id);

        DB::transaction(function () use ($pivot, $document, $pivotId): void {
            $assetId = (int) $pivot->eur_asset_id;
            $pivot->delete();

            $this->audit('position_deleted', $document, [
                'position_id' => $pivotId,
                'asset_id' => $assetId,
            ]);
        });
    }

    /**
     * N Belege → eine bestehende Gruppe (oder das Lösen dieser Zuordnung).
     * Die Verknüpfung bleibt transitiv über die Mitgliedsgüter (D-89/D-90).
     */
    public function assignGroup(EurDocument $document, ?int $groupId): void
    {
        $assetIds = EurAssetDocument::forCurrentBrand()
            ->where('eur_document_id', $document->id)
            ->pluck('eur_asset_id');
        $assets = EurAsset::forCurrentBrand()->whereIn('id', $assetIds)->orderBy('id')->get()->all();

        DB::transaction(function () use ($document, $assets, $groupId): void {
            // D-89 fail closed: jede Gruppe, deren Mitgliedschaft sich ändert,
            // muss frei von gebuchten Jahren sein — beim Zuordnen die Zielgruppe,
            // beim Trennen und beim Gruppenwechsel auch die verlassenen Gruppen.
            $target = $groupId === null ? null : EurAssetGroup::forCurrentBrand()->findOrFail($groupId);
            if ($target !== null) {
                $this->assertGroupMutable($target);
            }

            $affectedGroupIds = [];
            foreach ($assets as $asset) {
                if ($asset->eur_asset_group_id === null || (int) $asset->eur_asset_group_id === $groupId) {
                    continue;
                }
                $affectedGroupIds[(int) $asset->eur_asset_group_id] = true;
            }
            foreach (array_keys($affectedGroupIds) as $affectedGroupId) {
                $this->assertGroupMutable(EurAssetGroup::forCurrentBrand()->findOrFail($affectedGroupId));
            }

            if ($groupId !== null) {
                $group = $target;

                foreach ($assets as $asset) {
                    try {
                        $this->groupService->assertAssignable($asset);
                    } catch (InvalidArgumentException $exception) {
                        throw new EurDocumentPositionException(
                            EurDocumentPositionException::BOOKED_MESSAGE,
                            0,
                            $exception,
                        );
                    }
                }

                foreach ($assets as $asset) {
                    if ($asset->eur_asset_group_id === $groupId) {
                        continue;
                    }

                    if ($asset->eur_asset_group_id !== null) {
                        $affectedGroupIds[(int) $asset->eur_asset_group_id] = true;
                        $asset->update(['eur_asset_group_id' => null]);
                        // Ein Wechsel zwischen Gruppen stellt kurz die
                        // Einzelbuchung wieder her; die Dienste sind idempotent.
                        $this->groupService->restoreIndividualBookkeeping($asset);
                    }

                    $this->groupService->removeIndividualBookkeeping($asset);
                    $asset->update([
                        'eur_asset_group_id' => $group->id,
                        'scope' => (int) $group->business_share < 50 ? 'private' : 'business',
                    ]);
                }
            } else {
                foreach ($assets as $asset) {
                    if ($asset->eur_asset_group_id === null) {
                        continue;
                    }

                    $affectedGroupIds[(int) $asset->eur_asset_group_id] = true;
                    $asset->update([
                        'eur_asset_group_id' => null,
                        'scope' => (int) $asset->business_share < 50 ? 'private' : 'business',
                    ]);
                    $this->groupService->restoreIndividualBookkeeping($asset);
                }
            }

            foreach (array_keys($affectedGroupIds) as $affectedGroupId) {
                $affected = EurAssetGroup::forCurrentBrand()->find($affectedGroupId);
                if ($affected !== null) {
                    $this->groupService->sync($affected->refresh());
                }
            }

            if ($groupId !== null) {
                $this->groupService->sync(EurAssetGroup::forCurrentBrand()->findOrFail($groupId)->refresh());
            }

            $this->audit($groupId === null ? 'group_detached' : 'group_assigned', $document, [
                'group_id' => $groupId,
                'asset_ids' => array_map(static fn (EurAsset $asset): int => (int) $asset->id, $assets),
            ]);
        });
    }

    private function assertGroupMutable(EurAssetGroup $group): void
    {
        try {
            $this->groupService->assertGroupMutable($group);
        } catch (InvalidArgumentException $exception) {
            throw new EurDocumentPositionException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeDocument(EurDocument $document): array
    {
        $rows = EurAssetDocument::forCurrentBrand()
            ->where('eur_document_id', $document->id)
            ->orderBy('ordinal')
            ->orderBy('id')
            ->get();
        $assets = EurAsset::forCurrentBrand()
            ->whereIn('id', $rows->pluck('eur_asset_id'))
            ->get()
            ->keyBy('id');

        $positions = [];
        foreach ($rows as $row) {
            // Reine D-85-Verknüpfungen ohne Positionsdaten bleiben unsichtbar.
            if ($row->label === null && $row->amount_cents === null && $row->ordinal === null) {
                continue;
            }

            $asset = $assets->get($row->eur_asset_id);
            $positions[] = [
                'id' => (int) $row->id,
                'asset_id' => (int) $row->eur_asset_id,
                'asset_name' => $asset?->name,
                'label' => $row->label,
                'amount_cents' => $row->amount_cents === null ? null : (int) $row->amount_cents,
                'ordinal' => $row->ordinal === null ? null : (int) $row->ordinal,
            ];
        }

        $totals = $this->allocationTotals($positions, $document->total_cents, false);

        // `storage_path` und `signed_storage_path` verlassen die API nie.
        return [
            'id' => (int) $document->id,
            'receipt_no' => $document->receipt_no,
            'original_name' => $document->original_name,
            'total_cents' => $document->total_cents === null ? null : (int) $document->total_cents,
            'allocated_cents' => $totals['allocated_cents'],
            'remaining_cents' => $totals['remaining_cents'],
            'positions' => $positions,
        ];
    }

    private function hasPositions(EurDocument $document): bool
    {
        return EurAssetDocument::forCurrentBrand()
            ->where('eur_document_id', $document->id)
            ->where(static function ($query): void {
                $query->whereNotNull('label')
                    ->orWhereNotNull('amount_cents')
                    ->orWhereNotNull('ordinal');
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>
     */
    private function validatePosition(array $position): array
    {
        $name = $position['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            throw new EurDocumentPositionException('Jede Position benötigt einen Namen.');
        }

        $amount = $position['amount_cents'] ?? null;
        if (! is_int($amount) || $amount <= 0) {
            throw new EurDocumentPositionException('Der Positionsbetrag muss eine positive ganze Cent-Zahl sein.');
        }

        $share = $position['business_share'] ?? null;
        if (! is_int($share) || $share < 0 || $share > 100 || $share % 5 !== 0) {
            throw new EurDocumentPositionException('Der Betriebsanteil muss 0–100 in 5-%-Schritten sein.');
        }

        $life = $position['useful_life_years'] ?? null;
        if (! is_int($life) || $life < 1 || $life > 50) {
            throw new EurDocumentPositionException('Die Nutzungsdauer muss 1–50 Jahre betragen.');
        }

        $method = $position['method'] ?? null;
        if (! in_array($method, self::METHODS, true)) {
            throw new EurDocumentPositionException('Die AfA-Methode muss linear oder degressiv sein.');
        }

        $label = $position['label'] ?? null;
        if ($label !== null && ! is_string($label)) {
            throw new EurDocumentPositionException('Das Positionslabel muss eine Zeichenkette sein.');
        }

        $ordinal = $position['ordinal'] ?? null;
        if ($ordinal !== null && (! is_int($ordinal) || $ordinal < 0)) {
            throw new EurDocumentPositionException('Die Positionsreihenfolge muss eine nichtnegative ganze Zahl sein.');
        }

        $rate = $position['degressive_rate'] ?? null;
        if ($rate !== null && (! is_int($rate) || $rate < 100 || $rate > 3000)) {
            throw new EurDocumentPositionException('Der degressive Satz muss zwischen 100 und 3000 Basispunkten liegen.');
        }

        return [
            'name' => $name,
            'label' => $label,
            'amount_cents' => $amount,
            'business_share' => $share,
            'useful_life_years' => $life,
            'method' => $method,
            'degressive_rate' => $rate,
            'ordinal' => $ordinal,
        ];
    }

    private function purchaseDate(EurDocument $document): string
    {
        $year = $document->year === null ? EurYear::DEFAULT : (int) $document->year;
        $month = $document->month === null ? 1 : (int) $document->month;

        return sprintf('%04d-%02d-15', $year, max(1, min(12, $month)));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $action, EurDocument $document, array $extra = []): void
    {
        EurAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'eur_document',
            'subject_id' => $document->id,
            'payload' => array_merge([
                'receipt_no' => $document->receipt_no,
                'total_cents' => $document->total_cents,
            ], $extra),
        ]);
    }
}
