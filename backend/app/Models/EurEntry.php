<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EAR · Buchung (Einnahme oder Ausgabe) eines Monats.
 *
 * Geld ist ausschließlich in ganzen Cent gespeichert; der Betriebsanteil ist
 * eine ganze Prozentzahl in 5-%-Schritten. Der betriebliche Betrag wird beim
 * Schreiben aus Betrag und Anteil berechnet und mitgespeichert, damit
 * Auswertungen keine Float-Werte bilden müssen.
 */
class EurEntry extends Model
{
    protected $fillable = [
        'brand', 'year', 'month', 'day', 'direction', 'kind_type', 'category',
        'description', 'e1a_code', 'business_share', 'amount_cents',
        'business_amount_cents', 'vat_kind', 'payment_method', 'eur_project_id',
        'eur_customer_id', 'receipt_no', 'external_id', 'note',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'month' => 'integer',
        'day' => 'integer',
        'business_share' => 'integer',
        'amount_cents' => 'integer',
        'business_amount_cents' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(EurProject::class, 'eur_project_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(EurCustomer::class, 'eur_customer_id');
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }
}
