<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · Persistenz für Reise- und Fahrtkosten (Portal #53, D-83a).
 *
 * Schema: neue Tabelle `eur_travel_trips` mit den Eingaben der bestehenden
 * `travel/calc`-Operation und den vom Server berechneten Cent-Beträgen. Wie in
 * `eur_asset_years` wird das Ergebnis beim Anlegen berechnet und gespeichert,
 * nicht bei jeder Anzeige neu erzeugt. Alle Beträge sind ganze Cent, Zeiten
 * ganze Minuten/ Tage, Anteile ganze Prozent in 5-%-Schritten. `brand` wird
 * gescoped; fremde IDs sind nicht adressierbar.
 *
 * Backfill: keiner. Die Tabelle ist neu und startet leer; vorherige Reisen
 * existierten nur als flüchtige Berechnung und werden nicht nachträglich
 * angelegt.
 *
 * Rollback: `down()` ist per Repository-Migrationspolicy absichtlich leer; die
 * Tabelle ist additiv und verändert keine bestehende Tabelle.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V067“.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eur_travel_trips')) {
            return;
        }

        Schema::create('eur_travel_trips', function (Blueprint $table): void {
            $table->id();
            $table->string('brand', 16)->index();
            $table->integer('year');
            $table->integer('month')->nullable();
            $table->string('vehicle', 64);
            $table->integer('kilometres');
            $table->integer('passengers')->default(0);
            $table->string('country', 190);
            $table->integer('days');
            $table->integer('meals');
            $table->boolean('with_receipts');
            $table->integer('business_share');
            $table->bigInteger('kilometre_cents');
            $table->bigInteger('day_cents');
            $table->bigInteger('night_cents');
            $table->bigInteger('total_cents');
            $table->bigInteger('business_cents');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['brand', 'year', 'month']);
        });
    }
};
