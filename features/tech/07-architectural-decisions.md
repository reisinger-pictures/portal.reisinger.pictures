# Architectural Decisions

Record of significant architectural decisions made during development.

| # | Decision | Date | Context |
|---|----------|------|---------|
| AD-1 | **Superseded:** Test-/Secret-Keys und App-Key-Fallbacks werden **nicht** committed; `AGENTS.md`/Security-Doku verlangen env-only Konfiguration und fail-closed Guards. | 2026-07-06 | Historische Entscheidung; durch C1–C3b und `features/security/env-hardening.md` abgelöst. |
| AD-2 | **Migrations-Stand — die einzige Stelle im Repository, die ihn nennt** (gemessen 2026-09-28). Repository und Produktion sind identisch: `ls backend/database/migrations/*.php \| wc -l` → **45**; `docker exec portal_backend php artisan migrate:status` → **45 `Ran`, 0 `Pending`**; höchste Nummer **V045** (`V045__calculator_money_fields_to_cents.php`). **Eine nicht-produktive Frontier existiert nicht mehr.** Die frühere Unterscheidung „zuletzt deployt" gegen „Frontier" ist gegenstandslos, und jede Zahl der Form „neue Änderungen ab V0xx" ist hiermit ein Dublett-Risiko gegen eine bereits deployte Migration. **Die nächste Migration ist V046.** V024 war der Stand der Ursprungsentscheidung. Weitere Migrationen nur bei unvermeidbarem Schema-Bedarf und mit vorab dokumentierter Schema-/Backfill-/Rollback-Entscheidung; eine deployte Migration wird nie geändert. **Alle anderen Dokumente verweisen auf diese Zeile und führen selbst keine Migrationsnummer** — eine kopierte Zahl veraltet stillschweigend, statt zu irren. | 2026-07-06, gemessen 2026-09-28 | Kanonisch. Jede andere Frontier-Angabe im Repo ist eine Kopie und taugt nicht als Beleg. |
| AD-3 | **Magic Links Transient-Access**: Doku ist SOLL → Code wird nachimplementiert (keine Dummy-User mehr, JWT-Claims statt DB-User). | 2026-07-06 | Entscheidung zu D1. |
| AD-4 | **God-Klassen-Refactor A1/A2**: Voller Refactor beider Klassen in dieser Session. | 2026-07-06 | Entscheidung zu Architektur-Refactor. |
| AD-5 | **Playwright-Tag-Syntax**: `AGENTS.md` §6 defines the singular `tag` option (Playwright-native); suite-wide migration is tracked separately. | 2026-07-06 | Entscheidung zu C4. |
| AD-6 | **Historical D2 proposal:** The former RP/SRP separation was superseded by the current RP-only static brand configuration. Any future brand is a reviewed config/enum/code change, not a migration-row or test-only brand override. | 2026-07-06 | Historical decision; current contract is `features/infrastructure/21-brand-config-driven.md`. |
| AD-7 | **D5 Ratings**: Eigenständiges Feature mit eigener UI + Lightroom-Sync → separate Doku in `features/`. | 2026-07-06 | Entscheidung zu D5. |
| AD-8 | **E3/E4 page.evaluate-Migration**: Eigener Sprint (API-Helper-Ausbau). | 2026-07-06 | Entscheidung zu E3/E4. |

> **AD-1 retraction (2026-09-24):** This historical decision is not permission
> to commit any real or private test credential, application key, encryption key,
> or environment-dependent fallback. Test-only values belong in untracked local
> environment files or CI secrets, or in scoped test code via `Config::set()` /
> HTTP fakes; official documented dummy credentials may be used only where the
> test explicitly identifies them as non-production values. Fixtures and mocks
> must never write credentials to source, logs, snapshots, or reports, and must
> never change production configuration.
