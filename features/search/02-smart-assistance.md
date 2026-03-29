---
domain: search
topic: smart-assistance
status: active
---

# Technical Concept: Smart Assistance (Metadata Auto-Complete)

## 1. Datenbasis & Import
- Die geographischen Daten werden nicht manuell gepflegt, sondern über den Artisan-Befehl `php artisan app:import-locations` bezogen.
- **Datenquelle:** Der Befehl lädt den offiziellen Open-Source Datensatz von [GeoNames](http://download.geonames.org/) herunter.
- Aktuell wird primär der Österreich-Datensatz (`AT.zip`) verwendet.
- **Automatisierung:** Der Command wird automatisch am Ende des `DatabaseSeeder` ausgeführt, damit nach einem `migrate:fresh --seed` die Daten sofort in der lokalen DB und in Meilisearch zur Verfügung stehen.

## 2. Struktur in Meilisearch
- Die Tabelle `locations` wird über Laravel Scout direkt in Meilisearch indiziert.
- Ein Feld `type` unterscheidet zwischen `city` und `country`.
- **Wichtig:** In der `scout.php` ist `type` als `filterableAttribute` definiert, damit das Frontend gezielt Anfragen nach Städten oder Ländern abfeuern kann.

## 3. Frontend-Verhalten (Geplant)
- Das Frontend fragt bei Eingaben in den Feldern "Stadt" und "Land" den Meilisearch-Index ab.
- Wird eine Stadt ausgewählt, befüllt das UI automatisch die angrenzenden IPTC-Felder (`state`, `country`, `iso_country`), um dem Fotografen Tipparbeit zu ersparen.
