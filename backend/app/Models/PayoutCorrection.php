<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutCorrection extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'user_id',
        'refund_amount_cents',
        'amount_cents',
        'closed_month',
        'closed_year',
        'settled_at',
        'settled_month',
        'settled_year',
    ];

    protected $casts = [
        'refund_amount_cents' => 'integer',
        'amount_cents' => 'integer',
        'closed_month' => 'integer',
        'closed_year' => 'integer',
        'settled_month' => 'integer',
        'settled_year' => 'integer',
        'settled_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
