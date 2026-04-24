<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PayoutPool extends Model
{
    use HasUuids;

    protected $fillable = [
        'month', 'year', 'product_id', 'gross_amount_cents', 
        'stripe_fee_cents', 'net_pool_cents', 'photographer_share_percent', 
        'total_unique_downloads', 'total_shares', 'value_per_share_cents'
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'gross_amount_cents' => 'integer',
        'stripe_fee_cents' => 'integer',
        'net_pool_cents' => 'integer',
        'photographer_share_percent' => 'integer',
        'total_unique_downloads' => 'integer',
        'total_shares' => 'string',
        'value_per_share_cents' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
