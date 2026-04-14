# Technical Concepts & Feature Documentation

This directory serves as the single source of truth for all technical concepts, architectural decisions, and feature specifications of the Reisinger Foto Portal.

## Directory Index

### 🔐 Auth & Roles
* [01-roles-and-access.md](auth/01-roles-and-access.md) - Core RBAC, JWT, and Domain Mapping.
* [02-magic-links.md](auth/02-magic-links.md) - Transient access and anonymous invites.
* [03-roles-and-rbac.md](auth/03-roles-and-rbac.md) - Enterprise Role Model (B2B).

### 📦 Delivery & Downloads
* [01-downloads-and-injection.md](delivery/01-downloads-and-injection.md) - ExifTool injection and watermarking.
* [02-audit-logs.md](delivery/02-audit-logs.md) - GDPR-compliant download tracking.

### 🛒 E-Commerce
* [01-licensing-and-cart.md](ecommerce/01-licensing-and-cart.md) - Dynamic pricing, upgrades, and delta-pricing.
* [02-licensing-and-downloads.md](ecommerce/02-licensing-and-downloads.md) - ZIP downloads and UI refactoring.
* [03-custom-quotes-and-stripe.md](ecommerce/03-custom-quotes-and-stripe.md) - Custom Quote Links and Stripe Payments.
* [04-crm-and-contracts.md](ecommerce/04-crm-and-contracts.md) - CRM, Text Snippets and PDF Contracts.
* [05-manual-invoices.md](ecommerce/05-manual-invoices.md) - Stateless PDF generation for B2B.
* [06-legal-evidence-and-disputes.md](ecommerce/06-legal-evidence-and-disputes.md) - Dispute protection and access locking.

### 🖼️ Gallery Management
* [01-core-architecture.md](gallery/01-core-architecture.md) - Selection vs. Delivery workflows.

### ⚙️ Infrastructure
* [01-deployment.md](infrastructure/01-deployment.md) - Docker, Portainer, and Reverse Proxy.
* [02-email-system.md](infrastructure/02-email-system.md) - Mailpit, Custom Mails, and Opt-ins.
* [03-accounting-and-lifecycle.md](infrastructure/03-accounting-and-lifecycle.md) - Invoicing, PDFs, and storage cleanup.

### 📷 Photos & Metadata
* [01-upload-and-processing.md](photos/01-upload-and-processing.md) - Lightroom UUIDs and ImageProcessor.
* [02-metadata-versioning.md](photos/02-metadata-versioning.md) - Client edits and snapshot reverting.

### 🔍 Search & Discovery
* [01-search-and-discovery.md](search/01-search-and-discovery.md) - Meilisearch integration.
* [02-smart-assistance.md](search/02-smart-assistance.md) - GeoNames autocomplete.

### 💻 Tech & Architecture
* [01-database-schema.md](tech/01-database-schema.md) - UUIDs and migration strategy.
* [03-backend-architecture.md](tech/03-backend-architecture.md) - Stateless API and ZIP streaming.
* [04-frontend-architecture.md](tech/04-frontend-architecture.md) - React, Vite, SWR, and UI rules.
* [05-testing-guidelines.md](tech/05-testing-guidelines.md) - Strict UI-first testing rules.
* [06-post-mortem-gallery-bugs.md](tech/06-post-mortem-gallery-bugs.md) - Bug tracking and prevention.
