# Technical Concepts & Feature Documentation

This directory serves as the single source of truth for all technical concepts, architectural decisions, and feature specifications of the Reisinger Foto Portal.

## Directory Index

### 🤖 AI / Metadata Generation
* [01-ai-service-architecture.md](ai/01-ai-service-architecture.md) - 3-state machine, vision/text flows, provider strategy pattern.
* [02-testing-strategy.md](ai/02-testing-strategy.md) - Verification criteria (detailed test cases in AGENTS.todo.md).

### 🔐 Auth & Roles
* [01-roles-and-access.md](auth/01-roles-and-access.md) - Core RBAC, JWT, and Domain Mapping.
* [02-magic-links.md](auth/02-magic-links.md) - Transient access and anonymous invites.
* [03-roles-and-rbac.md](auth/03-roles-and-rbac.md) - Enterprise Role Model (B2B).
* [04-permissions-hook.md](auth/04-permissions-hook.md) - Frontend permissions hook separation.

### 📦 Delivery & Downloads
* [01-downloads-and-injection.md](delivery/01-downloads-and-injection.md) - ExifTool injection and watermarking.
* [02-audit-logs.md](delivery/02-audit-logs.md) - GDPR-compliant download tracking.
* [03-file-delivery-controller.md](delivery/03-file-delivery-controller.md) - Auth-gated file delivery and audit behavior.

### 🛒 E-Commerce
* [02-licensing-and-downloads.md](ecommerce/02-licensing-and-downloads.md) - Current licensing/cart and delivery contract; the former standalone licensing-and-cart document is deprecated and not retained.
* [03-custom-quotes-and-stripe.md](ecommerce/03-custom-quotes-and-stripe.md) - Custom Quote Links and Stripe Payments.
* [04-crm-and-contracts.md](ecommerce/04-crm-and-contracts.md) - CRM, Text Snippets and PDF Contracts.
* [05-manual-invoices.md](ecommerce/05-manual-invoices.md) - Stateless PDF generation for B2B.
* [06-legal-evidence-and-disputes.md](ecommerce/06-legal-evidence-and-disputes.md) - Dispute protection and access locking.
* [07-psychological-pricing.md](ecommerce/07-psychological-pricing.md) - Psychological price rounding (intentionally inexact discounts) — desired-behavior invariant.
* [08-srp-coupon-system.md](ecommerce/08-srp-coupon-system.md) - SRP coupon/discount code system, schema, API, role-based permissions.

### 🖼️ Gallery Management
* [01-core-architecture.md](gallery/01-core-architecture.md) - Selection vs. Delivery workflows.
* [02-ratings-feature.md](gallery/02-ratings-feature.md) - Photo ratings (stars + comments) in selection galleries.

### 🧑💼 CRM
* [04-birthdate-age-verification.md](crm/04-birthdate-age-verification.md) - Birthdate & Age Verification (CRM), live age calculation.
* [05-model-registration.md](crm/05-model-registration.md) - **Current SOLL:** Magic-Link-Registrierung, Single-Katalog v1, verschlüsselte Altersnachweise/Personen-Fotos, Act- und Owner-Zugriff.
* [06-model-profile-iteration.md](crm/06-model-profile-iteration.md) - **Current SOLL:** Single-Katalog v1 (Bereitschaft/Erfahrung, ohne Erotik), Lifecycle 13+2, Owner-/Admin-Foto- und Zugriffsverträge, verschlüsselte Ablage.

### ⚙️ Infrastructure
* [01-deployment.md](infrastructure/01-deployment.md) - Docker, Portainer, and Reverse Proxy.
* [02-email-system.md](infrastructure/02-email-system.md) - Mailpit, Custom Mails, and Opt-ins.
* [03-accounting-and-lifecycle.md](infrastructure/03-accounting-and-lifecycle.md) - Invoicing, PDFs, and storage cleanup.
* [04-payout-system.md](infrastructure/04-payout-system.md) - Weighted share pool model, deduplication and statements.
* [05-watermark-refactoring.md](infrastructure/05-watermark-refactoring.md) - Auto-generation of watermark PNGs and SVG rasterization.
* **Brand System** (Lesereihenfolge: 08 → 06 → 12 → 15 → 10 → 09 → 20):
  * [08-org-brand-concept.md](infrastructure/08-org-brand-concept.md) - Org vs. Brand: Begriffsklärung.
  * [06-multi-domain-branding.md](infrastructure/06-multi-domain-branding.md) - Hostname-Erkennung, Tailwind-Theming, Assets pro Domain.
  * [12-brand-registry-and-settings-fixes.md](infrastructure/12-brand-registry-and-settings-fixes.md) - `BrandRegistry`-API: `fromHost()`, `current()`, `currentOrDefault()`.
  * [15-strict-user-brand-isolation.md](infrastructure/15-strict-user-brand-isolation.md) - Login-Enforcement: Brand-Mismatch-Rejection, Staff-Brand-Binding (U-01, U-02).
  * [10-frontend-brand-tenant-isolation.md](infrastructure/10-frontend-brand-tenant-isolation.md) - Frontend: `useBrand`-Hook, Sidebar-Filterung, Route-Guards.
  * [09-brand-context-queue-cli.md](infrastructure/09-brand-context-queue-cli.md) - Brand-Kontext in Queue-Jobs und CLI-Commands.
  * [20-setting-resolver.md](infrastructure/20-setting-resolver.md) - Brand-gescopte Settings (Fallback-Chain).
  * [21-brand-config-driven.md](infrastructure/21-brand-config-driven.md) - **SOLL:** Statisches `config/brands.php` statt DB-Tabelle, SRP entfernt (historischer Implementierungsverweis; siehe die Projektdokumentation).
  * [22-brand-settings-overlay.md](infrastructure/22-brand-settings-overlay.md) - **SOLL:** F3 Brand-Settings-Overlay (DB-Overlay auf `settings`, Whitelist, Merge-Precedence, API-Vertrag).
