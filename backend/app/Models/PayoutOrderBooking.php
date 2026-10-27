<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayoutOrderBooking extends Model
{
    use HasUuids;

    protected $fillable = [
        'order_id',
        'user_id',
        'statement_id',
        'month',
        'year',
        'share_cents',
        'photo_revenue_cents',
        'refunded_cents',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'share_cents' => 'integer',
        'photo_revenue_cents' => 'integer',
        'refunded_cents' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(PhotographerStatement::class);
    }
}
