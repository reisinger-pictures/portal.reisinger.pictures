<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * EAR · Belegmetadaten. Die Datei liegt ausschließlich im privaten Storage;
 * der Pfad wird nie öffentlich ausgeliefert.
 */
class EurDocument extends Model
{
    protected $fillable = [
        'brand', 'year', 'month', 'receipt_no', 'original_name', 'storage_path',
        'mime_type', 'size_bytes', 'sha256', 'status',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'month' => 'integer',
        'size_bytes' => 'integer',
    ];

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
