# Task Board — Portal Reisinger Pictures

## Session: Epic Digital Contracts & Signatures (Multi-Signer)

#### P0 — Backend: DB Schema & Models
- Create migration for `contracts`, `contract_signers`, and `contract_audit_logs` tables.
- Create eloquent models with relationships (Contract hasMany Signers, Signer hasMany AuditLogs).
- **Tests:** PHPUnit tests for model creation, JSON casting (roles), and cascade deletions.

#### P0 — Backend: API & Audit Trail Logic
- Create management endpoints: POST (create), GET (list), PUT (update draft), POST (open period), POST (close period).
- Create public endpoints for token-based access:
  - GET `/api/contracts/join/{token}` (Fetch active contract metadata & available roles)
  - POST `/api/contracts/join/{token}` (User joins, selects role, gets personal session)
  - POST `/api/contracts/sign/{personal_token}` (Submit clickwrap signature)
- Implement `ContractAuditService` to securely log IP and User-Agent per signer.
- **Tests:** PHPUnit tests for token validation, role constraints (allow_multiple_roles), and period state transitions (draft -> active -> closed).

#### P1 — Frontend: Contract Builder UI (Management)
- Clone/adapt `ManagementManualInvoiceView` into `ManagementContractView`.
- Retain Pricing Logic (Items, Discounts).
- Extract Billing Details into a separate "Rechnungsempfänger (Optional)" block.
- Add UI to define `available_roles` (tags input) and toggle `allow_multiple_roles_per_signer`.
- Add "Vertragsperiode starten" action (generates/shows the generic Join-Link and allows sending direct invites).
- **Tests:** Playwright E2E for creating a contract with custom roles and opening it.

#### P1 — Frontend: Client Signing UI
- Create public `ContractJoinView` (for the generic link): Form for Name, Email, and Role selection.
- Create public `ContractSignView` (accessible via personal token after joining or direct invite).
- Implement Read-Only view of the HTML content, terms, and pricing items.
- Implement heartbeat mechanism to log active viewing time per signer.
- Implement Clickwrap agreement: Mandatory checkbox + legally compliant submit button ("Zahlungspflichtig abschließen" vs. "Vertrag verbindlich abschließen").
- **Tests:** Playwright E2E mimicking two different clients joining the same contract link, picking roles, and signing.

#### P2 — Backend: Final PDF Generation & Auto-Invoicing
- On `closed` event: Trigger Auto-Invoicing if `total_amount > 0` (using the `billing_details`).
- Create Blade template for the contract + Multi-Signer Block.
- Append Multi-Signer Audit Trail (Digital Certificate showing IPs and timestamps for all participants).
- Embed `%OFFER_JWT:{token}%` into the final PDF.
- Dispatch `ContractClosedMail` with the immutable PDF attachment to ALL signers and the photographer.
- **Tests:** PHPUnit tests for the `close` trigger, PDF multi-signer compilation, and mass-email dispatch.
