<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InvoiceSequence extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'year',
        'current_value'
    ];

    public static function getNextInvoiceNumber($prefix = 'P-'): string
    {
        return DB::transaction(function () use ($prefix) {
            $year = (int) date('Y');
            
            $sequence = self::lockForUpdate()->firstOrCreate(
                ['year' => $year],
                ['current_value' => 0]
            );

            $sequence->current_value += 1;
            $sequence->save();

            return sprintf('%s%04d-%04d', $prefix, $year, $sequence->current_value);
        });
    }
}
