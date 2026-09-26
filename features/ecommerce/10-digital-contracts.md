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

### Deadline Inheritance and Telemetry Semantics

* Öffentliche Join-, Content- und Signaturpfade prüfen `status`, `expires_at` und `closes_at` anhand der Datenbankzeit. Create-/Sign-Transaktionen serialisieren den Contract-/Template-Zugriff per Row-Lock; Signaturen verwenden zusätzlich ein bedingtes Database-Time-Update.
* Neue Template-Instanzen übernehmen die Deadline-Werte ihres Templates. Legacy-Instanzen mit `NULL`-Deadlines lösen ihre effektive Deadline dynamisch vom Template auf. Das Template ist dabei eine harte Obergrenze; bestehende Zeilen werden nicht per Mass-Update backfilled.
* `POST /api/contracts/sign/{personalToken}/page-exit` ist Telemetrie, kein Signatur- oder Autorisierungs-Gate. Ein gültiger persönlicher Token mit verknüpftem Contract wird auch nach Close/Signatur akzeptiert; der Audit-Action-Wert ist `page_exit`. Ungültige Tokens liefern `404`.

### Join-Identität und V039-Dateninvariante

* Jeder `contract_signers`-Datensatz erhält zwei kanonische Identitätsfelder: `normalized_email` (`lower(trim(email))`) und `join_scope_key`. Der unveränderte Originalwert in `email` bleibt erhalten.
* Der Scope ist ein unveränderlicher Snapshot: `contract:<contract UUID>` für direkte Verträge und `template:<template UUID>` für **jede** Instanz, die aus einer Vorlage erzeugt wurde. Das Löschen oder Umhängen einer Vorlage ändert die Identität bestehender Signer nicht.
* V039 führt den Preflight vor dem Backfill aus. Der Preflight leitet fehlende Identitäten deterministisch ab, validiert bestehende Snapshots, und bricht bei malformed/ambiguous Zeilen ab. Für Legacy-Verträge ist `template_id = NULL` nur mit einem nicht-leeren `join_token` eindeutig direkt; bei `template_id = NULL` und `join_token = NULL` wird der Eintrag als historisch mehrdeutig (Direktabzug vs. gelöschte Vorlage) berichtet, auch wenn bereits ein Snapshot gespeichert ist. Ein nicht-null `template_id` verlangt weiterhin einen gültigen Template-Datensatz; ein vorhandener gültiger Template-Snapshot wird bei bekannter/erhaltener Template-Historie nicht neu berechnet. Bei Duplikaten werden maximal 20 Gruppen mit maximal 20 Signer-IDs pro Gruppe in einem Log/Eintrag und einer klaren Exception ausgegeben. Es werden weder Signer noch Audit-Daten gelöscht oder zusammengeführt.
* Erst nach einem sauberen Preflight werden die Felder befüllt und der Unique-Index `contract_signers_scope_normalized_email_unique` auf `(join_scope_key, normalized_email)` angelegt. MySQL/MariaDB und PostgreSQL verwenden physische `NOT NULL`-Spalten; SQLite erzwingt die erforderlichen und unveränderlichen Werte wegen der fehlenden sicheren Online-Nullability-Änderung über BEFORE INSERT/UPDATE-Trigger. Die INSERT-Guards laden den referenzierten Contract sowie den optionalen Template-Datensatz, prüfen deren Typen und vergleichen sowohl `normalized_email = LOWER(TRIM(email))` (einschließlich maximal 255 Bytes gemäß der kanonischen PHP-Grenze) als auch den daraus abgeleiteten Scope. UPDATE-Guards leiten bewusst nicht neu ab: Sie verwerfen Contract-, E-Mail- und beide Identity-Feldänderungen, damit ein Template-Relink oder eine Löschung den gespeicherten Snapshot nicht verändert. MySQL/MariaDB-Trigger-Lokale sind binär; UUIDs werden vor den Vergleichen explizit als `CHAR` normalisiert, sodass gemischte Server-/Tabellenkollationen wie `utf8mb4_uca1400_ai_ci` und `utf8mb4_unicode_ci` keinen SQLSTATE-1267-Fehler auslösen. Nicht unterstützte Datenbank-Treiber werden fail-closed abgebrochen, bevor DDL ausgeführt wird.
* Der Maintenance-Window-Rollout ist fail-closed: Schreiber/Worker anhalten, den Preflight-Bericht prüfen und die betroffenen Legacy-Zeilen manuell entscheiden, `php artisan migrate --force` ausführen, danach `php artisan db:seed --force`; erst nach erfolgreichem LaufWriter wieder starten. Ein Retry validiert bereits befüllte Snapshots, repariert nur fehlende Felder und ist nach partieller DDL-/Index-/Trigger-Ausführung vorgesehen. Während des pausierten Laufs werden die Guard-Trigger für den Backfill kurzzeitig entfernt und anschließend wiederhergestellt; auch ein fehlgeschlagener Preflight stellt den fail-closed Guard-Zustand wieder her.
* Der Unique-Index ist die letzte Autorität auch bei einem Race zwischen zwei Verbindungen. Beide Join-Writer prüfen die kanonischen Felder, fangen den passenden Unique-Constraint-Fehler erst außerhalb der DB-Transaktion ab und geben ausschließlich einen generischen 409 ohne Token, Namen oder Rollen zurück. Template-Instance und Signer werden bei jedem Signer-Fehlschlag gemeinsam zurückgerollt.
* Eine bestehende Snapshot-Spalte wird niemals aus der aktuellen Vorlage neu berechnet. Ein fehlender oder ungültiger Template-/Contract-Scope wird abgewiesen; die Migration errät keine Historie. Das Backfill verändert keine bestehende E-Mail, keinen Zeitstempel, keine Tokens und keine Audit-Zeile.
* Operative Grenzen: Der Preflight läuft in 500-Zeilen-Chunks, legt die Report-/Datenänderungen in einer Transaktion an und ist kein Online-Backfill. Ein großer Legacy-Bestand muss im Wartungsfenster vollständig geprüft werden; bei einem Report-Abbruch wird keine Teilbereinigung als Erfolg interpretiert.

