# 📝 Projekt-Master-Backlog (Kumulativ)

## 🚀 Offene Tasks & Technische Deep-Dives

### 🛠️ E2E Test Fixes (Prio 1 - BLOCKER)
- [ ] **Fix DeliveryView:** Hinzufügen von `useState` in React-Imports in `DeliveryView.tsx`.
- [ ] **Fix Photographer Spec:** Navigation im Test von "Einstellungen" auf "Mein Profil" ändern.

### 🎁 NEU: Kostenlose Downloads (Galerie-/Gruppen-Ebene)
- [ ] **Backend (Auth):** `DownloadController` und `FileDeliveryController` erweitern: Bypass der Lizenzprüfung/Wasserzeichen bei `effective_is_free_download = true`.
- [ ] **Frontend (Admin):** Checkbox "Kostenloser Download" in `GalleryModal.tsx` und `GalleryGroupModal.tsx` hinzufügen.
- [ ] **Frontend (Client):** `DeliveryView.tsx` anpassen: Sofort-Download Button anzeigen, wenn `is_free_download` aktiv.
- [ ] **Testing:** PHPUnit Test hinzufügen: Gast lädt aus "Free Download" Galerie ohne Lizenz.

### 🛡️ Security & Backend Tests (PHPUnit)
- [ ] **Flow S:** Customer Manager Scoping (Zero Trust).
- [ ] **Flow U:** Tenant Isolation (IDOR Schutz zwischen Mandanten).
- [ ] **Flow W:** Pessimistic Locking (InvoiceSequence).
- [ ] **Flow AC:** CRON-Job Validierung für Sammelrechnungen.
- [ ] **Flow AD:** Watermark Tile Cache Validation (Hash-Prüfung).
- [ ] **Flow AI (Zero Trust Boundary):** PHPUnit: `customer_manager` versucht, `UserController@update` oder `UserController@destroy` für eine `user_id` eines fremden Mandanten aufzurufen -> System muss strikt 403 Forbidden werfen.


### 🧪 E2E Tests (Playwright)
- [ ] **Flow AE:** Mandanten-Einladung & Registrierung.
- [ ] **Flow P:** Flatrate-Bypass (Sofort-Download).
- [ ] **Flow Q:** Warenkorb & Checkout-Formular (Austria MVP).
- [ ] **Flow AB:** Manuelle Sammelrechnung im Tenant-Dashboard.
- [ ] **Flow AG (Upselling):** Lizenzen im Warenkorb anpassen und Preis-Updates validieren.
- [ ] **Flow AH (Order ZIP):** Komplette Bestellung als ZIP herunterladen.
- [ ] **Flow AJ (Delta-Pricing Lifecycle):** E2E: Bild-Upgrade mit Delta-Pricing -> Checkout -> Order im Admin-Dashboard prüfen.
- [ ] **Flow AK (E2E Teardown Integrity):** E2E: Sicherstellen, dass `UserController@destroy` beim Teardown den Test-User restlos entfernt.
