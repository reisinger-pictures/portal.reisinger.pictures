<?php

namespace App\Models;

use App\Casts\AsBrand;
use App\Support\BrandRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** EAR · Signaturstatus eines Monats (Testprovider ist gekennzeichnet). */
class EaSignature extends Model
{
    protected $fillable = [
        'brand', 'year', 'month', 'ea_document_id', 'provider', 'status',
        'signed_at', 'note',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'year' => 'integer',
        'month' => 'integer',
        'signed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(EaDocument::class, 'ea_document_id');
    }

    public function scopeForCurrentBrand(Builder $query): Builder
    {
        return $query->where($query->getQuery()->from.'.brand', BrandRegistry::currentId());
    }
}