* [07-lightroom-multi-tenant-gap.md](infrastructure/07-lightroom-multi-tenant-gap.md) - Lightroom plugin single-Org gap analysis.
* [11-brand-settings-separation.md](infrastructure/11-brand-settings-separation.md) - Symmetric brand-prefixed settings resolver.
* [13-ftp-brand-isolation.md](infrastructure/13-ftp-brand-isolation.md) - FTP upload brand isolation and defense-in-depth.
* [14-per-brand-catalog.md](infrastructure/14-per-brand-catalog.md) - Per-brand catalog, CRM, and settings isolation.
* [16-srp-volume-pricing.md](infrastructure/16-srp-volume-pricing.md) - **Historical:** altes SRP-Volumen-Preismodell; aktuelle Presets und generische Strategie siehe 17/27.
* [17-pricing-strategy-pattern.md](infrastructure/17-pricing-strategy-pattern.md) - **Current SOLL:** Scope-/Volume-Strategie, Brand-Default plus Gallery-Override, Presets und Mixed-Cart-Aufteilung.
* [27-volume-licensing-presets.md](infrastructure/27-volume-licensing-presets.md) - **Current SOLL:** V029-basierte Presets, Gallery-Zuordnung und Admin/API; V038 bleibt die aktuelle Repository-Migrations-Frontier.
* [18-jwt-offer-tokens.md](infrastructure/18-jwt-offer-tokens.md) - JWT-based machine-readable offer tokens.
* [19-ftp-upload-pipeline.md](infrastructure/19-ftp-upload-pipeline.md) - FTP upload pipeline and storage mapping.
* [21-brand-config-driven.md](infrastructure/21-brand-config-driven.md) - Static, code-reviewed brand configuration.
* [22-brand-settings-overlay.md](infrastructure/22-brand-settings-overlay.md) - Database overlay for the brand-settings whitelist.
* [25-brand-separation-matrix.md](infrastructure/25-brand-separation-matrix.md) - Brand/Org separation decisions and boundaries.
* [28-ci-test-image.md](infrastructure/28-ci-test-image.md) - **E2E-Test-Image `portal-e2e`:** portal-base + Node/pnpm/Composer + Playwright-Chromium vorinstalliert; CI-E2E läuft komplett im Container.
* [29-production-operations-runbook.md](infrastructure/29-production-operations-runbook.md) - Production queue, SMTP, worker supervision, scheduler, and recovery contract.

### 📷 Photos & Metadata
* [01-upload-and-processing.md](photos/01-upload-and-processing.md) - Lightroom UUIDs and ImageProcessor.
* [02-metadata-versioning.md](photos/02-metadata-versioning.md) - Client edits and snapshot reverting.
* [03-ai-batch-edit.md](photos/03-ai-batch-edit.md) - **Historical:** früherer reiner LM-Studio-Batch-Flow; superseded by 04.
* [04-ai-server-side.md](photos/04-ai-server-side.md) - **Current SOLL:** serverseitige AI-Metadaten, Boolean-/Providerstatus und selektiver LM-Studio-Fallback.
* [05-photo-detail-swipe.md](photos/05-photo-detail-swipe.md) - Photo detail view, PhotoSwipe lightbox, and responsive image loading.

### 🔍 Search & Discovery
* [01-search-and-discovery.md](search/01-search-and-discovery.md) - Meilisearch integration.
* [02-smart-assistance.md](search/02-smart-assistance.md) - GeoNames autocomplete.
* [03-meilisearch-typo-tolerance.md](search/03-meilisearch-typo-tolerance.md) - Typo-tolerance configuration and sync.

### 📄 Documents & PDF
* [01-pdf-typography.md](documents/pdf-typography.md) - dompdf engine decision (orphans enforced / widows FIXME, Engine-Vergleich dompdf vs. mPDF vs. phppdf vs. Chromium) + CSS-Typografie-Vertrag (orphans/widows/page-break-inside).

### 💻 Tech & Architecture
* [01-database-schema.md](tech/01-database-schema.md) - UUIDs and migration strategy.
* [02-backend-architecture.md](tech/02-backend-architecture.md) - Stateless API and ZIP streaming.
* [03-frontend-architecture.md](tech/03-frontend-architecture.md) - React, Vite, SWR, and UI rules.
* [04-testing-guidelines.md](tech/04-testing-guidelines.md) - Strict UI-first testing rules.
* [05-security-and-perf-refinement.md](tech/05-security-and-perf-refinement.md) - Security and performance hardening.
* [06-i18n-internationalization.md](tech/06-i18n-internationalization.md) - Internationalization policy and Lingui integration.
* [07-architectural-decisions.md](tech/07-architectural-decisions.md) - Historical and current architecture decisions.

### 🧪 E2E Test Strategy
* [e2e-test-strategy.md](e2e-test-strategy.md) - Playwright topology, functional-tag policy, isolation rules, and CI shard selection.
