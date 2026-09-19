<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Personen-Foto eines Model-Profils (max. 5 je Customer).
 *
 * Die Datei liegt verschlüsselt auf der privaten `local`-Disk; `path` ist ein
 * zufälliger, nicht nutzerkontrollierter Pfad. Die Auslieferung erfolgt
 * ausschließlich über den auth-gated Management-Endpunkt.
 */
class ModelPhoto extends Model
{
    use HasFactory, HasUuids;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_INTERNAL = 'internal';

    protected $fillable = [
        'customer_id',
        'model_profile_id',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'visibility',
        'is_primary',
        'position',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'size_bytes' => 'integer',
        'position' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function modelProfile(): BelongsTo
    {
        return $this->belongsTo(ModelProfile::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('visibility', self::VISIBILITY_PUBLIC);
    }
}
