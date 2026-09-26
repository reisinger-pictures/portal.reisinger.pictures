<?php

namespace App\Support;

use Carbon\Carbon;

class AgeHelper
{
    /**
     * Number of completed years between `birthdate` and `referenceDate`.
     *
     * Carbon 3 returns a signed float from `diffInYears()`; the result is
     * floored to completed years. A reference date before the birthdate has no
     * meaningful age and yields `null`.
     */
    public static function calculate(?string $birthdate, ?string $referenceDate = null): ?int
    {
        if ($birthdate === null) {
            return null;
        }

        $birth = Carbon::parse($birthdate);
        $ref = $referenceDate ? Carbon::parse($referenceDate) : Carbon::today();

        $diff = $birth->diffInYears($ref, false);

        if ($diff < 0) {
            return null;
        }

        return (int) floor($diff);
    }

    public static function format(?string $birthdate, ?string $referenceDate = null): ?string
    {
        $age = self::calculate($birthdate, $referenceDate);
        if ($age === null) {
            return null;
        }

        $formatted = Carbon::parse($birthdate)->format('d.m.Y');

        return "Alter: {$age} Jahre (geb. {$formatted})";
    }
}
