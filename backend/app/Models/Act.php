<?php

namespace App\Models;

use App\Casts\AsBrand;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Act = Bewerbungs-/Buchungseinheit aus 1..n Personen mit einer Managerperson.
 */
class Act extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'brand',
        'manager_customer_id',
        'act_type',
        'catalog_version',
        'answers',
        'person_count',
        'submitted_at',
    ];

    protected $casts = [
        'brand' => AsBrand::class,
        'answers' => 'array',
        'person_count' => 'integer',
        'submitted_at' => 'datetime',
    ];

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'manager_customer_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ActMember::class);
    }

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'act_members')->withPivot(['role', 'position']);
    }

    /**
     * act_type is always derived from the person count (single | couple | group).
     */
    public static function deriveType(int $personCount): string
    {
        return match (true) {
            $personCount <= 1 => 'single',
            $personCount === 2 => 'couple',
            default => 'group',
        };
    }
}
