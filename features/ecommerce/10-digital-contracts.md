---
domain: ecommerce
topic: digital-contracts
status: implemented
---

# Technical Concept: Digital Contracts & Signatures

## 1. Legal Framework & Audit Trail (SES / Clickwrap)
Das System implementiert eine Einfache Elektronische Signatur (EES / SES) via Clickwrap-Verfahren.
Da ein Vertrag mehrere Unterzeichner haben kann, wird der Audit-Trail pro Unterzeichner geführt.
Die `contract_audit_logs` protokollieren:
* **opened:** Unterzeichner öffnet den Link (erfasst IP-Adresse, User-Agent, Timestamp).
* **heartbeat:** Aktives Lesen wird protokolliert.
* **signed:** Unterzeichner stimmt zu (erfasst finale IP, User-Agent, Timestamp und gewählte Rollen).
* **modified:** Vertrag wurde im `active`-Status durch Admin bearbeitet (kein Signer-Bezug, erfasst Admin-IP).

## 2. Multi-Signer & Signing Periods
Verträge sind nicht mehr strikt 1:1, sondern 1:n (Vertrag zu Unterzeichnern).
* **Signing Period:** Ein Vertrag hat einen Zustand (Draft, Active, Closed, Cancelled). Unterschriften können nur im `Active`-Zustand abgegeben werden. Die Periode kann manuell oder durch ein `closes_at` Datum beendet werden.
* **Rollen (Roles):** Pro Vertrag können verfügbare Rollen definiert werden (z.B. "Model", "Visagist", "Kunde"). 
* **Multiple Roles:** Ein Flag `allow_multiple_roles_per_signer` steuert, ob eine Person (z.B. Model & Visagist in Personalunion) mehrere Rollen gleichzeitig annehmen darf.
* **Join-Link vs. Direct-Link:** Unterzeichner können entweder vom Fotografen explizit eingeladen werden (Direct-Link) oder über einen generischen Join-Link selbst beitreten, ihre Daten angeben, eine Rolle wählen und unterschreiben (ideal für Fotowalks/Gruppen-TFP).

## 3. Database Schema
* **`contracts`**: `id` (UUID), `status` (draft, active, closed, cancelled), `billing_details` (JSON - falls der Vertrag kostenpflichtig ist, gibt es *einen* Rechnungsempfänger), `items` & `discounts` (JSON), `terms_html` (Text), `available_roles` (JSON Array), `allow_multiple_roles_per_signer` (Boolean), `join_token` (String, für öffentliche Invites), `closes_at` (Nullable Timestamp, Auto-Ende), `content_version` (Integer, default 0 — wird bei Bearbeitung im active-Status inkrementiert), `created_at`, `updated_at`.
* **`contract_signers`**: `id` (UUID), `contract_id` (FK UUID), `name`, `email`, `roles` (JSON Array), `personal_token` (String), `status` (invited, joined, signed), `signed_at` (Nullable Timestamp), `created_at`, `updated_at`.
* **`contract_audit_logs`**: `id` (BIGINT auto-increment), `contract_id` (FK UUID), `contract_signer_id` (FK UUID), `action` (VARCHAR: opened, heartbeat, signed), `ip_address` (VARCHAR), `user_agent` (TEXT), `created_at`.

## 4. Token Auth Architecture (Reuse Invite Pattern)
Keine JWT-Auth für Contract-Signer — simpler DB-Token-Lookup (identisch zum `GalleryInvite`-Pattern mit `Str::random(64)`):

* **Join-Link:** `contracts.join_token` (`Str::random(64)`) — öffentlich, kein Login nötig
  * GET `/api/contracts/join/{token}` → Contract-Metadaten + available_roles (analog `InviteController::check()`)
  * POST `/api/contracts/join/{token}` → Signer-Eintrag mit gewählter Rolle → returns personal_token
* **Personal Token:** `contract_signers.personal_token` (`Str::random(64)`) — pro Signer, kein JWT
  * GET `/api/contracts/sign/{personal_token}` → Contract-Content + Signer-Info (Heartbeat-Log)
  * POST `/api/contracts/sign/{personal_token}` → Clickwrap-Signature + Audit-Log
* **Code-Reuse:**
  * `Str::random(64)` Token-Generierung (identisch GalleryInvite)
  * `$request->ip()` + `$request->userAgent()` für Audit-Log
  * `AbstractBrandAwareMailable` + `Pdf::loadView()` + `attachData()` für ContractClosedMail
  * `OfferTokenService` für `%OFFER_JWT%` Marker im PDF
  * `InvoiceService`-Pattern für Auto-Invoicing

## 5. Content Versioning & Stale-Detection (Lock on First Signature)

### Problem
Ein Admin soll einen aktiven Vertrag noch korrigieren können, solange kein Unterzeichner unterschrieben hat. Sobald ein Signer jedoch die Seite geöffnet hat, darf er nicht versehentlich eine alte Version signieren.

### Lösung: `content_version`
* **`contracts.content_version`** (Integer, default 0) wird bei jeder Bearbeitung eines `active`-Vertrags **inkrementiert**.
* Der Heartbeat-Endpoint (`GET /api/contracts/sign/{personal_token}`) gibt das aktuelle `content_version` zurück.
* Das Frontend speichert die Version beim ersten Laden und vergleicht sie bei jedem Heartbeat-Tick (30s).
* **Bei Abweichung:** Frontend zeigt Warning-Banner ("Vertrag wurde geändert — bitte neu laden") und deaktiviert den Sign-Button.
* Der Sign-Endpoint (`POST /api/contracts/sign/{personal_token}`) **erfordert `content_version` im Body** und lehnt ab, wenn es nicht mit der aktuellen Vertragsversion übereinstimmt (409 Conflict).

