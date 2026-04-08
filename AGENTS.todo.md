# 📝 Projekt-Master-Backlog (Kumulativ)

## 🚀 Offene Tasks & Technische Deep-Dives

### 🧪 Fehlende Testabdeckungen (Aus QA Gap-Analysis überführt)
- [ ] **Flow C (Gruppe/Galerie löschen):** Fotograf löscht Gruppe, darin liegende Galerien wandern in Root.
- [ ] **Flow H (Einstellungs-Sync):** Admin ändert Deckkraft -> Prüft WebP Derivative Cleanup -> Neues Tile-Image wird generiert.
- [ ] **Flow AL (Tenant CRUD & Scope):** PHPUnit: Admin erstellt Tenant. Customer Manager sieht nur eigene Tenants. Zuweisung von Gruppen/Nutzern.
- [ ] **Flow AN (Delta Pricing & Validation):** PHPUnit: `OrderController@checkout` prüft korrekte Delta-Berechnung (z.B. User hat `print` Flatrate, kauft `original` -> berechnet nur Differenz).
- [ ] **Flow AO (Invoice PDF & Status):** PHPUnit: Sofort-Rechnung (Status `invoice_created`) vs Lieferschein (Status `delivery_note`) basierend auf der `invoice_frequency` des Tenants. Testen des PDF Downloads.
- [ ] **Flow AP (Custom Quotes):** UI/E2E: Angebot anfordern, falls Galerie `allow_custom_quotes` aktiv hat.
- [ ] **Flow AQ (Flatrate Watermark Bypass):** PHPUnit: `FileDeliveryController` liefert für User mit `flatrate_level >= web` das Original-Thumb ohne Wasserzeichen aus.
- [ ] **Flow AS (Downscale Editorial):** PHPUnit: `app:downscale-editorial` verkleinert alte redaktionelle Bilder auf 2560px und setzt DB-Flag.
- [ ] **Flow AT (Cleanup Derivatives):** PHPUnit: `app:cleanup-derivatives` löscht verwaiste WebP Thumbs nach 14 Tagen inaktiver Nutzung.
