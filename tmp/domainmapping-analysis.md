# DomainMapping → Tenant Migration: Verlustanalyse

Stand: 2026-07-03, 20:30 UTC  
Untersucht von: AI Agent (big-pickle)  
Auftrag: Senior Architekt Florian Reisinger

---

## 1. Ausgangslage

Zwei Konzepte wurden im Laufe der Projektentwicklung vermischt:

| Konzept | Soll | Code | Status |
|---------|------|------|--------|
| **Brand** | White-Label (rp vs srp), unsichtbar, via URL | `Brand` Enum, `BrandRegistry` | ✅ Korrekt implementiert |
| **Organisation** | **Automatische** Kunden-Gruppierung per E-Mail-Domain, DSGVO-Invites, Flatrates, Sammelrechnung | `Tenant` Model + Controller | ❌ Falscher Ansatz |

---

## 2. Historische Entwicklung

### Phase 1: DomainMapping (Ursprung, gelöscht in `ca34a78`)
- **Model**: `DomainMapping.php` (29 Zeilen) — `domain`, `role_id`, `gallery_group_id`
- **Controller**: `DomainMappingController.php` (33 Zeilen) — CRUD
- **Frontend**: `DomainMappingTab.tsx` (92 Zeilen) — Tabelle + Create-Formular in der Benutzerverwaltung
- **Registration**: Automatische Erkennung via `substr(strrchr($email, "@"), 1)` → Mapping-Lookup → Role + GalleryGroup zuweisen
- **Gallery Access**: Automatisch für Delivery-Galerien der verknüpften Gruppe
- **Kein Invite-System**: Volle Automatik, keine Bestätigung nötig

### Phase 2: Tenant-System (eingeführt in `ca34a78`)
- DomainMapping wurde **komplett gelöscht** (`DROP TABLE domain_mappings`)
- Ersetzt durch: `tenants`, `tenant_user`, `gallery_group_tenant`, `tenant_invites`
- Fokus auf **manuelle** B2B-Kundenverwaltung mit Sammelrechnung
- Invite-Flow hinzugefügt (Token per E-Mail, Expiration, Redeem)

### Phase 3: Umbenennung (in `28b7fc6`)
- UI-Text "Mandanten" → "Organisationen (B2B)"
- Keine inhaltliche Änderung

---

## 3. Was verloren ging (Regressionen)

### 3.1. Automatische Rollen-Zuweisung per Domain ❌
**Alt (DomainMapping):** Jede Domain konnte eine beliebige Role zuweisen (admin, photographer, client).
**Neu (Tenant):** Hartcodiert auf `client`-Role in `AuthController::register()`. Keine Möglichkeit, z.B. `@fotograf.de` automatisch Photographer-Rolle zu geben.

**Relevante Code-Stelle:**
```
backend/app/Http/Controllers/AuthController.php — registration logic
→ $tenant = Tenant::where('domain', $domain)->first();
→ $user->tenants()->attach($tenant->id);
→ $clientRole = Role::where('name', 'client')->first();  // HARTCODIERT
```

### 3.2. DSGVO-konformes Invite-System fehlt im Auto-Join ❌
**Alt:** Keine Invites nötig — voll automatisch, aber auch keine Einwilligung.
**Neu:** Invite-System ist rein manuell (Admin lädt ein). Der Auto-Join per Domain umgeht das Invite-System komplett — ein User wird ohne Bestätigung einer Organisation zugeordnet.

**Erwartet:** Bei Auto-Join per Domain müsste ein **DSGVO-konformer Invite-Prozess** durchlaufen werden:
1. User registriert sich mit `@firma.com`
2. System findet passende Organisation
3. Organisation hat `auto_join` oder `requires_invite` Flag
4. Bei `requires_invite`: Organisation benachrichtigen → Freigabe → User erhält Zugriff

### 3.3. Super-User (power_user) kann trotz Flatrate kaufen ❌
**Alt:** War nicht im DomainMapping, aber Konzept existiert im neuen System als `is_power_user`.
**Problem:** Die Verknüpfung zwischen Organisation und `power_user`-Status fehlt:
- Ein User kann `can_purchase_upgrades = true` haben
- Aber es gibt keine Möglichkeit, dies **per Organisation** zu steuern
- `flatrate_level` ist ein User-Attribut, kein Organisations-Attribut

