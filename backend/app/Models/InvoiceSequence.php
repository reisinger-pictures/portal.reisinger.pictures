<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class InvoiceSequence extends Model
{
    public $timestamps = false;

    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'year',
        'current_value',
    ];

    public static function getNextInvoiceNumber($prefix = 'P-'): string
    {
        return DB::transaction(function () use ($prefix) {
            $year = (int) date('Y');

            try {
                // Race-safe first insert: `INSERT IGNORE` tolerates the concurrent
                // insert of the same (year) primary key instead of raising a
                // duplicate-key 500. The following lockForUpdate() then serialises
                // all concurrent increments on the now-guaranteed row.
                self::insertOrIgnore(['year' => $year, 'current_value' => 0]);
                $sequence = self::lockForUpdate()->findOrFail($year);
            } catch (QueryException $e) {
                if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'lock wait timeout')) {
                    throw new HttpResponseException(
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