## 3. Database Schema
* **`contracts`**: `id` (UUID), `status` (draft, active, closed, cancelled), `billing_details` (JSON - falls der Vertrag kostenpflichtig ist, gibt es *einen* Rechnungsempfänger), `items` & `discounts` (JSON), `terms_html` (Text), `available_roles` (JSON Array), `allow_multiple_roles_per_signer` (Boolean), `join_token` (String, für öffentliche Invites), `closes_at` (Nullable Timestamp, Auto-Ende), `content_version` (Integer, default 0 — wird bei Bearbeitung im active-Status inkrementiert), `created_at`, `updated_at`.
* **`contract_signers`**: `id` (UUID), `contract_id` (FK UUID), `name`, `email`, `normalized_email` (canonical identity), `join_scope_key` (immutable direct/template scope snapshot), `roles` (JSON Array), `personal_token` (String), `status` (invited, joined, signed), `signed_at` (Nullable Timestamp), `created_at`, `updated_at`. The durable identity index is `(join_scope_key, normalized_email)`.
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

### Authoritative pricing snapshot

* Contract item/fixed-discount `price` values and quantities are canonical integer cents/whole units. Percentage-discount `price` values are integer basis points (`10% = 1000`). Contract wire integers are no larger than JavaScript `Number.MAX_SAFE_INTEGER` (`9007199254740991`).
* Manual invoices keep the same integer-cent prices/basis-point rates, but quantities are explicitly fixed-point hundredths: new UI payloads send `quantity_scale: 100`, so `0.25` is serialized as `qty: 25`. The marker is accepted on every manual wire line; discount rows may carry the same `qty`/`quantity_scale` metadata, which pricing ignores. Direct legacy API payloads without `quantity_scale` remain accepted when their positive decimal quantity has at most two exact places; the backend normalizes them to the same hundredths before calculating. Offer-PDF roundtrips retain the scale marker so the editor restores the decimal quantity.
* The shared invoice table exposes an explicit manual mode (`step=0.25`, `min=0.25`) and a contract mode (`step=1`, `min=1`); this keeps the existing manual product behavior visible without weakening the contract snapshot's whole-unit rule.
* New contract writes accept only canonical JSON integers. Contract reads may normalize older integer-like strings and integral floats within the bound; manual legacy quantity reads additionally accept exact decimals with at most two places. Decimal/exponent ambiguity, non-finite/non-integral contract values, and every value above the safe maximum are rejected before totals or PDFs are derived.
* Discounts are applied in stored order to the running total. Item rows use `round(price cents × quantity units / quantity scale)` and percentage deductions use `round(running total × rate / 10000)`, both with half-up-away-from-zero behavior and checked integer intermediates. PHP uses bounded integer arithmetic and the TypeScript mirror uses `BigInt` intermediates; neither path relies on unsafe floating-point multiplication. Running additions/subtractions and discount magnitudes are checked against the same safe bound; no pricing total uses or returns a float, and the final total is clamped to zero.
* The line wire boundary remains `Number.MAX_SAFE_INTEGER`, but a contract whose authoritative final total would later be persisted is limited to the shared signed 32-bit money ceiling `PersistedMoney::MAX_CENTS = 2,147,483,647` cents. Store, update, open, close, and template-instance copy preflight this ceiling before any contract/accounting mutation; the Eloquent `Order` and `InvoiceSnapshot` write guards enforce the same bound as a last line of defense.
* The editor boundary remains `Number.MAX_SAFE_INTEGER`. Editor major-unit values accept at most two decimal places, validate that precision before conversion, and then use a bounded `Math.round(value * scale)` conversion. This deliberately repairs the unavoidable binary representation of max-safe cents (`fixedPointToMajorUnits(9007199254740991)`) so item, fixed/percentage discount, and manual-quantity roundtrips return the same wire integer. Wire normalization and all running-total arithmetic remain strict integer/`BigInt` operations; rounding is never used as a pricing arithmetic shortcut.
* Legacy mixed-placement template rows may be copied only when canonicalizing the separate `items`/`discounts` partitions preserves the original line-type order. A row that would move a discount before an item fails closed with a validation error before an instance or signer is inserted; the source template remains unchanged. Canonical and order-preserving legacy rows remain readable/copyable.
* `calculated_percentage` is derived display metadata in percentage points, never the basis-point wire rate: `1000` is emitted as integer `10`, while non-whole values such as `1001` use an exact decimal string (`10.01`). The PDF fragments format integer cents through `PersistedMoney::formatCents()` (integer grouping plus two exact decimals, never `/ 100` float division), keep the existing German decimal-comma typography, and append one `%`; the focused regression runs the real manual-invoice service, Blade view, and Dompdf output.
* `GET /api/contracts/sign/{personal_token}` returns the server-calculated `contract.total` in cents from the same method used when the contract closes. The signing view treats that field as authoritative.
* For a legacy response that predates `contract.total`, the frontend may calculate a compatibility total only from valid bounded integer contract lines. It does not trust a client-editable or stale `row_total`; current API responses remain server-authoritative.
* New management writes enforce the canonical partition: ordinary `type=item` lines belong in `items`, and ordered `discount_fixed`/`discount_percent` lines belong in `discounts`. Mixed placement is rejected before persistence. Legacy rows that already contain mixed placement are normalized on read; only this explicit compatibility path is accepted.
* Contract invoice/PDF snapshots serialize the same normalized, ordered line stream used for the response and close total, so derived `row_total` values cannot diverge from the authoritative amount.

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