### Guard
```php
// ContractController::update()
// Erlaubt: status === 'draft'
// Erlaubt: status === 'active' UND kein Signer mit status === 'signed'
// Verboten: status === 'active' MIT signed signer → 403
// Verboten: status === 'closed' || status === 'cancelled' → 403
```

Bei Bearbeitung eines `active`-Vertrags wird zusätzlich ein `modified`-Audit-Log-Eintrag geschrieben.

## 6. Smart Document Integration & Auto-Invoicing
1. **Auto-Invoicing Trigger:** Das Auto-Invoicing (Erstellung von `Order` und `InvoiceSnapshot`) wird erst ausgelöst, wenn der Vertrag in den Status **`closed`** übergeht UND die `total_gross` > 0 ist. Rechnungsempfänger ist die in `billing_details` definierte Person.
2. **JWT-Fallback (Polyglot PDF):** Das finale PDF enthält weiterhin den `%OFFER_JWT:{token}%` Marker für den Re-Import in den Manual Invoice Builder.

## 7. Final PDF Compilation & Dispatch
Erst wenn der Vertrag geschlossen wird (`status = closed`), wird das finale, unveränderliche PDF generiert:
1. **Seite 1-X:** Vertragsdetails und Text (reused pattern: `manual_offer.blade.php`).
2. **Signatur-Block:** Eine Auflistung aller Personen, die unterschrieben haben, inkl. ihrer jeweiligen Rollen.
3. **Zertifikats-Anhang:** Das Digitale Signatur-Zertifikat listet tabellarisch für **jeden** Unterzeichner die IPs, Timestamps (Opened, Signed) auf.
4. **Dispatch:** Nach der Generierung erhalten alle E-Mail-Adressen aus der `contract_signers` Tabelle das finale PDF zugesendet.

## 8. API Routes

### Management (auth:api + management + super_admin)
```
POST   /api/management/contracts              → store (create draft)
GET    /api/management/contracts              → index (list all)
GET    /api/management/contracts/{id}         → show (detail + signers)
PUT    /api/management/contracts/{id}         → update (draft OR active ohne signed signers)
POST   /api/management/contracts/{id}/open    → open (draft → active, create join_token)
POST   /api/management/contracts/{id}/close   → close (active → closed, trigger PDF+Mail+AutoInvoice)
```

### Public (no auth, token-based)
```
GET    /api/contracts/join/{token}            → metadata + available_roles
POST   /api/contracts/join/{token}            → join (name, email, roles) → personal_token
GET    /api/contracts/sign/{personal_token}   → contract content + signer info (heartbeat)
POST   /api/contracts/sign/{personal_token}   → submit clickwrap signature (erfordert content_version, 409 bei Staleness)
```

## 9. File Map

### Backend
| File | Purpose |
|------|---------|
| `database/migrations/V021__digital_contracts.php` | Tables: contracts, contract_signers, contract_audit_logs |
| `database/migrations/V022__add_content_version_to_contracts.php` | Add content_version column |
| `database/migrations/V023__add_modified_action_to_contract_audit_logs.php` | Add 'modified' to audit action enum |
| `app/Models/Contract.php` | Model + relationships |
| `app/Models/ContractSigner.php` | Model + relationships |
| `app/Models/ContractAuditLog.php` | Model + relationships |
| `database/factories/ContractFactory.php` | Test factory |
| `database/factories/ContractSignerFactory.php` | Test factory |
| `database/factories/ContractAuditLogFactory.php` | Test factory |
| `app/Http/Controllers/ContractController.php` | Management CRUD + open/close |
| `app/Http/Controllers/ContractJoinController.php` | Public join/sign endpoints |
| `app/Http/Requests/StoreContractRequest.php` | Validation |
| `app/Http/Requests/UpdateContractRequest.php` | Validation |
| `app/Services/ContractAuditService.php` | IP + User-Agent logging |
| `app/Services/ContractPdfService.php` | Multi-signer PDF generation |
| `app/Services/ContractCloseService.php` | Close orchestration (invoice + PDF + mail) |
| `app/Mail/ContractClosedMail.php` | PDF email to all signers |
| `resources/views/pdf/contract_signatures.blade.php` | PDF template |
| `routes/api.php` | Route definitions |

### Frontend
| File | Purpose |
|------|---------|
| `src/ui/management/ManagementContractView.tsx` | Contract builder/manager |
| `src/logic/useContractManagement.ts` | SWR hooks for management API |
| `src/ui/ContractJoinView.tsx` | Public join page (token-based) |
| `src/ui/ContractSignView.tsx` | Public sign page (personal token) |
| `src/logic/useContractJoin.ts` | SWR hooks for public API |
| `src/App.tsx` | Add /admin-contracts, /contracts/join/:token, /contracts/sign/:token |
| `src/ui/management/ManagementDashboard.tsx` | Add contract view switch |
| `src/ui/components/Sidebar.tsx` | Add "Verträge" menu entry |

### Tests
| File | Purpose |
|------|---------|
| `tests/Feature/Contract/ContractControllerTest.php` | Management API CRUD tests |
| `tests/Feature/Contract/ContractJoinTest.php` | Public join/sign token flow |
| `tests/Feature/Contract/ContractCloseTest.php` | Close → invoice → PDF → mail |
| `tests/Unit/ContractAuditServiceTest.php` | Audit log unit tests |
| `src/logic/__tests__/useContractManagement.test.ts` | Vitest hook tests |
| `src/logic/__tests__/useContractJoin.test.ts` | Vitest hook tests |
| `tests/e2e/admin/contracts.spec.ts` | E2E: create → open → sign → close |
