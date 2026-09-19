<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot: Customer (Person) innerhalb eines Acts inkl. Rolle und Position.
 */
class ActMember extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'act_id',
        'customer_id',
        'role',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    public function act(): BelongsTo
    {
        return $this->belongsTo(Act::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
