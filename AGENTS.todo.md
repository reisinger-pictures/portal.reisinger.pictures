# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-01. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
> - Brand-Architektur (SOLL, Commit `1831116`): `features/infrastructure/21-brand-config-driven.md`
> - Pricing-Strategy-Pattern: `features/infrastructure/17-pricing-strategy-pattern.md`
> - Security-Risiken (C1–C4) & Stärken: `AGENTS.md` §7 / §8
> - Email-Template-Kompatibilität: gemeinsames Layout `emails/layouts/app.blade.php` + Button-Partial, 7 Templates auf 100% Kompatibilität, Env-Fix `FRONTEND_URL=4321` (Commit `…Email-Kompatibilität…`)
>
> Test-Regel (DoD): Backend-Änderungen → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## ✅ Session 2026-07-31 — nginx → Caddy-Direktauslieferung (Migration) — ABGESCHLOSSEN

Commit `e341216`. Caddy liefert `dist/` direkt via `import spa` + CSP/XFO (mit korrektem `sha256`-Hash fürs Inline-Script + `js.stripe.com`), nginx-Container entfernt. Alle Tasks + Doku-Fixes (K1, K2, M1/M2, I1, I2, I3) verifiziert. `SystemMiscTest` grün. Deploy-Sequenz (Server) siehe Commit-Message — außerhalb dieser Session.

## 🔙 Backlog (nicht in dieser Session)

| Task | Beschreibung | Grund |
|------|-------------|-------|
| **F3** | Admin-UI für Brand-Einstellungen (nur Settings für bestehende Brands, kein Full-CRUD). | Architekturfrage ungeklärt (Config-Write-Layer vs DB-Revert). Nicht für near future geplant. → `features/infrastructure/21-brand-config-driven.md` §Follow-up |

## 📊 Verification (2026-08-01)

| Suite | Result |
|-------|--------|
| PHPUnit | ✅ 999 passed (2423 assertions) — vorher 5 Stripe-Checkout-Tests fehlerhaft (echter Stripe-Call mit Platzhalter-Key im lokalen `.env`). Fix: `tests/Support/MocksStripeClient.php` (Trait, `ApiRequestor::setHttpClient`), eingesetzt in `OrderCheckoutTest` + `CheckoutCouponRevalidationTest`; Debug-`dump()` aus `OrderCheckoutTest` entfernt. |
| Vitest | ✅ 492 passed (50 files) |
| ESLint | ✅ `pnpm lint:fix --max-warnings 0` |
| Build (tsc+vite) | ✅ |
| Playwright `@smoke` | ⚠️ nicht ausführbar (Server offline: `ERR_CONNECTION_REFUSED localhost:4321`) — kein Code-induzierter Fehler |

## ✅ Session 2026-08-01 — Tracking-Integration (stats.reisinger.pictures) — ABGESCHLOSSEN

Tracker-Skript (`x7k2p.js`) war bereits in `index.html` eingebunden, aber ohne Custom-Events. Implementiert:

- `src/logic/tracking.ts` — typisierter Wrapper um `window.trackEvent` (Guard, Event-Name-Konstanten)
- `src/logic/usePageViewTracking.ts` + `PageViewTracker` in `App.tsx` — virtuelle Pageviews bei SPA-Route-Wechsel
- Foto-Interaktionen: `photo_view` (Lightbox-Slide via `usePhotoSwipe`, PhotoDetailView), `photo_swipe_open` (Lightbox-Open), `photo_download` (Zip-Download in `DeliveryView`, Einzel-Download + Admin-Download in `LicenseSelectorCard`)
- Warenkorb-Funnel: `add_to_cart` (LicenseSelectorCard + VolumeLicensingCard), `remove_from_cart` (`CartProvider`), `checkout_started`/`checkout_succeeded`/`checkout_failed` (`ClientCartView`)
- Tests: `tracking.test.ts`, `usePageViewTracking.test.tsx`

**Follow-up (gleiche Session):**
- Rating-Event `photo_rated` (photo_id, rating, has_comment) zentral in `useGallery.ratePhoto()` ergänzt — deckt Grid-Rating (`GridPhotoActions`), Lightbox-Bridge (`DaisyUIRatingBridge`) und Keyboard-Rating (`SelectionView`) ab.
- Integration/Component-Tests ergänzt: `useGallery.test.ts` (photo_rated ×2), `LicenseSelectorCard.test.tsx` (add_to_cart, photo_download via Admin-Download), `usePhotoSwipe.test.tsx` (photo_swipe_open, photo_view ×2, via gemocktem `PhotoSwipeLightbox` mit `vi.hoisted`).

**Offen/Note:** E2E `@smoke` lokal nicht lauffähig, weil Frontend-Dev-/Backend-Server nicht laufen. Events sind nur bei Nutzer-Interaktion sinnvoll messbar — vor Deployment `test:e2e` auf laufendem Stack ausführen.
