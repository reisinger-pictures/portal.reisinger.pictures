<?php

namespace App\Models;

use App\Enums\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'status',
        'brand',
        'total_amount',
        'stripe_fee_cents',
        'coupon_id',
        'coupon_discount_cents',
        'is_quote_request',
        'ip_address',
        'stripe_payment_intent_id',
        'quote_status'
    ];

    protected $casts = [
        'total_amount' => 'integer',
        'stripe_fee_cents' => 'integer',
        'coupon_discount_cents' => 'integer',
        'is_quote_request' => 'boolean',
        'brand' => Brand::class,
    ];

    public function coupon()
    {
        return $this->belongsTo(Coupon::class);
    }

    protected static function booted()
    {
        static::saving(function ($order) {
            $allowedStatuses = ['pending', 'invoice_created', 'pending_payment', 'paid', 'overdue', 'cancelled', 'disputed', 'refunded', 'delivery_note', 'archived_in_collective'];
            if (!in_array($order->status, $allowedStatuses)) {
                throw new \InvalidArgumentException("Ungültiger Bestellstatus: {$order->status}");
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoiceSnapshot()
    {
        return $this->hasOne(InvoiceSnapshot::class);
    }
}
