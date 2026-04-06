# Spezifikation fehlender Testfälle (QA Gap Analysis)

## 1. Bildverwaltung & Historie (Fotograf/Admin)
* **Flow A (Historie via Magic Link):** Fotograf gewährt Gast Metadaten-Rechte, Gast editiert, Fotograf stellt Historie wieder her.

## 2. Struktur & Lifecycle
* **Flow C (Gruppe/Galerie löschen):** Fotograf löscht Gruppe, darin liegende Galerien wandern in Root.

## 3. Einladungen & E-Mail
* **Flow E (Links widerrufen):** Link im Modal generieren, widerrufen, Link verschwindet.
* **Flow F (Custom Email Composer):** E-Mail Modal füllen (fixer Text + Variablen wie {user_name}), HTML Vorschau prüfen.
* **Flow AE (Mandanten-Einladung):** E2E: Manager lädt per E-Mail in Tenant ein -> Mailpit Check -> Gast registriert sich -> User ist Tenant zugeordnet.

## 4. E-Commerce & Buchhaltung
* **Flow P (Checkout Bypass):** User mit Flatrate öffnet Lizenz-Modal -> Sofort-Download Button erscheint.
* **Flow Q (Full Checkout):** Power-User bestellt kostenpflichtiges Upgrade -> Formular-Validierung -> PDF Rechnung Erzeugung.
* **Flow AB (Manuelle Sammelrechnung):** Tenant-Dashboard -> Klick auf "Sammelrechnung erstellen" -> Zähler offene Lieferscheine sinkt auf 0, Toast erscheint.
* **Flow AC (Automatisierte Sammelrechnung):** PHPUnit: Trigger Command `app:process-collective-invoices` simuliert Monatsende -> Prüft Rechnungsversand.

## 5. Security & Multi-Tenancy (KRITISCH)
* **Flow S (Customer Manager Scope):** CM loggt sich ein -> Sieht NUR Nutzer seines Mandanten -> Versuch fremde ID zu laden wirft 403.
* **Flow U (Tenant Isolation):** PHPUnit: Sicherstellen, dass Galerien eines Tenants für Nutzer eines anderen Tenants unsichtbar bleiben (IDOR Protection).
* **Flow W (Pessimistic Locking):** PHPUnit Stress-Test: Parallele Checkouts dürfen niemals dieselbe Rechnungsnummer generieren.
* **Flow AI (Zero Trust Boundary):** PHPUnit: `customer_manager` versucht, `UserController@update` oder `UserController@destroy` für eine `user_id` eines fremden Mandanten aufzurufen -> System muss strikt 403 Forbidden werfen.

## 6. Bildschutz (Watermark)
* **Flow H (Einstellungs-Sync):** Admin ändert Deckkraft -> Prüft WebP Derivative Cleanup -> Neues Tile-Image wird generiert.
* **Flow AD (Tile Cache Validation):** Prüfung ob `watermark_master_tile_{hash}.png` im Dateisystem existiert und bei SVG-Wechsel aktualisiert wird.

## 7. E-Commerce Upselling & Cart
* **Flow AG (Cart Modification):** User öffnet Warenkorb, ändert die Lizenz eines Bildes (z.B. Original/Kommerziell) -> Preis wird dynamisch aktualisiert -> Checkout leitet zu /orders weiter.
* **Flow AH (Order ZIP Download):** User navigiert zu "Meine Einkäufe & Lizenzen" -> Klickt auf "Bilder ZIP" -> Download startet und Dateiname endet auf .zip.
* **Flow AJ (Delta-Pricing Lifecycle):** Power-User legt Bild in Warenkorb -> Upgrade gewählt (Delta-Preis) -> Checkout -> Admin sieht Order im Dashboard.


## 8. Testing Framework & Teardown
* **Flow AK (E2E Teardown Integrity):** E2ESessionHelper führt teardown() aus -> API-Prüfung beweist, dass der E2E-User (via UserController@destroy) restlos aus dem System entfernt wurde.