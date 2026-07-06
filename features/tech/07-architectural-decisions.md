# Architectural Decisions

Record of significant architectural decisions made during development.

| # | Decision | Date | Context |
|---|----------|------|---------|
| AD-1 | **Test-API-Keys dürfen in Git committed sein** (z.B. Stripe `pk_test_*` / `sk_test_*`, APP_KEY-Fallback). Testmode-only, kein Produktions-Risiko. | 2026-07-06 | Architekt-Freigabe nach WS-1 (S2). Fallback in `config/app.php` + `config/services.php` wiederhergestellt, da env-only den Auth-Break verursachte. |
| AD-2 | **V024 ist die neueste deployte Migration** (nicht V017 wie in AGENTS.md §3 impliziert). AGENTS.md muss korrigiert werden. | 2026-07-06 | Architekt-Korrektur. Konsolidierung ab V025. |
| AD-3 | **Magic Links Transient-Access**: Doku ist SOLL → Code wird nachimplementiert (keine Dummy-User mehr, JWT-Claims statt DB-User). | 2026-07-06 | Entscheidung zu D1. |
| AD-4 | **God-Klassen-Refactor A1/A2**: Voller Refactor beider Klassen in dieser Session. | 2026-07-06 | Entscheidung zu Architektur-Refactor. |
| AD-5 | **Playwright-Tag-Syntax**: AGENTS.md §2 wird auf `tag` (singular, Playwright-native) geändert; alle 100+ Spec-Tests migriert. | 2026-07-06 | Entscheidung zu C4. |
| AD-6 | **D2 Brand-Trennung RP/SRP**: `Brand::id()` (rp/srp) und `Brand::domain()` werden nachimplementiert. Architekt will klare Analyse was RP/SRP trennt vs gemeinsam hat (Super-Admin cross-brand, RP-Kalkulator auch bei SRP etc.). | 2026-07-06 | Entscheidung zu D2. |
| AD-7 | **D5 Ratings**: Eigenständiges Feature mit eigener UI + Lightroom-Sync → separate Doku in `features/`. | 2026-07-06 | Entscheidung zu D5. |
| AD-8 | **E3/E4 page.evaluate-Migration**: Eigener Sprint (API-Helper-Ausbau). | 2026-07-06 | Entscheidung zu E3/E4. |
