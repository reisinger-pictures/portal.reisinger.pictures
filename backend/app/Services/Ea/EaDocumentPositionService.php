<?php

declare(strict_types=1);

namespace App\Services\Ea;

use App\Ea\Domain\DepreciationCalculator;
use App\Ea\Reference\EaYear;
use App\Exceptions\EaDocumentPositionException;
use App\Models\EaAsset;
use App\Models\EaAssetDocument;
use App\Models\EaAssetGroup;
use App\Models\EaAuditLog;
use App\Models\EaDocument;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * EAR · Beleg-Positionen: Split und Merge sind zwei Richtungen derselben
 * Verknüpfung (Portal #59, D-90).
 *
 * Eine Position ist eine angereicherte Zeile der bestehenden Pivot
 * `ea_asset_documents`; es gibt keine zweite Wahrheitsquelle. Der Split
 * legt je Position ein eigenes Anlagegut an und benutzt dafür exakt denselben
 * Anlagepfad wie `/assets`. Der Merge ordnet alle mit den Belegen verknüpften
 * Güter einer bestehenden Gruppe zu (eine Abrechnungseinheit je D-89).
 * Alle Geldbeträge sind ganze Cent, alle Abfragen brand-scoped.
 */
final class EaDocumentPositionService
{
    private const METHODS = [DepreciationCalculator::METHOD_LINEAR, DepreciationCalculator::METHOD_DEGRESSIVE];

    public function __construct(
        private readonly EaAssetCreationService $creationService,
        private readonly EaAssetGroupService $groupService,
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
            throw new EaDocumentPositionException(EaDocumentPositionException::OVER_ALLOCATION_MESSAGE);
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
     * @return list<EaAssetDocument>
     */
    public function createPositions(EaDocument $document, array $positions, ?int $totalCents): array
    {
        if ($this->hasPositions($document)) {
            throw new EaDocumentPositionException(EaDocumentPositionException::DOUBLE_SPLIT_MESSAGE);
        }

        if ($positions === []) {
            throw new EaDocumentPositionException('Ein Beleg benötigt mindestens eine Position.');
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
                    'ea_asset_group_id' => null,
                ], null, $positionKey);

                $created[] = EaAssetDocument::create([
                    'brand' => BrandRegistry::currentId(),
                    'ea_asset_id' => $asset->id,
                    'ea_document_id' => $document->id,
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
        $pivot = EaAssetDocument::forCurrentBrand()->findOrFail($pivotId);
        $document = EaDocument::forCurrentBrand()->findOrFail($pivot->ea_document_id);

        DB::transaction(function () use ($pivot, $document, $pivotId): void {
            $assetId = (int) $pivot->ea_asset_id;
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
    public function assignGroup(EaDocument $document, ?int $groupId): void
    {
        $assetIds = EaAssetDocument::forCurrentBrand()
            ->where('ea_document_id', $document->id)
            ->pluck('ea_asset_id');
        $assets = EaAsset::forCurrentBrand()->whereIn('id', $assetIds)->orderBy('id')->get()->all();

        DB::transaction(function () use ($document, $assets, $groupId): void {
            // D-89 fail closed: jede Gruppe, deren Mitgliedschaft sich ändert,
            // muss frei von gebuchten Jahren sein — beim Zuordnen die Zielgruppe,
            // beim Trennen und beim Gruppenwechsel auch die verlassenen Gruppen.
            $target = $groupId === null ? null : EaAssetGroup::forCurrentBrand()->findOrFail($groupId);
            if ($target !== null) {
                $this->assertGroupMutable($target);
            }

            $affectedGroupIds = [];
            foreach ($assets as $asset) {
                if ($asset->ea_asset_group_id === null || (int) $asset->ea_asset_group_id === $groupId) {
                    continue;
                }
                $affectedGroupIds[(int) $asset->ea_asset_group_id] = true;
            }
            foreach (array_keys($affectedGroupIds) as $affectedGroupId) {
                $this->assertGroupMutable(EaAssetGroup::forCurrentBrand()->findOrFail($affectedGroupId));
            }

            if ($groupId !== null) {
                $group = $target;

                foreach ($assets as $asset) {
                    try {
                        $this->groupService->assertAssignable($asset);
                    } catch (InvalidArgumentException $exception) {
                        throw new EaDocumentPositionException(
                            EaDocumentPositionException::BOOKED_MESSAGE,
                            0,
                            $exception,
                        );
                    }
                }

                foreach ($assets as $asset) {
                    if ($asset->ea_asset_group_id === $groupId) {
                        continue;
                    }

                    if ($asset->ea_asset_group_id !== null) {
                        $affectedGroupIds[(int) $asset->ea_asset_group_id] = true;
                        $asset->update(['ea_asset_group_id' => null]);
                        // Ein Wechsel zwischen Gruppen stellt kurz die
                        // Einzelbuchung wieder her; die Dienste sind idempotent.
                        $this->groupService->restoreIndividualBookkeeping($asset);
                    }

                    $this->groupService->removeIndividualBookkeeping($asset);
                    $asset->update([
                        'ea_asset_group_id' => $group->id,
                        'scope' => (int) $group->business_share < 50 ? 'private' : 'business',
                    ]);
                }
            } else {
                foreach ($assets as $asset) {
                    if ($asset->ea_asset_group_id === null) {
                        continue;
                    }

                    $affectedGroupIds[(int) $asset->ea_asset_group_id] = true;
                    $asset->update([
                        'ea_asset_group_id' => null,
                        'scope' => (int) $asset->business_share < 50 ? 'private' : 'business',
                    ]);
                    $this->groupService->restoreIndividualBookkeeping($asset);
                }
            }

            foreach (array_keys($affectedGroupIds) as $affectedGroupId) {
                $affected = EaAssetGroup::forCurrentBrand()->find($affectedGroupId);
                if ($affected !== null) {
                    $this->groupService->sync($affected->refresh());
                }
            }

            if ($groupId !== null) {
                $this->groupService->sync(EaAssetGroup::forCurrentBrand()->findOrFail($groupId)->refresh());
            }

            $this->audit($groupId === null ? 'group_detached' : 'group_assigned', $document, [
                'group_id' => $groupId,
                'asset_ids' => array_map(static fn (EaAsset $asset): int => (int) $asset->id, $assets),
            ]);
        });
    }

    private function assertGroupMutable(EaAssetGroup $group): void
    {
        try {
            $this->groupService->assertGroupMutable($group);
        } catch (InvalidArgumentException $exception) {
            throw new EaDocumentPositionException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeDocument(EaDocument $document): array
    {
        $rows = EaAssetDocument::forCurrentBrand()
            ->where('ea_document_id', $document->id)
            ->orderBy('ordinal')
            ->orderBy('id')
            ->get();
        $assets = EaAsset::forCurrentBrand()
            ->whereIn('id', $rows->pluck('ea_asset_id'))
            ->get()
            ->keyBy('id');

        $positions = [];
        foreach ($rows as $row) {
            // Reine D-85-Verknüpfungen ohne Positionsdaten bleiben unsichtbar.
            if ($row->label === null && $row->amount_cents === null && $row->ordinal === null) {
                continue;
            }

            $asset = $assets->get($row->ea_asset_id);
            $positions[] = [
                'id' => (int) $row->id,
                'asset_id' => (int) $row->ea_asset_id,
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

    private function hasPositions(EaDocument $document): bool
    {
        return EaAssetDocument::forCurrentBrand()
            ->where('ea_document_id', $document->id)
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
            throw new EaDocumentPositionException('Jede Position benötigt einen Namen.');
        }

        $amount = $position['amount_cents'] ?? null;
        if (! is_int($amount) || $amount <= 0) {
            throw new EaDocumentPositionException('Der Positionsbetrag muss eine positive ganze Cent-Zahl sein.');
        }

        $share = $position['business_share'] ?? null;
        if (! is_int($share) || $share < 0 || $share > 100 || $share % 5 !== 0) {
            throw new EaDocumentPositionException('Der Betriebsanteil muss 0–100 in 5-%-Schritten sein.');
        }

        $life = $position['useful_life_years'] ?? null;
        if (! is_int($life) || $life < 1 || $life > 50) {
            throw new EaDocumentPositionException('Die Nutzungsdauer muss 1–50 Jahre betragen.');
        }

        $method = $position['method'] ?? null;
        if (! in_array($method, self::METHODS, true)) {
            throw new EaDocumentPositionException('Die AfA-Methode muss linear oder degressiv sein.');
        }

        $label = $position['label'] ?? null;
        if ($label !== null && ! is_string($label)) {
            throw new EaDocumentPositionException('Das Positionslabel muss eine Zeichenkette sein.');
        }

        $ordinal = $position['ordinal'] ?? null;
        if ($ordinal !== null && (! is_int($ordinal) || $ordinal < 0)) {
            throw new EaDocumentPositionException('Die Positionsreihenfolge muss eine nichtnegative ganze Zahl sein.');
        }

        $rate = $position['degressive_rate'] ?? null;
        if ($rate !== null && (! is_int($rate) || $rate < 100 || $rate > 3000)) {
            throw new EaDocumentPositionException('Der degressive Satz muss zwischen 100 und 3000 Basispunkten liegen.');
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

    private function purchaseDate(EaDocument $document): string
    {
        $year = $document->year === null ? EaYear::currentOrLast() : (int) $document->year;
        $month = $document->month === null ? 1 : (int) $document->month;

        return sprintf('%04d-%02d-15', $year, max(1, min(12, $month)));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(string $action, EaDocument $document, array $extra = []): void
    {
        EaAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'ea_document',
            'subject_id' => $document->id,
            'payload' => array_merge([
                'receipt_no' => $document->receipt_no,
                'total_cents' => $document->total_cents,
            ], $extra),
        ]);
    }
}
