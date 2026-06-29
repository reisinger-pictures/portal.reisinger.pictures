<?php

namespace App\Models;

use App\Enums\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InvoiceSnapshot extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;
    
    protected $primaryKey = 'invoice_number';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'order_id',
        'invoice_number',
        'brand',
        'customer_details',
        'total_net',
        'total_gross',
        'tax_rate'
    ];

    protected $casts = [
        'customer_details' => 'array',
        'total_net' => 'integer',
        'total_gross' => 'integer',
        'tax_rate' => 'decimal:2',
        'brand' => Brand::class,
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
