<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Führe die Bereinigung täglich um 03:00 Uhr nachts aus
Schedule::command('app:cleanup-galleries')->dailyAt('03:00');

// Storage Lifecycle & Cache Registry
Schedule::command('app:downscale-editorial')->dailyAt('04:00');
Schedule::command('app:cleanup-derivatives')->dailyAt('05:00');

// Storage Lifecycle & Cache Registry
Schedule::command('app:downscale-editorial')->dailyAt('04:00');
Schedule::command('app:cleanup-derivatives')->dailyAt('05:00');
