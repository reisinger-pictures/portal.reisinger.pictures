# Task Board — Portal Reisinger Pictures

> Stand: 2026-07-09. Contract Templates Feature in Umsetzung.

---

## Feature: Contract Templates → 1:1 Instanzen

### SOLL-Zustand (Architektur)

Ein **Template** wird vom Admin einmal erstellt. Jede Signatur via Join-Link erzeugt eine eigenständige **Contract-Instanz** (1:1 Admin ↔ Signer). Das Template bleibt für weitere Signer offen (Mehrfachverwendung).

**Template** (type=template, status=active, join_token, expires_at)
 └─ Instanz A (type=contract, template_id=X, 1 Signer → auto-closed → Rechnung + PDF)
 └─ Instanz B (type=contract, template_id=X, 1 Signer → auto-closed → Rechnung + PDF)

### DB-Änderungen (V026)

**contracts** Tabelle:
- `type` ENUM('contract', 'template') DEFAULT 'contract' — Unterscheidung Template vs. Instanz/Standard-Vertrag
- `template_id` UUID nullable FK → contracts.id — Verweis auf das Template (nur bei Instanzen gesetzt)
- `expires_at` TIMESTAMP nullable — Ablaufdatum des Join-Links (nur bei Templates relevant)

### Flow: Template-Signierung

```
1. Admin: Template draft → active (Join-Link + optional expiry generiert)
2. Signer: /join/{token} → Name/Email/Rollen → System prüft Template aktiv + nicht abgelaufen
3. System (POST /join): Erstellt Contract-Instanz (Kopie aller Template-Daten) + ContractSigner (status=joined)
   → Returns personal_token der INSTANZ (nicht des Templates)
4. Signer: GET /sign/{personalToken} → liest Instanz-Inhalte (bestehender Flow, unverändert)
5. Signer: POST /sign/{personalToken} → Signatur (bestehender Flow, unverändert)
6. System: Nach Signatur → Instanz automatisch schließen → Rechnung (wenn Preis > 0) + PDF + E-Mail
```

### API-Änderungen

| Endpoint | Änderung |
|---|---|
| `POST /api/management/contracts` | Neue Felder: `type`, `expires_at` |
| `PUT /api/management/contracts/{id}` | `expires_at` editierbar bei Templates |
| `GET /api/management/contracts` | Filter via `?type=template` oder `?type=contract` |
| `POST /api/management/contracts/{id}/open` | Bei Templates: `expires_at` validieren (muss in Zukunft liegen) |
| `GET /api/management/contracts/{id}/instances` | **NEU**: Alle Instanzen eines Templates listen |
| `POST /api/management/contracts/{instanceId}/close` | Instanz manuell schließen (falls nicht auto-closed) |
| `POST /api/contracts/join/{token}` | **Templates**: Erstellt Instanz + Signer; **Contracts**: bestehendes Verhalten |
| `GET /api/contracts/sign/{personalToken}` | Unverändert (funktioniert mit Instanzen) |
| `POST /api/contracts/sign/{personalToken}` | Unverändert + **auto-close** nach Signatur bei Instanzen |

### Auto-Close nach Signatur

Instanzen (type=contract, template_id != null) werden nach erfolgreicher Signatur automatisch geschlossen:
- Status → 'closed'
- `ContractCloseService::close($instance)` wird getriggert
- → Rechnung (wenn items + billing_details + total > 0)
- → PDF-Generierung
- → E-Mail an Signer + billing-Empfänger

### Frontend-Änderungen

- **ManagementContractView**: Type-Selector (Vertrag / Vorlage), Expiry-Date-Picker, Instanz-Liste unter Template-Detail
- **useContractManagement.ts**: Neue Felder im Contract-Interface, neue API-Funktion `fetchInstances`
- **ContractListView**: Template-Badge/Icons, Filterung
- **ContractSignView**: Unverändert

---

### Test Cases

#### Backend PHPUnit (Feature)

**ContractControllerTest (erweitern):**
- [ ] `test_can_create_template` — POST mit type=template, expires_at
- [ ] `test_can_open_template_with_valid_expiry` — Template draft → active mit expires_at in Zukunft
- [ ] `test_cannot_open_template_with_past_expiry` — Expiry in Vergangenheit → 422
- [ ] `test_template_has_instances_relation` — GET /{id}/instances listet Instanzen
- [ ] `test_index_filters_by_type` — ?type=template filtert
- [ ] `test_can_close_instance` — Instanz schließen funktioniert

