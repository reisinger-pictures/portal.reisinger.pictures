<?php

namespace AppModels;

use IlluminateDatabaseEloquentModel;

class PricingFactor extends Model
{
    /**
     * The table associated with the model.
     * @var string
     */
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
