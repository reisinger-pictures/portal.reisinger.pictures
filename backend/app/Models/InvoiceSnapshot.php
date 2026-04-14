<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceSnapshot extends Model
{
    public const UPDATED_AT = null;
    
    protected $primaryKey = 'invoice_number';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'invoice_number',
        'customer_details',
        'total_net',
        'total_gross',
        'tax_rate'
    ];

    protected $casts = [
        'customer_details' => 'array',
        'total_net' => 'decimal:2',
        'total_gross' => 'decimal:2',
        'tax_rate' => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    protected static function booted()
    {
        static::creating(function ($snapshot) {
            if (empty($snapshot->invoice_number)) {
                $order = $snapshot->order;
                if ($order && $order->is_quote_request) {
                    $snapshot->invoice_number = 'A-' . strtoupper(\Illuminate\Support\Str::random(8));
                } else {
                    $prefix = ($order && $order->status === 'delivery_note') ? 'L-' : 'P-';
                    $snapshot->invoice_number = \App\Models\InvoiceSequence::getNextInvoiceNumber($prefix);
                }
            }
        });
    }
}