**Erwartet:** Organisation definiert:
- `default_flatrate_level` (none/web/print/original)
- `can_purchase_upgrades` (bool, default false)
- Beim Auto-Join erbt der User diese Werte

### 3.4. Zahlungsintervall fehlt auf Organisation ❌
**Alt:** Kein Zahlungsintervall (gab es nicht).
**Neu:** `invoice_frequency` existiert auf Tenant, wird aber **nirgends ausgewertet**.
- Datenbank: `invoice_frequency ENUM('immediate','monthly','quarterly') DEFAULT 'immediate'`
- Aber: Kein Cron-Job, keine Logik, die monatliche/vierteljährliche Rechnungen erzeugt
- Einzige Nutzung: `generateCollectiveInvoice()` — manuell triggered

### 3.5. Auto-Join umgeht Tenant-Brand-Isolation ❌
**Alt:** Brand-Konzept existierte nicht.
**Neu:** Wenn ein User mit `@firma.com` registriert, aber die Organisation hat `brand = 'srp'`, während der User auf `rp`-Domain registriert — was passiert? → Brand-Konflikt.

**Code:** Brand wird via `BrandContextMiddleware` aus dem Host ermittelt. Bei Registration wird **nicht** geprüft, ob die gefundene Organisation zum aktuellen Brand passt.

### 3.6. Customer Manager kann keine User anlegen ❌
In `UserController::store()`:
```php
if ($request->user()->isCustomerManager()) {
    return response()->json(['error' => 'Not implemented for Customer Managers yet.'], 403);
}
```
Customer Manager soll User seiner Organisation verwalten können — ist aber nicht implementiert.

### 3.7. Tenant-Detail-View hat keine User-Übersicht ❌
`ManagementTenantDetailView.tsx` zeigt User nicht direkt an; man muss umständlich navigieren.

---

## 4. Falsche Tests

### 4.1. `backend/tests/Feature/TenantIsolationTest.php` — teilweise falsch
Testet Customer Manager Isolation, aber:
- `test_customer_manager_cannot_update_cross_tenant_user` → Update via `PUT /api/management/users/{id}` — das Feature ist **noch nicht implementiert** (s.o.)
- Der Test könnte grün sein, weil ein 403 zurückkommt, aber aus dem falschen Grund

### 4.2. `backend/tests/Feature/TenantControllerTest.php`
- Testet Admin-CRUD für Tenants als `POST /api/management/tenants`
- Erzeugt **keinen** Auto-Join-Test (domain-basierte Registration)
- Testet **nicht**, ob `invoice_frequency` korrekt gespeichert/ausgelesen wird

### 4.3. `backend/tests/Feature/TenantInviteControllerTest.php`
- Testet nur den manuellen Invite-Flow
- Testet **nicht** den Auto-Join via Domain bei Registration
- Testet **nicht** DSGVO-konformen Ablauf (Einwilligung vor Beitritt)

### 4.4. `frontend/tests/e2e/admin/tenant-dashboard.spec.ts`
- Testet Erstellung + Invite + Redeem
- Testet **nicht**:
  - Auto-Join per Domain
  - Flatrate-Level-Vererbung
  - Power-User-Upgrade-Flag
  - Brand-konforme Registrierung
  - Sammelrechnungserstellung (nur Button-Check)

### 4.5. `frontend/tests/e2e/brand/brand-isolation.spec.ts`
- Testet Sichtbarkeit des "Organisationen (B2B)"-Menüpunkts
- Verwendet UI-Label "Mandanten" im Test-`describe` (nicht in Locators)
- Testet **nicht**, ob die Brand-Isolation bei Auto-Join greift

### 4.6. `frontend/tests/e2e/brand/gallery-brand-scoping.spec.ts`
- Gleiches Problem: Test-Descriptions sagen "Mandanten" statt "Organisation"
- Testet nicht die Domain-basierte Gallery-Zugriffssteuerung

---

## 5. Migrations-Analyse

### 5.1. Alle Migrationen (V001–V018)

