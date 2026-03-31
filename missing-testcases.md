# Fehlende Testfälle & User Flows (Coverage Gap Analysis)

Basierend auf der Analyse der UI-Komponenten und bestehenden Tests wurden folgende User Flows identifiziert, die aktuell nicht oder nur unzureichend durch E2E- (Playwright) oder Backend-Tests (PHPUnit) abgedeckt sind.

Ziel ist es, den "Soll-Zustand" zu testen: Wie sollte sich die App verhalten, wenn ein User diese Aktionen durchführt? (Kein Mocking, nur echte System-Interaktionen).

## 1. Bildverwaltung: Löschen & Metadaten-Historie (Fotograf/Admin)
* **Flow A (Historie via Magic Link):** Fotograf gewährt Gast Metadaten-Rechte, Gast editiert, Fotograf stellt Historie wieder her.
* **Flow B (Bild löschen):** Fotograf öffnet Bild-Detailansicht, löscht Bild, Navigation zurück ins Grid.

## 2. Struktur-Management & Deaktivierung (Fotograf/Admin)
* **Flow C (Gruppe/Galerie löschen):** Fotograf löscht Gruppe, darin liegende Galerien wandern in Root.
* **Flow K (Ablauf & Deaktivierung):** Galerie abgelaufen -> durchgestrichen in Sidebar -> Gast erhält 403.

## 3. Einladungen, Edge Cases & Kommunikation (Fotograf/User)
* **Flow L (Invalider Magic Link als eingeloggter User):** Aufruf falschen Links zeigt Fehler, aber zerstört nicht die aktive Sitzung.
* **Flow E (Links widerrufen):** Link im Modal generieren, widerrufen, Link verschwindet.
* **Flow F (Custom Email Composer):** E-Mail Modal füllen (fixer Text + Variablen wie `{user_name}`), HTML Vorschau prüfen.

## 4. Admin-spezifische Features & Restriktionen
* **Flow M (Admin Management Block):** Login als reiner Admin -> Sidebar "Galerien" fehlt -> Management Buttons fehlen auf Galerie-Ansicht.
* **Flow G (Domain Mapping Lifecycle):** Admin legt Mapping an -> Prüft in Tabelle -> Löscht Mapping.
* **Flow H (Wasserzeichen Settings):** Admin ändert Slider (Skalierung/Deckkraft) -> Speichert -> Reload hält Werte.

## 5. FTP & Status-Monitoring (Fotograf)
* **Flow I (Rating Status Modal):** "Bewertungen..." Modal zeigt Gäste und Fortschrittsbalken korrekt an.
* **Flow J (FTP Real File Processing):** Echte .jpg in FTP-Ordner via Node.js `fs` ablegen -> Widget zeigt File an -> "Importieren" verschiebt Datei.

## 6. PHPUnit / Backend API-Tests (Neu)
* **Flow N (GalleryGroup Update):** `GalleryController@updateGroup` - Testet die Aktualisierung von Ordner-Metadaten inkl. Slug-Kollisionsvermeidung. Unbefugter Zugriff wirft 403.
* **Flow O (GalleryGroup Delete & Cascade):** `GalleryController@deleteGroup` - Testet das Löschen einer Meta-Galerie. Da in der Datenbank `ON DELETE SET NULL` konfiguriert ist, muss zwingend getestet werden, ob verschachtelte Unterordner und Galerien sicher in der Root-Ebene landen.