<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks per-user usage of coupons (SRP-01 Phase A-2).
 *
 * @property int $id
 * @property int $coupon_id
 * @property int $user_id
 * @property int $used_count
 */
class CouponUserUsage extends Model
{
    protected $table = 'coupon_user_usage';

    public $timestamps = false;

    protected $fillable = [
        'coupon_id',
        'user_id',
        'used_count',
    ];

    protected $casts = [
        'coupon_id' => 'integer',
        'used_count' => 'integer',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
