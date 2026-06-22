# 📝 Projekt-Master-Backlog

Aktuelle DB Version: 16

- [x] Verschiebe `CalculatorSettingsCard` in eine eigene Datei unter `frontend/src/ui/management/components/CalculatorSettingsCard.tsx`.
- [x] Refaktoriere `CalculatorSettingsCard` auf `react-hook-form` und integriere ein Zod-Validierungsschema (`calculatorSettingsSchema`), das die Backend-Regeln abbildet (`min(0)` für Preise, `int().min(1)` für Bilder).
- [x] Prüfe das TypeScript-Interface von `useLicenseTerms` und füge `calc_base_price` (vorzugsweise als optionalen String oder Number) hinzu.
- [x] Ergänze im HTML-Input für `imagesPerHour` das Attribut `step="1"`, um die Integer-Validierung des Backends visuell zu unterstützen.
- [x] Prüfe, ob für den neuen Datenbank-Key `calc_base_price` ein Standardwert über eine Datenbank-Migration oder einen Seeder initialisiert werden muss.

---

## 🧪 Test- & Qualitäts-Initiative

**Spec (SOLL-ZUSTAND):** [`features/tech/08-testing-initiative.md`](features/tech/08-testing-initiative.md) — dort liegen alle Paket-Details, Edge-Cases, Patterns & der Review-Workflow. **Testing-Regeln:** [`features/tech/05-testing-guidelines.md`](features/tech/05-testing-guidelines.md).

**Workflow pro Paket:** Spec lesen → mit günstigem Modell implementieren → mit teurem Modell reviewen → Fix-Loop. Tests erfassen das **aktuelle** Verhalten (auch Bugs → `// REVIEW`-Marker, nicht selbst „reparieren").

### P0 — Voraussetzung
- [ ] **BK-00** Shared Model Factories (Order, Tenant, InvoiceSnapshot, PayoutPool, PhotographerStatement, LicenseUseCase, LicenseModifier, DownloadLog, Setting, Product)

### P1 — Backend Unit-Tests (CRITICAL)
- [ ] **BK-01** User: Permission- & Zugriffslogik (`getAllowedGalleryIds`, `canAccessGallery`, `hasPurchasedPhoto`, …)
- [ ] **BK-02** Photo: `requiresWatermark`, `effective_*`, URLs, Filename
- [ ] **BK-03** Gallery + GalleryGroup: `effective_*`-Kaskade & `fullPath` (inkl. Zyklus-Edge-Case)
- [ ] **BK-04** PricingService (`calculateItemPriceCents`)
- [ ] **BK-05** PayoutCalculationService (`calculatePoolShares`, `calculatePowerUserDelta`, `finalizeStatements`)

### P2 — Backend (Services & Mocking)
- [ ] **BK-06** CheckoutService (Mailpit statt `Mail::fake()`; Stripe gemockt; IDOR)
- [ ] **BK-07** InvoiceService (Mailpit; Billing-Fallback)
- [ ] **BK-08** GalleryTreeService (Cache Hit/Miss; Permission-Filter)
- [ ] **BK-09** WatermarkService + PhotoProcessingService (ImageProcessor/Process mocken)
- [ ] **BK-10** Shooting Calculator: SettingsController-Validierung (Defaults vs. gespeichert; Auth)

### P2 — Frontend Pure-Logic Unit (Vitest, keine Komponenten-Tests)
- [ ] **FE-00** Vitest-Setup (nur `vitest`; `vitest.config.ts`; scripts)
- [ ] **FE-01** utils.ts (`formatMoney`, `formatDateToDE` [REVIEW-Bug], `flattenGroups`, `debounce`, `isEmpty`, `safeJsonParse`, …)
- [ ] **FE-02** usePricing → extrahiere `pricingLogic.ts` + Tests
- [ ] **FE-03** Cart → extrahiere `cartLogic.ts` (Zod + Pure) + Tests
- [ ] **FE-04** ShootingCalculator → extrahiere `shootingCalculator.ts` + Tests (REVIEW: `Infinity` bei `images_per_hour=0`)

### P3 — E2E-Edge-Cases (kein Mocking, UI-First)
- [ ] **E2E-01** Echte Edge-Case-Specs (Leer-Zustände, Validierung, IDOR-Grenzen, Accessibility, mobile)

**Fortschritt & Reviewer-Einträge:** siehe §9 in `features/tech/08-testing-initiative.md`.