**ContractJoinTest (erweitern):**
- [ ] `test_template_join_creates_instance_and_signer` — POST /join/{templateToken} → erstellt neuen Contract (Instanz) + Signer
- [ ] `test_template_instances_have_correct_template_id` — Instanz hat template_id = Template-ID
- [ ] `test_template_expired_returns_410` — Join-Link mit abgelaufenem expires_at → 410
- [ ] `test_template_join_copies_items_and_terms` — Instanz hat kopierte items/discounts/terms_html
- [ ] `test_template_sign_auto_closes_instance` — Nach Signatur: Instanz status = 'closed'
- [ ] `test_template_sign_triggers_close_service` — Invoice wird erstellt wenn items > 0

**New: ContractTemplateTest (optional, oder in bestehende integrieren)**

#### Backend Unit

- [ ] `test_contract_model_has_type_cast` — type ist im $casts
- [ ] `test_contract_template_relation` — template() und instances() Relationen existieren

#### Frontend Vitest

- [ ] `useContractManagement.test.ts`: Neue Felder in Typen, fetchInstances Funktion
- [ ] `ManagementContractView.test.tsx`: Type-Selector, Expiry-Picker, Instance-List (neue Tests)

#### E2E (Playwright)

- [ ] `@feature:admin:contracts:template` Template erstellen, öffnen, Client signiert via API → Instanz erstellt, auto-closed
- [ ] `@feature:admin:contracts:template` Abgelaufener Template-Link → 410
- [ ] `@feature:admin:contracts:template` Mehrere Clients signieren dasselbe Template → mehrere Instanzen

---

### File Map (geändert/neu)

| File | Aktion |
|---|---|
| `backend/database/migrations/V026__contract_templates.php` | **NEU** |
| `backend/app/Models/Contract.php` | EDIT (casts, relations) |
| `backend/app/Http/Requests/StoreContractRequest.php` | EDIT (type, expires_at) |
| `backend/app/Http/Requests/UpdateContractRequest.php` | EDIT (expires_at) |
| `backend/app/Http/Controllers/ContractController.php` | EDIT (type support, instances endpoint) |
| `backend/app/Http/Controllers/ContractJoinController.php` | EDIT (template join → instance creation) |
| `backend/app/Services/ContractCloseService.php` | EDIT (no changes needed, same close logic) |
| `backend/database/factories/ContractFactory.php` | EDIT (template state) |
| `backend/routes/api.php` | EDIT (instances route) |
| `frontend/src/logic/useContractManagement.ts` | EDIT (types, API) |
| `frontend/src/ui/management/ManagementContractView.tsx` | EDIT (type selector, expiry, instances) |
| `backend/tests/Feature/Contract/ContractControllerTest.php` | EDIT |
| `backend/tests/Feature/Contract/ContractJoinTest.php` | EDIT |
| `backend/tests/Feature/Contract/ContractCloseTest.php` | EDIT |
| `frontend/tests/e2e/admin/contracts.spec.ts` | EDIT |

---

### Agent Tasks

#### Agent 1: Migration + Model + Factory
Files: V026 migration, Contract.php, ContractFactory.php
- Create `V026__contract_templates.php` migration
- Add `type`, `template_id`, `expires_at` columns
- Update Contract model: casts, `template()`, `instances()`, `isTemplate()` scope
- Update ContractFactory: add `template()` state, set `type` default to 'contract'

#### Agent 2: Backend Controllers + Requests + Services
Files: StoreContractRequest, UpdateContractRequest, ContractController, ContractJoinController, routes/api.php
- Add `type`, `expires_at` validation to requests
- ContractController: type in store, expiry validation in open, instances() endpoint, filter by type in index
- ContractJoinController: join() for templates → create instance + signer
- ContractJoinController: sign() → auto-close for instances (type=contract with template_id)
- Add routes

#### Agent 3: Frontend
Files: useContractManagement.ts, ManagementContractView.tsx
- Update Contract interface with type, template_id, expires_at
- Add Instance interface + fetchInstances function
- UI: type selector radio, expiry date picker, instance list table in template detail
- Update useContractJoin.ts if needed

#### Agent 4: Backend Tests
Files: ContractControllerTest, ContractJoinTest, ContractCloseTest
- Add template-specific tests
- Update existing tests for new fields (backward compatible)
- Test auto-close on sign for instances
- Test expiry validation

#### Agent 5: Frontend Tests + E2E
Files: useContractJoin.test.ts, useContractHeartbeat.test.ts, ContractSignView.test.tsx, contracts.spec.ts
- Update vitest mocks for new response shapes
- Add E2E test: template create → open → client sign → instance auto-closed
- Add E2E test: expired template link → 410
- Add E2E test: multi-client → multi-instance
