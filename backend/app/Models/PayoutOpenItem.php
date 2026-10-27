<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PayoutOpenItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'source_type',
        'source_id',
        'order_id',
        'origin_month',
        'origin_year',
        'amount_cents',
        'settled_month',
        'settled_year',
        'settled_at',
    ];

    protected $casts = [
        'origin_month' => 'integer',
        'origin_year' => 'integer',
        'amount_cents' => 'integer',
        'settled_month' => 'integer',
        'settled_year' => 'integer',
        'settled_at' => 'datetime',
    ];
}
