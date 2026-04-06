<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class InvoiceSnapshot extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

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
}