| Datei | Änderungen |
|-------|-----------|
| **V001** | Kern-Schema: `users`, `roles`, `gallery_groups`, **`domain_mappings`**, `galleries`, `photos`, `download_logs`, `gallery_invites`, `settings`, Pivot-Tabellen |
| **V002** | `locations`-Tabelle |
| **V003** | `gallery_invites.can_edit_metadata` |
| **V004** | **`domain_mappings` DROPPED**, `tenants`, `tenant_user`, `gallery_group_tenant`, `tenant_invites` erstellt. E-Commerce. Governance-Flags. |
| **V005** | `photographer_galleries`, `photographer_gallery_groups`. **`down()` defekt** |
| **V006** | `customers`, `text_snippets` |
| **V007** | `orders.ip_address`, `orders.stripe_payment_intent_id` |
| **V008** | UUID-Umstellung `pricing_factors`/`license_options`. **`down()` ist leer** |
| **V009** | `products` |
| **V010** | `license_use_cases`, `license_modifiers` |
| **V011** | `payout_pools`, `photographer_statements` |
| **V012** | `orders.stripe_fee_cents` |
| **V013** | Keywords string(255)→text |
| **V014** | `photos.filename` entfernt, boolean-normalisierung |
| **V015** | `photos.captured_at` |
| **V016** | 3 Calculator-Settings |
| **V017** | `gallery_groups.tenant_id` + `galleries.tenant_id` (nullable FK→tenants, ON DELETE SET NULL) |
| **V018** | Brand-Spalte an 12 Tabellen, `coupons`, `coupon_user_usage`, **settings PK-Entfernung** |

### 5.2. Kritische Migrations-Probleme

1. **V005 `down()` defekt**: Falsche Klammer-schachtelung — Rollback würde fehlschlagen.
2. **V008 `down()` leer**: `// Rollback logik` ohne Code.
3. **V018 `down()` entfernt `settings`-PK ohne Wiederherstellung**: Nach Rollback hat `settings` **keinen Primary Key**.
4. **Doppelte Tenant-Zuordnung (V017)**: `galleries`/`gallery_groups` haben sowohl direkte `tenant_id` als auch Pivot `gallery_group_tenant`. Zwei konkurrierende Quellen.
5. **SET NULL-Inkonsistenz (V017)**: Löscht man Tenant → `galleries.tenant_id` = NULL (SET NULL) aber `gallery_group_tenant` gelöscht (CASCADE).
6. **Kein CASCADE von `gallery_groups` → `galleries`**: `galleries.gallery_group_id` hat ON DELETE SET NULL.
7. **`coupons` verwendet `bigIncrements`** statt UUID.
8. **`coupon_user_usage.user_id` ist `char(36)` ohne FK.**

### 5.3. Domänen-ID-Inkonsistenzen

| Entität | PK-Typ | Problem |
|---------|--------|---------|
| Alle Domain-Entitäten | UUID (V001-V017) | Einheitlich |
| `coupons` (V018) | `bigIncrements` | **Bricht mit Konvention** |
| `coupon_user_usage` (V018) | `bigIncrements` | **Bricht mit Konvention** |
| `invoice_snapshots` (V008) | `string(invoice_number)` | Akzeptabel (Buchhaltung) |
| `invoice_sequences` (V008) | `integer(year)` | Akzeptabel (Buchhaltung) |

---

## 6. Brand-Detection: Kompaktheits-Analyse

### 6.1. Aktuelle Verteilung (16 Dateien)

| Schicht | Datei | Zeilen |
|---------|-------|--------|
| Backend Enum | `backend/app/Enums/Brand.php` | 45 |
| Backend Registry | `backend/app/Support/BrandRegistry.php` | 103 |
| Backend Middleware | `backend/app/Http/Middleware/BrandContextMiddleware.php` | 32 |
| Backend Mail Trait | `backend/app/Mail/BrandAwareMail.php` | 49 |
| Frontend Registry | `frontend/src/logic/brandRegistry.ts` | 33 |
| Frontend Hook | `frontend/src/logic/useBrand.ts` | 34 |
| Frontend HTML | `frontend/index.html` (inline) | 18 |
| Frontend Theme | `frontend/src/main.tsx` (applyTheme) | 3 |
| + Feature Doc | `features/infrastructure/12-brand-registry-and-settings-fixes.md` | 120 |

### 6.2. Kern-Logik (2 Zeilen pro Seite)

```
Backend:  str_starts_with($host, 'buy.') → SRP, sonst B2B   (BrandRegistry:fromHost)
Frontend: hostname.toLowerCase().startsWith('buy.') → SRP    (brandRegistry:getBrandFromHostname)
```

