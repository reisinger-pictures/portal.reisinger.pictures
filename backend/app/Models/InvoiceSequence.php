<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class InvoiceSequence extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'year';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'year',
        'current_value'
    ];

    public static function getNextInvoiceNumber($prefix = 'P-'): string
    {
        return DB::transaction(function () use ($prefix) {
            $year = (int) date('Y');
            
            try {
                $sequence = self::lockForUpdate()->firstOrCreate(
                    ['year' => $year],
                    ['current_value' => 0]
                );
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'lock wait timeout')) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException(
                        response()->json(['error' => 'Server ist derzeit überlastet. Bitte versuche es in einigen Sekunden erneut.'], 503)
                    );
                }
                throw $e;
            }

            $sequence->current_value += 1;
            $sequence->save();

            return sprintf('%s%04d-%04d', $prefix, $year, $sequence->current_value);
        });
    }
}
