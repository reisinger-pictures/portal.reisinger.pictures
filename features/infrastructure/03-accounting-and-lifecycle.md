---
domain: infrastructure
topic: accounting-and-lifecycle
status: active
---

# Technical Concept: Accounting, Storage & Lifecycle

## 1. Accounting (AT 2025 Standard)
- **Phase 1 (MVP):** Strictly limited to domestic customers in Austria. No international reverse-charge logic required.
- **On-the-fly PDFs:** Documents are generated dynamically using Laravel Blade and `barryvdh/laravel-dompdf`. PDFs are NEVER stored physically on the server.
- **Number Sequencing:** A centralized, autonomous number range for the shop (`P-YYYY-NNNN`). Protected via pessimistic database locking (`lockForUpdate`).
- **Payment Terms & Status:** Default payment term is 14 days. Orders have a trackable payment status (`open`, `overdue`, `paid`). Dunning (Mahnwesen) is handled manually by the admin based on these statuses.

## 1b. Tax Handling (Kleinunternehmerregelung)
- **tax_rate = null:** All `invoice_snapshots` store `tax_rate` as `null` (nullable decimal column). A null value means "no VAT applicable."
- **No VAT display:** When `tax_rate` is null, PDF invoices show no tax line. The Kleinunternehmer legal notice is rendered in the PDF footer template.
- **All prices are net:** `total_net` and `total_gross` are identical when `tax_rate` is null. The system does not compute or display VAT amounts.
- **Scope:** Applies to all invoice types — shop orders (CheckoutService), manual invoices (InvoiceService), and contract invoices (ContractCloseService).

## 2. Collective Invoices (Sammelrechnung) vs. Direct Invoice
- **Direct Invoice:** Standard users immediately receive an invoice with a generated number upon checkout.
- **Collective Invoice (Future Scope):** Purchases by specific B2B tenants initially generate a "Delivery Note" (Lieferschein) as an order line item without a fiscal invoice number. The actual invoice number is only assigned when the collective invoice is generated at the end of the billing period (month/quarter).

### 2.1 Purchase-time organization attribution

- A checkout for a user assigned to an organization writes the organization
  UUID to `invoice_snapshots.customer_details.org_id` in the same transaction as the
  order and snapshot. The existing JSON column is the snapshot contract; no
  `orders` or `invoice_snapshots` schema migration is required.
- Collective invoice generation treats a present `org_id` value as immutable
  purchase-time attribution. A later `users.org_id` reassignment cannot move
  that order to the new organization.
- Legacy snapshots without the `org_id` key retain the compatibility fallback
  to the user's current organization membership. A present but null or invalid
  key fails closed and is never treated as a legacy row.
- A delivery note without an `InvoiceSnapshot` is malformed accounting data and
  fails closed; it is not a legacy fallback row.
- The organization summary count and the generation path use the same
  attribution predicate, so a historical order remains visible to its
  purchase-time organization even after all of its users have moved.
- `InvoiceSnapshot::updating` is the application-level immutability boundary:
  once `customer_details.org_id` is present, Eloquent `save()`/`update()` may
  neither replace nor remove it (including a null/invalid value). A legacy
  snapshot without the key may receive a one-time backfill.
- This is **not** a claim of database-level immutability. Raw Query Builder/DB
  facade writes, bulk Eloquent updates, and event-disabled maintenance writes
  bypass model events; they are trusted maintenance escape hatches and are not
  supported business-logic paths. Standard application writes that change
  `customer_details` use per-model Eloquent `save()`/`update()` so the model
  guard is effective.
- Candidate selection deliberately avoids JSON extraction in SQL. It loads
  snapshot-bearing delivery notes and evaluates the attribution predicate in
  PHP, so malformed JSON is filtered closed instead of aborting a SQLite query.

## 3. Email Automation
- Asynchronous dispatch of documents using Laravel Queues to handle Google Mail API rate limits (max 500-2000/day).
- **Mandatory BCC:** Every system-generated accounting email MUST include a blind copy routing to the internal accounting mailbox.

## 4. Cache & Storage Optimization
- **Hit-Registry:** Instead of relying on file system timestamps, image views log a "hit" in the database. To reduce DB load, the `last_accessed_at` timestamp is updated a maximum of once per 24 hours per asset. WebP derivatives without a hit for 14 days are physically deleted.
- **Master-File Downscale:** A CRON job sweeps editorial images older than 7 days. The image is downscaled to 2560px, and the original master file is permanently deleted to save storage.

## 5. Multi-Page PDF Styling (Sammelrechnungen)
- **Problem:** Bei B2B-Sammelrechnungen können viele Positionen anfallen, wodurch die Tabelle über mehrere Seiten umbricht.
- **Lösung:** Das PDF-Template (`invoice.blade.php`) nutzt strikte CSS-Regeln für die DomPDF-Engine:
  - `table.items { page-break-inside: auto; }` und `tr { page-break-inside: avoid; }` verhindern, dass einzelne Rechnungsposten in der Mitte zerschnitten werden.
  - `thead { display: table-header-group; }` zwingt DomPDF dazu, den Tabellenkopf (Titel der Spalten) auf jeder neuen Seite automatisch zu wiederholen.
  - Zusätzlich wird in Sammelrechnungen pro Posten der Name des ursprünglichen Bestellers (`ordered_by`) ausgewiesen, um die interne Zuordnung für den Kunden zu erleichtern.
