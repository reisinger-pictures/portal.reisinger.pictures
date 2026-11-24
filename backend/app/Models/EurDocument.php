<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EAR · Belegmetadaten. Die Datei liegt ausschließlich im privaten Storage;
 * der Pfad des Ursprungsbelegs wird nie ausgeliefert. Der Pfad der signierten
 * Fassung ist ein Metadatum des API-Vertrags, keine öffentliche URL.
 *
 * `signed_*` beschreibt die extern signierte Fassung. Deren Inhaltsbindung ist
 * eine Content-Identity-Prüfung (gleiche Ursprungsbytes), keine QES.
 *
 * Portal #59/D-90: `total_cents` ist der optionale Belegbetrag in ganzen Cent;
 * die Positionen sind angereicherte Zeilen der bestehenden Pivot
 * `eur_asset_documents` (keine neue Haupttabelle).
 */
class EurDocument extends Model
{
    protected $fillable = [
        'brand', 'year', 'month', 'receipt_no', 'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'total_cents', 'sha256', 'status',
        'signed_storage_path', 'signed_sha256', 'signed_mime_type',
        'signed_size_bytes', 'signed_at',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'month' => 'integer',
        'size_bytes' => 'integer',
        'total_cents' => 'integer',
        'signed_size_bytes' => 'integer',
        'signed_at' => 'datetime',
    ];

    /**
     * Die Pivot-Zeilen selbst: eine Position ist genau eine dieser Zeilen.
     */
    public function assetDocuments(): HasMany
    {
        return $this->hasMany(EurAssetDocument::class, 'eur_document_id');
    }

    /** Alias für die positionsrelevante Sicht auf die Pivot. */
    public function positions(): HasMany
    {
        return $this->assetDocuments();
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
