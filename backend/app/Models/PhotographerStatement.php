<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PhotographerStatement extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id', 'sequence_number', 'month', 'year', 
        'total_shares_earned', 'pool_earnings_cents', 'delta_surcharge_earnings_cents', 
        'earned_amount_cents', 'rolled_over_amount_cents', 'total_payable_cents', 'status'
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'total_shares_earned' => 'decimal:4',
        'pool_earnings_cents' => 'integer',
        'delta_surcharge_earnings_cents' => 'integer',
        'earned_amount_cents' => 'integer',
        'rolled_over_amount_cents' => 'integer',
        'total_payable_cents' => 'integer',
        'status' => 'string',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->sequence_number)) {
                $model->sequence_number = 'ST-' . $model->year . '-' . str_pad($model->month, 2, '0', STR_PAD_LEFT) . '-' . strtoupper(\Illuminate\Support\Str::random(6));
            }
        });
    }
}
