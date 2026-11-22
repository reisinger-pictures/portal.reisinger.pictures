<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * EAR · Audit-Eintrag für Schreibzugriffe auf Buchhaltungsdaten.
 *
 * Der Payload enthält nur fachliche Felder (IDs, Beträge, Aktionen) — keine
 * Dateiinhalte, keine Secrets und keine Tokens.
 */
class EurAuditLog extends Model
{
    protected $fillable = ['brand', 'user_id', 'action', 'subject_type', 'subject_id', 'payload'];

    protected $casts = [
        'brand' => AsBrand::class,
        'payload' => 'array',
        'subject_id' => 'integer',
    ];

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
