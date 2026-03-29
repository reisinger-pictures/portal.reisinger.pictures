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

## 2. Integration von Meilisearch (Typo-Toleranz)
- Für die Suche nach Ortsdaten wird direkt Meilisearch genutzt (`Location::search()`), um von der eingebauten Typo-Toleranz zu profitieren.
- Zwar unterstützt Meilisearch nativ keine Substring-Suche innerhalb einzelner zusammenhängender Wörter (Infix-Suche), dafür verzeiht es aber Tippfehler der Nutzer (z.B. "Salsburg" -> "Salzburg").
- Die Ergebnisse werden zusätzlich über Meilisearch nach `population` (Einwohnerzahl) absteigend sortiert, um bekannte Großstädte zu priorisieren.

## 3. Frontend-Verhalten (Geplant)
- Das Frontend fragt bei Eingaben in den Feldern "Stadt" und "Land" den Meilisearch-Index ab.
- Wird eine Stadt ausgewählt, befüllt das UI automatisch die angrenzenden IPTC-Felder (`state`, `country`, `iso_country`), um dem Fotografen Tipparbeit zu ersparen.
