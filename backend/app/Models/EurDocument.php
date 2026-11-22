<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * EAR · Belegmetadaten. Die Datei liegt ausschließlich im privaten Storage;
 * der Pfad des Ursprungsbelegs wird nie ausgeliefert. Der Pfad der signierten
 * Fassung ist ein Metadatum des API-Vertrags, keine öffentliche URL.
 *
 * `signed_*` beschreibt die extern signierte Fassung. Deren Inhaltsbindung ist
 * eine Content-Identity-Prüfung (gleiche Ursprungsbytes), keine QES.
 */
class EurDocument extends Model
{
    protected $fillable = [
        'brand', 'year', 'month', 'receipt_no', 'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'sha256', 'status',
        'signed_storage_path', 'signed_sha256', 'signed_mime_type',
        'signed_size_bytes', 'signed_at',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'month' => 'integer',
        'size_bytes' => 'integer',
        'signed_size_bytes' => 'integer',
        'signed_at' => 'datetime',
    ];

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