### Idempotent closure and concurrency

* `ContractCloseService::close()` is the single lifecycle claim for manual and template-instance closure. It locks the contract row and performs the conditional `active` → `closed` update, accounting writes, and closure-mail enqueue in one database transaction. A retry or the loser of a concurrent close observes `closed` and performs no second order, invoice, or durable mail enqueue.
* Automatic closure of a template instance runs inside the same outer transaction as the final signature update. A close conflict therefore rolls back both the signature and the accounting claim; the public endpoint returns a generic retryable `409` without exposing database details.
* A contract-created invoice records the source UUID in the existing `invoice_snapshots.customer_details.contract_id` JSON field. This is the durable source identity for accounting reconciliation; the contract row remains the lifecycle/idempotency authority. It is not a new order or invoice column, so no V039+ migration is required for this closure behavior.
* With the production transactional database queue, the guarantee is at-most-once durable enqueue and accounting state, not exactly-once SMTP delivery. Queue-worker retries and terminal mail recovery remain separate operational concerns.

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
| `database/migrations/V039__enforce_contract_signer_identity.php` | Preflight/backfill, immutable scope snapshot, canonical unique index, driver-specific writer guards |
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
| `app/Services/ContractPricingService.php` | Canonical/legacy normalization and checked integer pricing engine |
| `app/Support/PersistedMoney.php` | Shared signed-32-bit persisted-money ceiling and exact cent formatting |
| `app/Services/ContractCloseService.php` | Close orchestration (invoice + PDF + mail) |
| `app/Exceptions/ContractCloseConflictException.php` | Generic retryable close conflict |
| `app/Mail/ContractClosedMail.php` | PDF email to all signers |
| `resources/views/pdf/contract_signatures.blade.php` | PDF template |
| `routes/api.php` | Route definitions |

### Frontend
| File | Purpose |
|------|---------|
| `src/ui/management/ManagementContractView.tsx` | Contract builder/manager |
| `src/logic/contractPricing.ts` | Safe-integer contract/manual pricing, fixed-point serialization, display metadata |
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
| `tests/Feature/Contract/ContractSignerIdentityTest.php` | Model identity, direct/template conflicts, raw-writer email/scope/relation guards, non-Unicode MariaDB collation, unique rollback |
| `tests/Feature/Contract/ContractSignerIdentityMigrationTest.php` | V039 schema, deterministic backfill, replay, bounded duplicate report |
| `tests/Feature/Contract/ContractSignerIdentityRaceTest.php` | Driver-gated real MariaDB/MySQL multi-connection race (skipped on SQLite) |
| `tests/Feature/Contract/ContractCloseTest.php` | Close → invoice → PDF → mail |
| `tests/Unit/ContractAuditServiceTest.php` | Audit log unit tests |
| `src/logic/__tests__/contractPricing.test.ts` | Safe-integer/fixed-point Vitest regressions |
| `src/logic/__tests__/useContractManagement.test.ts` | Vitest hook tests |
| `src/logic/__tests__/useContractJoin.test.ts` | Vitest hook tests |
| `tests/e2e/admin/contracts.spec.ts` | E2E: create → open → sign → close |
