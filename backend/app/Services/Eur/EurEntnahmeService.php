<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Support\EurAssetPositionKey;
use App\Eur\Support\Share;
use App\Models\EurAsset;
use App\Models\EurEntry;
use Carbon\Carbon;

/**
 * EAR · automatische Entnahme-Buchung bei Anschaffung (EUE-055, 2026-11-24).
 *
 * Hat ein Anlagegut einen Privatanteil, verlangt die gepinnte Regel eine
 * Buchung „Entnahme" im Einnahmenjournal unter **derselben Belegnummer** wie
 * das Anlagegut. Der Betrag ist `Kosten × Privatanteil` in ganzen Cent
 * (`ROUND(S3*(1-bet),2)`, S3 = Brutto-Kosten). Es ist eine einmalige Buchung
 * bei Anschaffung — die laufende Privatnutzung wird nicht erneut gebucht.
 *
 * Kennzahl 9040 („Betriebseinnahmen / Private Entnahme"): 9060 beschreibt
 * laut gepinnter Ausfüllhilfe den Erlös eines **abgegangenen** Anlageguts und
 * ist hier nicht einschlägig.
 *
 * Der Service läuft beim Anlegen des Anlageguts ohne Benutzeraktion. Er ist
 * über Belegnummer + Art idempotent; eine bereits vorhandene Entnahme wird
 * nie verdoppelt. Alle Beträge bleiben ganze Cent.
 */
final class EurEntnahmeService
{
    public const KIND = 'Entnahme';

    public const CODE = '9040';

    /**
     * Portal #59/D-90: beim Beleg-Split teilen sich mehrere Anlagegüter
     * dieselbe Belegnummer (`invoice_no`). Ein optionaler Positionsschlüssel
     * hält die Entnahme je Position idempotent, ohne die Belegnummer zu
     * verändern oder eine zweite Regelquelle zu schaffen. Ohne Schlüssel
     * bleibt das bestehende Verhalten (eine Entnahme je Belegnummer) exakt
     * erhalten.
     */
    public function createForAsset(EurAsset $asset, ?string $positionKey = null): ?EurEntry
    {
        $privatePercent = 100 - (int) $asset->business_share;

        if ($privatePercent <= 0) {
            return null;
        }

        $description = 'Entnahme bei Anschaffung: '.$asset->name;
        if ($positionKey !== null) {
            $description .= ' (Position '.$positionKey.')';
        }

        $query = EurEntry::forCurrentBrand()
            ->where('receipt_no', $asset->invoice_no)
            ->where('kind_type', self::KIND);

        if ($positionKey !== null) {
            $query->where('description', $description);
        }

        $existing = $query->first();

        if ($existing !== null) {
            return $existing;
        }

        $cents = Share::apply((int) $asset->cost_cents, $privatePercent);
        $date = Carbon::parse($asset->purchase_date);

        return EurEntry::create([
            'brand' => $asset->brand,
            'year' => (int) $date->format('Y'),
            'month' => (int) $date->format('n'),
            'day' => (int) $date->format('j'),
            'direction' => 'income',
            'kind_type' => self::KIND,
            'category' => self::KIND,
            'description' => $description,
            'e1a_code' => self::CODE,
            'business_share' => 100,
            'amount_cents' => $cents,
            'business_amount_cents' => $cents,
            'receipt_no' => $asset->invoice_no,
            'note' => sprintf('Automatische Entnahme EUE-055 (%d %% privat)', $privatePercent),
        ]);
    }

    /**
     * Portal #59/D-90: der Status muss dieselbe Position meinen wie Anlage und
     * Löschung. Split-Geschwister teilen die Belegnummer; ohne den
     * Positionsschlüssel würde eine gelöschte Entnahme als vorhanden gemeldet.
     * Ohne Pivot bleibt die Suche exakt beim klassischen Verhalten.
     *
     * @return array{done: bool, entry_id: int|null, amount_cents: int|null}
     */
    public function status(EurAsset $asset): array
    {
        $query = EurEntry::forCurrentBrand()
            ->where('receipt_no', $asset->invoice_no)
            ->where('kind_type', self::KIND);

        $positionKey = EurAssetPositionKey::forAsset($asset);
        if ($positionKey !== null) {
            $query->where('description', 'like', '% (Position '.$positionKey.')');
        }

        $entry = $query->first();

        return [
            'done' => $entry !== null,
            'entry_id' => $entry === null ? null : (int) $entry->id,
            'amount_cents' => $entry === null ? null : (int) $entry->amount_cents,
        ];
    }
}
