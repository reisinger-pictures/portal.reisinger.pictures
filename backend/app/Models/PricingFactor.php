<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PricingFactor extends Model
{
    use HasUuids;

    protected $table = 'pricing_factors';

    protected $fillable = [
        'type',
        'name',
        'multiplier'
    ];

    protected $casts = [
        'multiplier' => 'float',
    ];
}