### 6.3. Redundanzen

1. **`Brand::domain()`**: Definiert in `Brand.php`, **nirgends aufgerufen** → toter Code
2. **`Brand::label()`**: Gibt für beide Brands `'Reisinger Foto Portal'` zurück → sinnlos
3. **`index.html` vs `brandRegistry.ts`**: Zwei verschiedene Strategien:
   - `index.html`: `hostname.includes('buy.reisinger.pictures')` (hardcodierte Production-Domain!)
   - `brandRegistry.ts`: `startsWith('buy.')` (Prefix-basiert, dev-kompatibel)
   - Bug B2: `includes` matcht nicht auf `buy.localhost`
4. **`useLicensingMode.ts`**: 15 Zeilen für `brand === 'srp' ? 'volume_licensing' : 'scope_licensing'`

### 6.4. Vorschlag

Kern auf **3 Dateien** reduzieren, Rest inlinen oder entfernen:

| Behalten | Entfernen/Inlinen |
|----------|-------------------|
| `backend/app/Support/BrandRegistry.php` | `backend/app/Enums/Brand.php` (Werte in Registry) |
| `frontend/src/logic/brandRegistry.ts` | `frontend/src/logic/useBrand.ts` (in Registry) |
| `frontend/index.html` (fix) | `backend/app/Mail/BrandAwareMail.php` (in InvoiceMail) |
| | `Brand::domain()` (tot) |
| | `Brand::label()` (tot) |
| | `isSrpBrand()` (`brand === 'srp'` reicht) |

---

## 7. Bugs im aktuellen System

| ID | Beschreibung | Fundort |
|----|-------------|---------|
| **B1** | NaN € in Admin-Orders | `/admin-orders` — fehlende Preisberechnung bei Angeboten |
| **B2** | SRP-Detection `includes` vs `startsWith` | `index.html` Zeile ~19 |
| **B3** | Leeres `<document>` bei SPA-Direktnavigation | Alle Routes außer `/` |
| **B4** | 50+ stale E2E-Test-User | Datenbank |
| **B5** | `users?.filter is not a function` | `GalleryAccessModal.tsx:28` |
| **B6** | Coupon-Seite ohne Sidebar-Link | `/admin-coupons` |
| **B7** | Doppelte Sidebar in `/my-payouts` | `/my-payouts` |

---

## 8. Handlungsempfehlungen

### Kurzfristig (Schnelle Korrekturen)
1. **B5 fixen**: `GalleryAccessModal.tsx:28` — `users?.data?.filter` oder API-Response wrappen
2. **B7 fixen**: `/my-payouts` — Layout-Komponente prüfen (doppeltes Rendering)
3. **B2 fixen**: `index.html` — `includes` → `startsWith`

### Mittelfristig (Architektur-Korrektur)
1. **DomainMapping reaktivieren oder Tenant erweitern:**
   - Option A: DomainMapping als Lightweight-Alternative zurückbringen
   - Option B: Tenant um `auto_join`, `default_role_id`, `default_flatrate_level`, `can_purchase_upgrades` erweitern
2. **DSGVO-Invite-Flow für Auto-Join:**
   - `tenant.auto_join_policy`: `immediate` | `requires_invite` | `disabled`
   - Bei `requires_invite`: Pending-User-Tabelle + Freigabe-Workflow
3. **Brand-Isolation bei Registration prüfen:**
   - Wenn Tenant `brand = 'srp'`, aber User registriert auf `rp`-Domain → Error
4. **Customer Manager User-Erstellung implementieren:**
   - `UserController::store()` für Customer Manager aktivieren (auf Tenant beschränkt)

### Langfristig (Test-Qualität)
1. **Tests korrigieren:**
   - Alle Test-Descriptions "Mandanten" → "Organisationen"
   - TenantIsolationTest: Customer Manager kann User NICHT updaten (403) → Dokumentieren, dass Feature fehlt
2. **Neue Tests schreiben:**
   - Auto-Join per Domain + Brand-Isolation
   - Flatrate-Level-Vererbung von Organisation auf User
   - DSGVO-konformer Invite-Flow mit Einwilligung
   - `invoice_frequency` korrekte Verarbeitung
   - Power-User-Upgrade in Kombination mit Flatrate
