<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Order extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'status',
        'total_amount',
        'is_quote_request',
        'ip_address',
        'stripe_payment_intent_id',
        'quote_status'
    ];

    protected $casts = [
        'total_amount' => 'integer',
        'is_quote_request' => 'boolean',
    ];

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
