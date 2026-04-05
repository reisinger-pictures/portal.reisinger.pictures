---
domain: infrastructure
topic: accounting-and-lifecycle
status: planned
---

# Technical Concept: Accounting, Storage & Lifecycle

## 1. Accounting (AT 2025 Standard)
- **Phase 1 (MVP):** Strictly limited to domestic customers in Austria. No international reverse-charge logic required.
- **On-the-fly PDFs:** Documents are generated dynamically using Laravel Blade templates. PDFs are NEVER stored physically on the server.
- **Number Sequencing:** A centralized, autonomous number range for the shop (`P-YYYY-NNNN`).
- **Collective Invoices (Sammelrechnung):** Purchases initially generate a "Delivery Note" (Lieferschein) as an order line item. The actual invoice number is only assigned when the collective invoice is generated at the end of the billing period.
- **Payment Terms:** Default is 14 days, configurable per user (e.g., 30 days). Manual status tracking (Open, Overdue, Paid).

## 2. Email Automation
- Asynchronous dispatch of documents using Laravel Queues to handle API rate limits.
- **Mandatory BCC:** Every system-generated accounting email MUST include a blind copy routing to the internal accounting mailbox.

## 3. Cache & Storage Optimization
- **Hit-Registry:** Instead of relying on file system timestamps, image views log a "hit" in the database. To reduce DB load, the `last_accessed` timestamp is only updated a maximum of once per 24 hours per asset. WebP derivatives without a hit for 14 days are physically deleted.
- **Master-File Downscale:** A CRON job sweeps editorial images older than 7 days. The image is downscaled to 2560px, and the original master file is permanently deleted to save storage.
