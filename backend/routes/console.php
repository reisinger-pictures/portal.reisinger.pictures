<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Keep scheduler mutexes on the configured shared store. The production
// operations policy rejects file/array stores when onOneServer() is used.
Schedule::useCache(config('cache.default'));

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Führe die Bereinigung täglich um 03:00 Uhr nachts aus
Schedule::command('app:cleanup-galleries')->dailyAt('03:00')->withoutOverlapping()->onOneServer();

// Board-Cleanup (Projekte + Photo-Jobs in Endstatus)
Schedule::command('app:cleanup-board-items')->dailyAt('06:00')->withoutOverlapping()->onOneServer();

// Storage Lifecycle & Cache Registry
Schedule::command('app:downscale-editorial')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
Schedule::command('app:cleanup-derivatives')->dailyAt('05:00')->withoutOverlapping()->onOneServer();

// Temp-Dateien (alte Skalierungs-Caches & Artefakte) bereinigen
Schedule::command('app:cleanup-temp')->dailyAt('02:30')->withoutOverlapping()->onOneServer();

// GeoNames- und Länderdaten einmal pro Woche aktualisieren. Der Import darf
// weder den Seed-Gate blockieren noch bei jedem Container-Neustart laufen.
Schedule::command('app:import-locations')->weeklyOn(1, '03:30')->withoutOverlapping()->onOneServer();

// Stripe: cancel incomplete PaymentIntents abandoned by abandoned checkouts
Schedule::command('stripe:cancel-stale-payment-intents')->hourly()->withoutOverlapping()->onOneServer();

// Model-Lifecycle: Reminder-Mails T+12/13/14 Monate (idempotent, brand-aware)
Schedule::command('app:process-model-lifecycle')->dailyAt('07:00')->withoutOverlapping()->onOneServer();

// Automatische Sammelrechnungen (monatlich)
Schedule::command('app:process-collective-invoices --frequency=monthly')->monthly()->withoutOverlapping()->onOneServer();

// Automatische Sammelrechnungen (quartalsweise)
Schedule::command('app:process-collective-invoices --frequency=quarterly')->quarterly()->withoutOverlapping()->onOneServer();
