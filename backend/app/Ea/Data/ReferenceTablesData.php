<?php

declare(strict_types=1);

namespace App\Ea\Data;

/**
 * GENERIERT von `php artisan ea:export-data` aus dem gepinnten
 * Regelartefakt — nicht von Hand ändern. Die Quelldatei kennt nur der Generator.
 *
 * Referenzdaten (EUE-004 … EUE-022) bereits in den Zielwerten: Geld in
 * ganzen Cent, Anteile in ganzen Prozent, Sätze in Basispunkten. Die
 * Umrechnung passiert einmal beim Erzeugen, nicht im Rechenpfad.
 * Fassade ist `App\Ea\Reference\ReferenceTables`.
 */
final class ReferenceTablesData
{
    /**
     * @var array<string, mixed>
     */
    public const TABLES = [
        'expense_kinds' => [
            [
                'type' => 'Wareneinkäufe',
                'e1a' => '9100 - Waren',
                'share_percent' => null,
            ],
            [
                'type' => 'Rohstoffe',
                'e1a' => '9100 - Waren',
                'share_percent' => null,
            ],
            [
                'type' => 'Fertig- und Einzelteile',
                'e1a' => '9100 - Waren',
                'share_percent' => null,
            ],
            [
                'type' => 'Materialveredelungen',
                'e1a' => '9110 - Fremdpersonal',
                'share_percent' => null,
            ],
            [
                'type' => 'Freelancer',
                'e1a' => '9110 - Fremdpersonal',
                'share_percent' => null,
            ],
            [
                'type' => 'Laptop 40% privat',
                'e1a' => '9130 - Anlagegüter',
                'share_percent' => 60,
            ],
            [
                'type' => 'Drucker 40% privat',
                'e1a' => '9130 - Anlagegüter',
                'share_percent' => 60,
            ],
            [
                'type' => 'Firmen-Drucker',
                'e1a' => '9130 - Anlagegüter',
                'share_percent' => null,
            ],
            [
                'type' => 'Andere Anlagegüter',
                'e1a' => '9130 - Anlagegüter',
                'share_percent' => null,
            ],
            [
                'type' => 'Firmenwebsite',
                'e1a' => '9130 - Anlagegüter',
                'share_percent' => null,
            ],
            [
                'type' => 'Geringwertige Wirtschaftsgüter',
                'e1a' => '9130 - Anlagegüter',
                'share_percent' => null,
            ],
            [
                'type' => 'Restbuchwert verkaufter Anlagegüter',
                'e1a' => '9210 - Buchwert abgeg. Anlagegüter',
                'share_percent' => null,
            ],
            [
                'type' => 'Einzelne Zugfahrten',
                'e1a' => '9160 - Reisen, Fahrten und Diäten',
                'share_percent' => null,
            ],
            [
                'type' => 'Klimaticket',
                'e1a' => '9165 - 50% Pauschale Massenbeförderung',
                'share_percent' => 50,
            ],
            [
                'type' => 'Provisionen an Dritte',
                'e1a' => '9190 - Provisionen und Lizenzen',
                'share_percent' => null,
            ],
            [
                'type' => 'Software',
                'e1a' => '9190 - Provisionen und Lizenzen',
                'share_percent' => null,
            ],
            [
                'type' => 'Apps',
                'e1a' => '9190 - Provisionen und Lizenzen',
                'share_percent' => null,
            ],
            [
                'type' => 'SV-Beiträge',
                'e1a' => '9225 - Pflichtversicherungen',
                'share_percent' => null,
            ],
            [
                'type' => 'Geschäftsessen',
                'e1a' => '9200 - Werbung und Repräsentation',
                'share_percent' => 50,
            ],
            [
                'type' => 'Visitenkarten',
                'e1a' => '9200 - Werbung und Repräsentation',
                'share_percent' => null,
            ],
            [
                'type' => 'Online Werbung',
                'e1a' => '9200 - Werbung und Repräsentation',
                'share_percent' => null,
            ],
            [
                'type' => 'Trinkgelder',
                'e1a' => '9200 - Werbung und Repräsentation',
                'share_percent' => null,
            ],
            [
                'type' => 'Allgemeine Spenden',
                'e1a' => '9200 - Werbung und Repräsentation',
                'share_percent' => null,
            ],
            [
                'type' => 'Internetzugang 50% privat',
                'e1a' => '9230 - Übrige Betriebsausgaben',
                'share_percent' => 50,
            ],
            [
                'type' => 'Handyvertrag 60% privat',
                'e1a' => '9230 - Übrige Betriebsausgaben',
                'share_percent' => 40,
            ],
            [
                'type' => 'Büromaterialien',
                'e1a' => '9230 - Übrige Betriebsausgaben',
                'share_percent' => null,
            ],
            [
                'type' => 'Beratungen Buchhalter',
                'e1a' => '9230 - Übrige Betriebsausgaben',
                'share_percent' => null,
            ],
            [
                'type' => 'Fachliteratur',
                'e1a' => '9230 - Übrige Betriebsausgaben',
                'share_percent' => null,
            ],
            [
                'type' => 'Paypal-Gebühren',
                'e1a' => '9230 - Übrige Betriebsausgaben',
                'share_percent' => null,
            ],
            [
                'type' => 'Umsatzsteuerzahlungen (Reverse Charge)',
                'e1a' => '9230 - Übrige Betriebsausgaben',
                'share_percent' => null,
            ],
            [
                'type' => 'WKO-Grundumlagen',
                'e1a' => '9230 - Übrige Betriebsausgaben',
                'share_percent' => null,
            ],
            [
                'type' => 'Unternehmer-Lohn',
                'e1a' => null,
                'share_percent' => 0,
            ],
            [
                'type' => 'Private Ausgaben',
                'e1a' => null,
                'share_percent' => 0,
            ],
            [
                'type' => 'Vermietung Ausgaben',
                'e1a' => 'E1B 9530 - Sonstige Kosten Vermietung',
                'share_percent' => null,
            ],
        ],
        'income_kinds' => [
            [
                'type' => 'Dienstleistung',
                'e1a' => '9040 - Betriebseinnahmen',
                'share_percent' => null,
            ],
            [
                'type' => 'Warenverkauf',
                'e1a' => '9040 - Betriebseinnahmen',
                'share_percent' => null,
            ],
            [
                'type' => 'Private Entnahme',
                'e1a' => '9040 - Betriebseinnahmen',
                'share_percent' => null,
            ],
            [
                'type' => 'Förderungen',
                'e1a' => '9090 - Übrige Betriebseinnahmen',
                'share_percent' => null,
            ],
            [
                'type' => 'Private Entnahme Anlagegüter',
                'e1a' => '9060 - Erlös abgeg. Anlagegüter',
                'share_percent' => null,
            ],
            [
                'type' => 'Verkauf Anlagegüter',
                'e1a' => '9060 - Erlös abgeg. Anlagegüter',
                'share_percent' => null,
            ],
            [
                'type' => 'Private Einnahme',
                'e1a' => null,
                'share_percent' => 0,
            ],
            [
                'type' => 'Private Einzahlung',
                'e1a' => null,
                'share_percent' => 0,
            ],
            [
                'type' => 'Vermietung Einnahmen',
                'e1a' => 'E1B 9460 - Mieteinnahmen',
                'share_percent' => null,
            ],
        ],
        'vat_kinds' => [
            'Reverse Charge 20 %',
            'Reverse Charge 13 %',
            'Reverse Charge 10 %',
            'IG-Erwerb 20 %',
            'IG-Erwerb 10 %',
            'IG-Erwerb 13 %',
            'IG-Erwerb 4.9 %',
        ],
        'vehicle_types' => [
            ['name' => 'Privatauto', 'category' => 'PKW oder Kombi'],
            ['name' => 'Motorrad', 'category' => 'Motorrad'],
            ['name' => 'Fahrrad', 'category' => 'Fahrrad oder E-Bike'],
            ['name' => 'E-Bike', 'category' => 'Fahrrad oder E-Bike'],
            ['name' => 'Flug', 'category' => 'Andere'],
            ['name' => 'Flug (Inland)', 'category' => 'Andere'],
            ['name' => 'Bus', 'category' => 'Andere'],
            ['name' => 'U-Bahn', 'category' => 'Andere'],
            ['name' => 'Zug', 'category' => 'Andere'],
            ['name' => 'Gemischte Öffis', 'category' => 'Andere'],
            ['name' => 'E-Scooter (Verleih)', 'category' => 'Andere'],
            ['name' => 'Taxi', 'category' => 'Andere'],
            ['name' => 'Carsharing', 'category' => 'Andere'],
        ],
        'payment_methods' => [
            ['kind' => 'Bankkonto', 'opening_balance_cents' => null],
            ['kind' => 'Bar', 'opening_balance_cents' => null],
        ],
        'projects' => ['Hauptprojekt', 'Nebenprojekt', 'Vermietung'],
        'travel_allowances' => [
            [
                'country' => 'Österreich',
                'day_cents' => 3000,
                'night_cents' => 1700,
            ],
            [
                'country' => 'Afghanistan',
                'day_cents' => 3180,
                'night_cents' => 2770,
            ],
            [
                'country' => 'Ägypten',
                'day_cents' => 3790,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Albanien',
                'day_cents' => 2790,
                'night_cents' => 2090,
            ],
            [
                'country' => 'Algerien',
                'day_cents' => 4140,
                'night_cents' => 2700,
            ],
            [
                'country' => 'Angola',
                'day_cents' => 4360,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Argentinien',
                'day_cents' => 3310,
                'night_cents' => 4730,
            ],
            [
                'country' => 'Armenien',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Aserbaidschan',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Äthiopien',
                'day_cents' => 3790,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Australien',
                'day_cents' => 4730,
                'night_cents' => 3990,
            ],
            [
                'country' => 'Bahamas',
                'day_cents' => 4800,
                'night_cents' => 3050,
            ],
            [
                'country' => 'Bahrein',
                'day_cents' => 5410,
                'night_cents' => 3750,
            ],
            [
                'country' => 'Bangladesch',
                'day_cents' => 3180,
                'night_cents' => 3420,
            ],
            [
                'country' => 'Barbados',
                'day_cents' => 5100,
                'night_cents' => 4360,
            ],
            [
                'country' => 'Belarus',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Belgien',
                'day_cents' => 3530,
                'night_cents' => 2270,
            ],
            [
                'country' => 'Belgien: Brüssel',
                'day_cents' => 4140,
                'night_cents' => 3200,
            ],
            [
                'country' => 'Benin',
                'day_cents' => 3620,
                'night_cents' => 2660,
            ],
            [
                'country' => 'Bolivien',
                'day_cents' => 2660,
                'night_cents' => 2510,
            ],
            [
                'country' => 'Bosnien und Herzegowina',
                'day_cents' => 3100,
                'night_cents' => 2330,
            ],
            [
                'country' => 'Brasilien',
                'day_cents' => 3310,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Brunei',
                'day_cents' => 3310,
                'night_cents' => 4210,
            ],
            [
                'country' => 'Bulgarien',
                'day_cents' => 3100,
                'night_cents' => 2270,
            ],
            [
                'country' => 'Burkina Faso',
                'day_cents' => 3920,
                'night_cents' => 2110,
            ],
            [
                'country' => 'Burundi',
                'day_cents' => 3790,
                'night_cents' => 3790,
            ],
            [
                'country' => 'Chile',
                'day_cents' => 3750,
                'night_cents' => 3640,
            ],
            [
                'country' => 'China',
                'day_cents' => 3510,
                'night_cents' => 3050,
            ],
            [
                'country' => 'Costa Rica',
                'day_cents' => 3180,
                'night_cents' => 3180,
            ],
            [
                'country' => 'Côte d\'Ivoire',
                'day_cents' => 3920,
                'night_cents' => 3200,
            ],
            [
                'country' => 'Dänemark',
                'day_cents' => 4140,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Demokratische Rep. Kongo',
                'day_cents' => 4730,
                'night_cents' => 3310,
            ],
            [
                'country' => 'Deutschland',
                'day_cents' => 3530,
                'night_cents' => 2790,
            ],
            [
                'country' => 'Deutschland: Grenzort',
                'day_cents' => 3070,
                'night_cents' => 1810,
            ],
            [
                'country' => 'Dominikanische Republik',
                'day_cents' => 3920,
                'night_cents' => 4360,
            ],
            [
                'country' => 'Dschibuti',
                'day_cents' => 4580,
                'night_cents' => 4730,
            ],
            [
                'country' => 'Ecuador',
                'day_cents' => 2660,
                'night_cents' => 2160,
            ],
            [
                'country' => 'El Salvador',
                'day_cents' => 3180,
                'night_cents' => 2620,
            ],
            [
                'country' => 'Estland',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Finnland',
                'day_cents' => 4140,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Frankreich',
                'day_cents' => 3270,
                'night_cents' => 2400,
            ],
            [
                'country' => 'Frankreich: Paris/Straßburg',
                'day_cents' => 3580,
                'night_cents' => 3270,
            ],
            [
                'country' => 'Gabun',
                'day_cents' => 4580,
                'night_cents' => 3990,
            ],
            [
                'country' => 'Gambia',
                'day_cents' => 4360,
                'night_cents' => 3010,
            ],
            [
                'country' => 'Georgien',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Ghana',
                'day_cents' => 4360,
                'night_cents' => 3010,
            ],
            [
                'country' => 'Griechenland',
                'day_cents' => 2860,
                'night_cents' => 2330,
            ],
            [
                'country' => 'Großbritannien und Nordirland',
                'day_cents' => 3680,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Großbritannien: London',
                'day_cents' => 4140,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Guatemala',
                'day_cents' => 3180,
                'night_cents' => 3180,
            ],
            [
                'country' => 'Guinea',
                'day_cents' => 4360,
                'night_cents' => 3010,
            ],
            [
                'country' => 'Guyana',
                'day_cents' => 3920,
                'night_cents' => 3420,
            ],
            [
                'country' => 'Haiti',
                'day_cents' => 3920,
                'night_cents' => 2770,
            ],
            [
                'country' => 'Honduras',
                'day_cents' => 3180,
                'night_cents' => 2700,
            ],
            [
                'country' => 'Hongkong',
                'day_cents' => 4640,
                'night_cents' => 3790,
            ],
            [
                'country' => 'Indien',
                'day_cents' => 3180,
                'night_cents' => 3990,
            ],
            [
                'country' => 'Indonesien',
                'day_cents' => 3920,
                'night_cents' => 3200,
            ],
            [
                'country' => 'Irak',
                'day_cents' => 5410,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Iran',
                'day_cents' => 3710,
                'night_cents' => 2900,
            ],
            [
                'country' => 'Irland',
                'day_cents' => 3680,
                'night_cents' => 3310,
            ],
            [
                'country' => 'Island',
                'day_cents' => 3790,
                'night_cents' => 3140,
            ],
            [
                'country' => 'Israel',
                'day_cents' => 3710,
                'night_cents' => 3250,
            ],
            [
                'country' => 'Italien',
                'day_cents' => 3580,
                'night_cents' => 2790,
            ],
            [
                'country' => 'Italien: Grenzorte',
                'day_cents' => 3070,
                'night_cents' => 1810,
            ],
            [
                'country' => 'Italien: Rom/Mailand',
                'day_cents' => 4060,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Jamaika',
                'day_cents' => 4710,
                'night_cents' => 4710,
            ],
            [
                'country' => 'Japan',
                'day_cents' => 6560,
                'night_cents' => 4290,
            ],
            [
                'country' => 'Jemen',
                'day_cents' => 5410,
                'night_cents' => 3750,
            ],
            [
                'country' => 'Jordanien',
                'day_cents' => 3710,
                'night_cents' => 3250,
            ],
            [
                'country' => 'Jugoslawien',
                'day_cents' => 3100,
                'night_cents' => 2330,
            ],
            [
                'country' => 'Kambodscha',
                'day_cents' => 3140,
                'night_cents' => 3140,
            ],
            [
                'country' => 'Kamerun',
                'day_cents' => 4580,
                'night_cents' => 2530,
            ],
            [
                'country' => 'Kanada',
                'day_cents' => 4100,
                'night_cents' => 3420,
            ],
            [
                'country' => 'Kap Verde',
                'day_cents' => 2790,
                'night_cents' => 1960,
            ],
            [
                'country' => 'Kasachstan',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Katar',
                'day_cents' => 5410,
                'night_cents' => 3750,
            ],
            [
                'country' => 'Kenia',
                'day_cents' => 3490,
                'night_cents' => 3200,
            ],
            [
                'country' => 'Kirgisistan',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Kolumbien',
                'day_cents' => 3310,
                'night_cents' => 3510,
            ],
            [
                'country' => 'Korea, Dem. Volksrepublik',
                'day_cents' => 3250,
                'night_cents' => 3250,
            ],
            [
                'country' => 'Korea, Republik',
                'day_cents' => 4530,
                'night_cents' => 3250,
            ],
            [
                'country' => 'Kroatien',
                'day_cents' => 3100,
                'night_cents' => 2330,
            ],
            [
                'country' => 'Kuba',
                'day_cents' => 5410,
                'night_cents' => 2770,
            ],
            [
                'country' => 'Kuwait',
                'day_cents' => 5410,
                'night_cents' => 3750,
            ],
            [
                'country' => 'Laos',
                'day_cents' => 3140,
                'night_cents' => 3140,
            ],
            [
                'country' => 'Lettland',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Libanon',
                'day_cents' => 3180,
                'night_cents' => 3510,
            ],
            [
                'country' => 'Liberia',
                'day_cents' => 3920,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Libyen',
                'day_cents' => 4360,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Liechtenstein',
                'day_cents' => 3070,
                'night_cents' => 1810,
            ],
            [
                'country' => 'Litauen',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Luxemburg',
                'day_cents' => 3530,
                'night_cents' => 2270,
            ],
            [
                'country' => 'Madagaskar',
                'day_cents' => 3640,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Malawi',
                'day_cents' => 3270,
                'night_cents' => 3270,
            ],
            [
                'country' => 'Malaysia',
                'day_cents' => 4360,
                'night_cents' => 4510,
            ],
            [
                'country' => 'Mali',
                'day_cents' => 3920,
                'night_cents' => 3120,
            ],
            [
                'country' => 'Malta',
                'day_cents' => 3010,
                'night_cents' => 3010,
            ],
            [
                'country' => 'Marokko',
                'day_cents' => 3270,
                'night_cents' => 2180,
            ],
            [
                'country' => 'Mauretanien',
                'day_cents' => 3380,
                'night_cents' => 3120,
            ],
            [
                'country' => 'Mauritius',
                'day_cents' => 3640,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Mexiko',
                'day_cents' => 4100,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Moldau',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Mongolei',
                'day_cents' => 2940,
                'night_cents' => 2940,
            ],
            [
                'country' => 'Mosambik',
                'day_cents' => 4360,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Myanmar',
                'day_cents' => 2940,
                'night_cents' => 2940,
            ],
            [
                'country' => 'Namibia',
                'day_cents' => 3490,
                'night_cents' => 3400,
            ],
            [
                'country' => 'Nepal',
                'day_cents' => 3180,
                'night_cents' => 3420,
            ],
            [
                'country' => 'Neuseeland',
                'day_cents' => 3250,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Nicaragua',
                'day_cents' => 3180,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Niederlande',
                'day_cents' => 3530,
                'night_cents' => 2790,
            ],
            [
                'country' => 'Niederländische Antillen',
                'day_cents' => 4360,
                'night_cents' => 2770,
            ],
            [
                'country' => 'Niger',
                'day_cents' => 3920,
                'night_cents' => 2110,
            ],
            [
                'country' => 'Nigeria',
                'day_cents' => 3920,
                'night_cents' => 3420,
            ],
            [
                'country' => 'Norwegen',
                'day_cents' => 4290,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Oman',
                'day_cents' => 5410,
                'night_cents' => 3750,
            ],
            [
                'country' => 'Pakistan',
                'day_cents' => 2770,
                'night_cents' => 2510,
            ],
            [
                'country' => 'Panama',
                'day_cents' => 4360,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Paraguay',
                'day_cents' => 3310,
                'night_cents' => 2510,
            ],
            [
                'country' => 'Peru',
                'day_cents' => 3310,
                'night_cents' => 2510,
            ],
            [
                'country' => 'Philippinen',
                'day_cents' => 3250,
                'night_cents' => 3250,
            ],
            [
                'country' => 'Polen',
                'day_cents' => 3270,
                'night_cents' => 2510,
            ],
            [
                'country' => 'Portugal',
                'day_cents' => 2790,
                'night_cents' => 2270,
            ],
            [
                'country' => 'Republik Kongo',
                'day_cents' => 3920,
                'night_cents' => 2680,
            ],
            [
                'country' => 'Ruanda',
                'day_cents' => 3790,
                'night_cents' => 3790,
            ],
            [
                'country' => 'Rumänien',
                'day_cents' => 3680,
                'night_cents' => 2730,
            ],
            [
                'country' => 'Russ. Föderation: Moskau',
                'day_cents' => 4060,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Russische Föderation',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Sambia',
                'day_cents' => 3710,
                'night_cents' => 3400,
            ],
            [
                'country' => 'Saudi-Arabien',
                'day_cents' => 5410,
                'night_cents' => 3750,
            ],
            [
                'country' => 'Schweden',
                'day_cents' => 4290,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Schweiz',
                'day_cents' => 3680,
                'night_cents' => 3270,
            ],
            [
                'country' => 'Schweiz: Grenzorte',
                'day_cents' => 3070,
                'night_cents' => 1810,
            ],
            [
                'country' => 'Senegal',
                'day_cents' => 4930,
                'night_cents' => 3120,
            ],
            [
                'country' => 'Seychellen',
                'day_cents' => 3640,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Sierra Leone',
                'day_cents' => 4360,
                'night_cents' => 3420,
            ],
            [
                'country' => 'Simbabwe',
                'day_cents' => 3710,
                'night_cents' => 3400,
            ],
            [
                'country' => 'Singapur',
                'day_cents' => 4360,
                'night_cents' => 4470,
            ],
            [
                'country' => 'Slowakei',
                'day_cents' => 2790,
                'night_cents' => 1590,
            ],
            [
                'country' => 'Slowakei: Bratislava',
                'day_cents' => 3100,
                'night_cents' => 2440,
            ],
            [
                'country' => 'Slowenien',
                'day_cents' => 3100,
                'night_cents' => 2330,
            ],
            [
                'country' => 'Slowenien: Grenzorte',
                'day_cents' => 2790,
                'night_cents' => 1590,
            ],
            [
                'country' => 'Somalia',
                'day_cents' => 3270,
                'night_cents' => 2900,
            ],
            [
                'country' => 'Spanien',
                'day_cents' => 3420,
                'night_cents' => 3050,
            ],
            [
                'country' => 'Sri Lanka',
                'day_cents' => 3180,
                'night_cents' => 3270,
            ],
            [
                'country' => 'Südafrika',
                'day_cents' => 3490,
                'night_cents' => 3400,
            ],
            [
                'country' => 'Sudan',
                'day_cents' => 4360,
                'night_cents' => 4140,
            ],
            [
                'country' => 'Suriname',
                'day_cents' => 3920,
                'night_cents' => 2510,
            ],
            [
                'country' => 'Syrien',
                'day_cents' => 3270,
                'night_cents' => 2900,
            ],
            [
                'country' => 'Tadschikistan',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Taiwan',
                'day_cents' => 3920,
                'night_cents' => 3750,
            ],
            [
                'country' => 'Tansania',
                'day_cents' => 4360,
                'night_cents' => 3200,
            ],
            [
                'country' => 'Thailand',
                'day_cents' => 3920,
                'night_cents' => 4210,
            ],
            [
                'country' => 'Togo',
                'day_cents' => 3620,
                'night_cents' => 2660,
            ],
            [
                'country' => 'Trinidad, Tobago',
                'day_cents' => 5100,
                'night_cents' => 4360,
            ],
            [
                'country' => 'Tschad',
                'day_cents' => 3620,
                'night_cents' => 2660,
            ],
            [
                'country' => 'Tschechien',
                'day_cents' => 3100,
                'night_cents' => 2440,
            ],
            [
                'country' => 'Tschechien: Grenzorte',
                'day_cents' => 2790,
                'night_cents' => 1590,
            ],
            [
                'country' => 'Tunesien',
                'day_cents' => 3620,
                'night_cents' => 2920,
            ],
            [
                'country' => 'Türkei',
                'day_cents' => 3100,
                'night_cents' => 3640,
            ],
            [
                'country' => 'Turkmenistan',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Uganda',
                'day_cents' => 4140,
                'night_cents' => 3200,
            ],
            [
                'country' => 'Ukraine',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Ungarn',
                'day_cents' => 2660,
                'night_cents' => 2660,
            ],
            [
                'country' => 'Ungarn: Budapest',
                'day_cents' => 3100,
                'night_cents' => 2660,
            ],
            [
                'country' => 'Ungarn: Grenzorte',
                'day_cents' => 2660,
                'night_cents' => 1810,
            ],
            [
                'country' => 'Uruguay',
                'day_cents' => 3310,
                'night_cents' => 2510,
            ],
            [
                'country' => 'USA',
                'day_cents' => 5230,
                'night_cents' => 4290,
            ],
            [
                'country' => 'USA: New York/Washington',
                'day_cents' => 6540,
                'night_cents' => 5100,
            ],
            [
                'country' => 'Usbekistan',
                'day_cents' => 3680,
                'night_cents' => 3100,
            ],
            [
                'country' => 'Venezuela',
                'day_cents' => 3920,
                'night_cents' => 3510,
            ],
            [
                'country' => 'Ver. Arabische Emirate',
                'day_cents' => 5410,
                'night_cents' => 3750,
            ],
            [
                'country' => 'Vietnam',
                'day_cents' => 3140,
                'night_cents' => 3140,
            ],
            [
                'country' => 'Zentralafrik. Republik',
                'day_cents' => 3920,
                'night_cents' => 2900,
            ],
            [
                'country' => 'Zypern',
                'day_cents' => 2860,
                'night_cents' => 3050,
            ],
        ],
        'domestic_flat_rates' => ['day_cents' => 3000, 'night_cents' => 1700],
        'income_tax_stages' => [
            ['limit_cents' => 1353900, 'rate_per_10000' => 2000],
            ['limit_cents' => 2199200, 'rate_per_10000' => 3000],
            ['limit_cents' => 3645800, 'rate_per_10000' => 4000],
            ['limit_cents' => 7036500, 'rate_per_10000' => 4800],
            ['limit_cents' => 10485900, 'rate_per_10000' => 5000],
        ],
        'basic_allowance' => ['limit_cents' => 3300000, 'rate_per_10000' => 1500],
        'social_insurance' => [
            'fsvg_pv_min' => 11022,
            'fsvg_pv_satz' => 2000,
            'gsvg_ns_pv_min' => 10195,
            'gsvg_pv_min' => 10195,
            'gsvg_pv_satz' => 1850,
            'kv_min' => 3747,
            'kv_satz' => 680,
            'max_bgl' => 808500,
            'svs_satz' => 153,
            'uv_pauschale' => 1295,
        ],
    ];
}
