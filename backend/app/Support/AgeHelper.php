<?php

namespace App\Support;

use Carbon\Carbon;

class AgeHelper
{
    public static function calculate(?string $birthdate, ?string $referenceDate = null): ?int
    {
        if ($birthdate === null) {
            return null;
        }

        $birth = Carbon::parse($birthdate);
        $ref = $referenceDate ? Carbon::parse($referenceDate) : Carbon::today();

        return (int) $birth->diffInYears($ref);
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
