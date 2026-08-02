# Backend Architektur (Laravel) - portal.reisinger.pictures

Dieses Verzeichnis enthält die Laravel-basierte, zustandslose (stateless) JSON-API für das Foto-Portal. 

Da wir eine maßgeschneiderte SaaS-Architektur verwenden, weicht dieses Setup in einigen zentralen Punkten vom Laravel-Standard ab. Hier sind die wichtigsten Architektur-Entscheidungen für Entwickler und KI-Agenten dokumentiert:

## 1. Datenbank & Laravel Migrations (Single Source of Truth)
* **Ausschließliche Nutzung von Laravel Migrations:** Die gesamte Struktur der Geschäftsdatenbank sowie interne Framework-Tabellen werden **ausschließlich** über native Laravel Migrations gesteuert. (Flyway wurde vollständig entfernt).
* **Timestamps:** Da unser optimiertes Schema in vielen Tabellen nur `created_at` und kein `updated_at` verwendet, ist in den betroffenen Eloquent-Models zwingend `public const UPDATED_AT = null;` gesetzt.
* **Surrogate Keys:** Alle Tabellen verwenden numerische Auto-Increment-IDs (`BIGINT`) als Primärschlüssel.

## 2. Authentifizierung & Autorisierung
* **Stateless JWT:** Wir verwenden keine PHP-Sessions (`session`-Guard ist für die API deaktiviert). Die Authentifizierung läuft vollständig über JSON Web Tokens (JWT) via `php-open-source-saver/jwt-auth`.
* **Implicit Pending:** Es gibt bewusst **keine** `status`-Spalte für User in der Datenbank. Ein User gilt implizit als `pending` (wartend), solange er keine Rollen (`roles`) und keine Rechte auf Galerien oder Gruppen hat. Diese Logik ist im `User`-Model als Accessor (`getIsPendingAttribute`) gekapselt.
* **Magic Links & Passwortschutz:** (Geplant) Gäste erhalten Zugang über spezielle Token-URLs. Ist bei einer Galerie ein `password_hash` hinterlegt, fungiert dieses als zweiter Faktor, bevor das Gast-JWT ausgestellt wird.

## 3. Dateiablage & Bildverarbeitung
* **Flat Storage:** Bilder werden nicht tief verschachtelt gespeichert, sondern flach unter `/var/www/photos/{gallery_slug}/`. Das vereinfacht Backups und Migrationen.
* **Thumbnails (Imagick):** Der `ImageController` nutzt direkt die PHP-Erweiterung `Imagick`, um beim Upload automatisch speichereffiziente WebP-Thumbnails (Standard-Breite: 1024px, 80% Qualität) zu generieren. 
* **Identifikation via Lightroom:** Bilder werden anhand ihrer Lightroom UUID (`lr_uuid`) identifiziert. Dies ermöglicht saubere "Upserts" in die Datenbank, falls ein Bild aus Lightroom korrigiert und erneut hochgeladen wird.

## 4. Suche (Meilisearch & Scout)
* **Filterable Attributes:** Wir nutzen Meilisearch nativ, um das Rechte-System abzubilden (`whereIn('gallery_id', [...])`). 
* **Location Cache (GeoNames):** Die Datensätze für die Autovervollständigung von Orten werden lokal unter `storage/app/private/temp/` zwischengespeichert (`AT_postal.txt`, `AT_places.txt`, `countryInfo.txt`), um API-Limits und Timeouts abzufangen. 
* **WICHTIG NACH UPDATES:** Wenn Änderungen an der Suche (Modelle oder Filter) vorgenommen werden, muss zwingend folgender Befehl ausgeführt werden, um die `scout.php` Konfiguration in die Meilisearch-Engine zu pushen:
  ```bash
  php artisan scout:sync-index-settings
  ```

## 4b. Code Coverage (PHPUnit + Herd Xdebug)
* **Coverage-Treiber:** Herd liefert Xdebug 3.5 als vorkompilierte Extension mit. Für PHP 8.5 ist sie über `/Applications/Herd.app/Contents/Resources/xdebug/xdebug-85-arm64.so` verfügbar. In `/Users/florianreisinger/Library/Application Support/Herd/config/php/85/xdebug.ini` wird sie geladen (Architektur beachten: `-arm64` vs `-x86`). `xdebug.mode=off` hält die Performance-Auswirkung für alle normalen Läufe bei null.
* **Ausführen:** Coverage ist nur aktiv, wenn der Mode beim Lauf gesetzt wird:
  ```bash
  # Kanban-Board-Feature
  cd backend && XDEBUG_MODE=coverage php artisan test --filter="PhotoJobBoardTest|ProjectBoardTest" --coverage
  # Detailreicher Text-Report (Methoden/Lines pro Datei)
  XDEBUG_MODE=coverage php vendor/bin/phpunit --filter="PhotoJobBoardTest|ProjectBoardTest" --coverage-text
  ```
* **phpunit.xml `<source>`:** Alle `app/`-Dateien sind eingebunden; uncovered Files werden standardmäßig mitgezählt (PHPUnit 10+ prozessiert uncovered Files automatisch — `processUncoveredFiles` ist obsolet und würde die XML-Validierung brechen). Die Board-relevanten Dateien (Models, Controller, Enums) sind zusätzlich explizit gelistet.
* **Einschränkungen:** `php artisan test --coverage` rendert das Collision-Dashboard nur in einem TTY; für gespeicherte/gepipete Ausgaben bitte direkt `php vendor/bin/phpunit --coverage-text` nutzen. Branch-/Pfad-Coverage wird von PHPUnit CLI standardmäßig nicht gesammelt — sie erfordert `CodeCoverage::enableBranchAndPathCoverage()` programmatisch.

## 5. DSGVO & Immutable Audit Logs
* **Keine IP-Adressen:** Um der DSGVO vollständig zu entsprechen, werden in der `download_logs`-Tabelle **keine IP-Adressen** gespeichert.
* **Immutable Logs (Denormalisierung):** Wenn eine Galerie oder ein User hart aus der Datenbank gelöscht wird (Hard Delete), werden die Fremdschlüssel in den Logs auf `NULL` gesetzt (`ON DELETE SET NULL`). Damit die Langzeit-Statistik trotzdem erhalten bleibt, speichert das Log beim Anlegen Denormalisierungs-Snapshots (`gallery_name_snapshot`, `user_name_snapshot`).

## 6. Kern-Komponenten (Models)
* `User`: Implementiert das `JWTSubject` Interface für die Token-Generierung.
* `Role`: Verwaltet statische Berechtigungen (z.B. `admin`).
* `GalleryGroup`: Rekursives Model (`parent_id`) zur hierarchischen Gruppierung von Galerien (wird im Application-Cache als JSON-Baum vorgehalten).
* `Gallery`: Herzstück des Systems. Kann vom Typ `selection` (Kunden-Auswahl/Bewertung) oder `delivery` (Finaler Download) sein. 
* `Photo`: Verknüpft mit `Gallery`, speichert Dateiname, Abmessungen und `lr_uuid`.
* `DownloadLog`: Das manipulationssichere Audit-Log für Downloads.
