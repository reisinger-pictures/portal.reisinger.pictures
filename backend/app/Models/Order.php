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
        'is_quote_request'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'is_quote_request' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoiceSnapshot()
    {
        return $this->hasOne(InvoiceSnapshot::class);
    }
}
