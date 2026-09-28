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
* **Thumbnails (GD, on demand bei der Auslieferung):** Thumbnails erzeugt `ImageProcessor::generateThumbnail()` über die PHP-Erweiterung **GD**. Die Formulierung hier war „Der `ImageController` nutzt direkt die PHP-Erweiterung `Imagick`, um beim Upload automatisch speichereffiziente WebP-Thumbnails (Standard-Breite: 1024px, 80% Qualität) zu generieren" — von den fünf Angaben darin waren vier falsch:
    * **GD, nicht Imagick.** `grep -rn "Imagick" backend/app` findet genau einen Treffer, und der ist ein Kommentar: `Services/ModelContactSheetService.php:342` → „das PDF nicht an einem fehlenden DomPDF/Imagick-Codec scheitert“. Bildskalierung passiert über `imagecopyresampled()` / `imagewebp()` (`ImageProcessor.php:180` und `:195`), nicht über ImageMagick-CLI.
    * **`ImageProcessor::generateThumbnail()`, nicht `ImageController`.** `grep -rn generateThumbnail backend/app` liefert zwei Zeilen: die Definition (`ImageProcessor.php:147`) und genau einen Aufrufer (`FileDeliveryController.php:91`). Dass der `ImageController` mit Thumbnail-Erzeugung nichts zu tun hat, ist direkt nachprüfbar: `grep -n "ImageProcessor" backend/app/Http/Controllers/ImageController.php` liefert **keine** Zeile.
    * **Bei der Auslieferung, nicht beim Upload.** Der einzige Aufruf ist `FileDeliveryController.php:91` → `$this->imageProcessor->generateThumbnail($originalPath, $thumbPath, $size);` — er wird vom Delivery-Request ausgelöst (Cache-Lock `thumb_generation_<photo_id>_<size>`, `FileDeliveryController.php:79`), nicht vom Upload. Der Upload legt nur das Original ab (`ImageController.php:106` → `if (! $file->storeAs($targetDir, $filename, ['disk' => 'photos'])) {`).
    * **Keine Standard-Breite 1024px.** Die Breite ist der `$size` aus dem angeforderten Pfad (`_thumbs/<size>/<photo_id>.webp`, `FileDeliveryController.php:33,50,78`) und muss einer von sechs erlaubten Werten sein (`Photo::DERIVATIVE_SIZES`, `Photo.php:18` → `public const DERIVATIVE_SIZES = [250, 400, 800, 1024, 1200, 2000];`) — unbekannte Werte werden mit 400 abgewiesen (`FileDeliveryController.php:66`). Die **einzige** `1024` in einer Upload-Zeile ist ein Hash-Salz im Dateinamen eines Legacy-Pfads, keine Breite: `ImageController.php:111` → `$thumbPath = Storage::disk('photos')->path($thumbsDir.'/'.md5($filename.'1024').'.webp');`
    * **80 % Qualität stimmt** — das ist der Default des Aufrufers (`ImageProcessor.php:147` → `public function generateThumbnail($sourcePath, $destPath, $size, $quality = 80)`), weitergegeben an `imagewebp($img, $destPath, $quality)` (`:195`).
* **Identifikation via Lightroom:** Bilder werden anhand ihrer Lightroom UUID (`lr_uuid`) identifiziert. Dies ermöglicht saubere "Upserts" in die Datenbank, falls ein Bild aus Lightroom korrigiert und erneut hochgeladen wird.

## 4. Suche (Meilisearch & Scout)
* **Filterable Attributes:** Wir nutzen Meilisearch nativ, um das Rechte-System abzubilden (`whereIn('gallery_id', [...])`). 
* **Location Cache (GeoNames):** Die Datensätze für die Autovervollständigung von Orten werden lokal unter `storage/app/private/temp/` zwischengespeichert (`AT_postal.txt`, `AT_places.txt`, `countryInfo.txt`), um API-Limits und Timeouts abzufangen. 
* **WICHTIG NACH UPDATES:** Wenn Änderungen an der Suche (Modelle oder Filter) vorgenommen werden, muss zwingend folgender Befehl ausgeführt werden, um die `scout.php` Konfiguration in die Meilisearch-Engine zu pushen:
  ```bash
  php artisan scout:sync-index-settings
  ```

## 4b. Code Coverage (PHPUnit + Xdebug)
* **Coverage-Treiber:** Xdebug kann via PECL installiert werden (`pecl install xdebug`). Die Extension liegt dann unter `/opt/homebrew/lib/php/pecl/20250930/xdebug.so` (Pfad variiert je nach PHP-Version). In `/opt/homebrew/etc/php/8.5/conf.d/ext-xdebug.ini` wird sie geladen. `xdebug.mode=off` hält die Performance-Auswirkung für alle normalen Läufe bei null.
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
