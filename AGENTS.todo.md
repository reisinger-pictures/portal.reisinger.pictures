# Task Board — Portal Reisinger Pictures

## Architecture & Security Review — 2026-10-18

**Execution hold:** This section records review findings only. Remediation, new tests,
configuration changes, commits, pushes and deployment require a subsequent explicit
instruction; do not start them from these TODOs.

**Review baseline:** `ef48716b4da69107c2cc4989b976cad12fac8fd4`
(`git rev-parse HEAD` at review start), plus the then-existing uncommitted changes.
At the final documentation check, `git rev-parse HEAD` was
`e1ed87aa7b5df9a6db5f793193bf77cce905997b`; `git status --short --branch` showed only
this `AGENTS.todo.md` edit. `git diff --name-only ef48716b4da69107c2cc4989b976cad12fac8fd4..HEAD`
showed intervening CI, docs and frontend build-gate changes, but no backend application,
route or bootstrap files behind these findings. This is a source review, not an
attestation of the production deployment. The pre-existing owner changes were left
untouched.

**Method:** Independent GPT-6.1 Sol (`openai/gpt-6.1-sol#high`) subagents inspect
authentication, object/tenant authorization, uploads/infrastructure, browser/payment
integrity and architectural trust boundaries. Findings must identify an entry point,
attacker prerequisites, the insufficient boundary, impact and a future regression
test. A final documentation consistency review used GPT-6 Luna (`openai/gpt-6-luna#xhigh`).
Static evidence is not labeled as a successful runtime exploit. Previously accepted
owner decisions in `features/owner-decisions.md` remain in force.

**Priority for this review's `SEC-20261018-*` IDs:** P0 = critical compromise requiring
immediate containment; P1 = high-impact actionable security or financial-integrity
defect; P2 = bounded/conditional security, reliability or financial-integrity defect;
P3 = hardening/architectural follow-up. These labels do not redefine the priority
conventions used by historical audit sections below.

### Architecture and attacker model

| Boundary | Assets and enforcement points |
|---|---|
| Internet/browser → application | React SPA and same-origin API behind Caddy; `backend/routes/api.php`, `backend/bootstrap/app.php`, brand context, JWT cookies and role middleware. CORS/browser behavior is not a replacement for server authorization. |
| User/guest → gallery, organization and CRM objects | `AuthorizationService`, policies and scoped queries decide actual object access; `ManagementMiddleware` is only the coarse management-entry gate. Cross-brand access by a null-brand super-admin is intentional. |
| Private media → browser/download | `FileDeliveryController` and `PhotoDownloadController` implement separate delivery paths. Visibility, gallery access, watermark policy, resolution entitlement and download auditing must agree across both paths. |
| Camera/browser/Lightroom → processing | SFTPGo inbox and management uploads feed image parsing, ExifTool and private storage. Parser/resource budgets and restricted filesystem authority are separate from validating the uploader's role. |
| Bearer links → model/contract data | Registration/profile links and contract personal tokens are independent capabilities, not ordinary account sessions. Contract deadlines/content versions and encrypted model-file storage protect different properties. Possession of a legitimately delegated bearer link is not itself a vulnerability. |
| Backend → external services and background work | Stripe signatures protect webhook ingress; SMTP/queue durability, AI-provider disclosure and SFTPGo administration are distinct boundaries. Web requests, scheduler and workers share a backend runtime and sensitive service credentials. |

Relevant attacker positions are an anonymous internet client, an ordinary registered
customer, a gallery guest/link holder, a contract participant, a photographer with
upload rights, and an actor controlling a same-site sibling origin. These prerequisites
are not interchangeable: a participant-only availability issue is not anonymous
account takeover, and compromise of the backend runtime is not demonstrated merely
because that runtime holds powerful credentials.

**Coverage limits:** No live host, Stripe account, Caddy configuration or public service
was probed; no exploit payload was sent to production. No application test suite was
run for this documentation-only review. Production exposure, effective proxy limits,
backup/key isolation and browser cache behavior require separate evidence. The
current source review is not a penetration-test completeness or absence-of-bugs claim.

### Prioritized findings — source review only

**Alle 14 Befunde dieser Prüfung sind umgesetzt und unabhängig verifiziert.** Sie stehen als
Zuordnung Befund → Commit → Test in
[`features/security/2026-10-18-review-closure.md`](features/security/2026-10-18-review-closure.md)
und sind deshalb hier entfernt (Board-Regel: geschlossene Einträge wandern ins Git bzw. nach
`features/`, nicht in eine Liste, die bei der nächsten Bereinigung verschwindet).
P0: none established by this source review. Priority is based on reachable impact and
the stated prerequisites, not on a confirmed production exploit.

---

### Neue offene Punkte aus den Wellen A–D und ihren Nachprüfungen (2026-10-18)

Diese Punkte sind in den Freigaben als **nicht-blockierend** eingestuft worden. Sie sind hier,
weil eine Freigabe sie sonst mitnimmt: keine ist erledigt, jede hat einen benannten Ort.


---
- [ ] **Dependabot: 1 offener Alert am Default-Branch** (war 10) — **gemessen am 2026-10-21:
  genau 1 Alert, `high`, `braces`** (verwundbar `<= 3.0.3`, **`gepatcht-ab=KEINE`**), 0 critical.
  Die **Erreichbarkeit ist geprüft** (`pnpm why braces`): `braces` kommt ausschließlich über
  `micromatch` ← `@lingui/cli` / `eslint-plugin-lingui`, also **nur über die Build- und
  Lint-Werkzeuge**; das ausgelieferte Bundle enthält es nicht, und der ReDoS-Pfad bräuchte
  ein vom Angreifer kontrolliertes Glob-Pattern, das es hier nicht gibt. **Die Erreichbarkeit
  ist auf die Bauzeit begrenzt, die Abhängigkeits-Klassifikation nicht:** der Pfad laeuft ueber
  `@lingui/vite-plugin`, das in `dependencies` steht — `pnpm audit --prod` zaehlt den Alert, obwohl
  zur Request-Zeit nichts davon laeuft (Praezisierung in `features/security/accepted-risks.md`,
  C8). **Der Alert bleibt
  in GitHub offen** — ohne verfügbare Version gibt es nichts zu aktualisieren, und ein
  Dismissal wäre eine Sicherheitsentscheidung des Owners. **Der Weg dorthin ist ein Schritt** (der Alert
  ist GHSA-vfj7-8cjw-p6xm, `npm:braces`, `frontend/pnpm-lock.yaml`):
  `gh api -X PATCH repos/reisinger-pictures/portal.reisinger.pictures/dependabot/alerts/<alert-id> -f state=dismissed -f dismissed_reason=tolerable_risk -f dismissed_comment="C8: accepted in features/security/accepted-risks.md — no patched version exists (3.0.3 is the highest)."`.
  **Der Alert bleibt bis dahin offen** — das Repo entscheidet die Grenze (C8), GitHub bekommt nur den
  Vermerk. **Auslöser für eine Neubewertung:** eine gepatchte Version erscheint (dann gehört der Bump in
  die nächste `node-deps`-Welle) oder die Abhängigkeit wandert in den Request-Pfad (dann verlässt sie C8).
  **Erscheint eine gepatchte
  Version, gehört sie in die nächste `node-deps`-Welle**, nicht nebenbei. Die übrigen
  Advisory-Gruppen (`undici`, `source-map-js`, `brace-expansion`,
  `postcss-selector-parser`) sind in den Wellen unten erledigt.
   - **Ergebnis der `node-deps`-Wellen (`85f102b` + die `undici`-Kopplung):** drei Pakete sind über `overrides` in `frontend/pnpm-workspace.yaml` gehoben (`source-map-js` 1.2.1 → 1.2.2, `brace-expansion` 5.0.9 → 5.0.12, `postcss-selector-parser` 6.0.10 → 7.1.6) — alle **transitiv** und dev/build-only (kein Treffer über alle 40 JS-Chunks, `pnpm why` weist jeden Pfad). `undici` brauchte am Ende **keinen** Override: die Kopplung wurde **gelöst** statt erzwungen — `jsdom@30.0.1` deklariert `^8.9.0` und löst selbst auf `8.12.2` auf, und **alle sechs** offenen `undici`-Advisories enden bei `< 7.29.1` (Range per API gemessen), 8.x liegt also außerhalb; der zwischenzeitliche erzwungene Downgrade auf 7.29.1 ist damit weg. `pnpm audit` 16 → 1, `pnpm audit --prod` 2 → 1, Vitest grün (1423; die 20 zusätzlichen Tests stammen aus der pk_test-Gate-Welle). **`braces` bleibt offen und ist als akzeptiertes Risiko dokumentiert (C8, `features/security/accepted-risks.md`)** — kein Patch existiert, und der verwundbare Eingang wird von keiner der drei realen Aufrufstellen betreten. Beide Wellen unabhängig verifiziert (APPROVED; u. a. minimal-invasiver Lockfile-Diff, CSS byte-identisch trotz Major-Bump, JSDOM-Konstruktionsprobe unter 8.12.2). **Scan-Lag, kein Befund:** die Dependabot-API meldet weiter **10** Alerts, weil ihr letzter Scan vor `85f102b` lief; autoritativ für den lokalen Stand ist `pnpm audit` → **1**.
  - **Verlauf:** 17 → 15 (dompurify, PR #18) → 12 (`league/commonmark` ×2 + `laravel/framework`, PR #17/#16) → **10** (`tinypool` ×2 critical, PR #19) — alle vier über den Auto-Merge, **ohne Handarbeit**. **Der rote-PR-Blocker ist behoben (D-33):** die Security-PRs waren nie wegen ihres Codes rot, sondern weil GitHub die **Actions**-Secrets von jedem Dependabot-Lauf zurückhält — er liest ausschließlich den **Dependabot**-Store (für `push` **und** `pull_request`; nur `pull_request_target` erhält keine). Der Push-Lauf von PR #18 ist nach `@dependabot rebase` **11/11 grün** (`39130666484`) und wurde von `automerge.yml` gemergt (13:29:48Z, Merge-Kommit `e2d280b`).
  - **Der manuelle Rest — Owner-Entscheidung 2026-10-18: eigene `node-deps`-Welle, nicht nebenbei — ausgeführt, Ergebnis im Unterpunkt darüber.** `undici` (6 Alerts), `source-map-js`, `brace-expansion`, `postcss-selector-parser`, `braces`. Für `source-map-js` hat Dependabot seinen PR #20 am 2026-10-18 selbst geschlossen („Looks like source-map-js is no longer updatable, so this is no longer needed"), obwohl der Alert **offen** ist und `frontend/pnpm-lock.yaml:2701` → `source-map-js@1.2.1:` den verwundbaren Stand weiter pinnt. **Sonderfall `braces`:** `first_patched_version` ist leer — es gibt **keine** gefixte Version, der Fall braucht Entfernen/Override statt Bump. Alle übrigen sind Ein-Patch-Sprünge (`undici` 7.29.0 → 7.29.1, `source-map-js` 1.2.1 → 1.2.2, `brace-expansion` → 5.0.12, `postcss-selector-parser` → 7.1.6), aber **transitiv** — deshalb erst Triage (dev-only vs. Produktion), dann `pnpm.overrides`.

> Stand: 2026-09-27. Architekturentscheidungen in `features/`.
>
> Test-Regel (DoD): Backend → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.
>
> **Struktur-Hinweis (Grundsatz, 2026-09-28; die Zählung unten ist der aktuelle Stand):**
> Dieses Board enthält **nur offene Arbeit** — erledigte Einträge werden entfernt, nicht
> abgehakt (`AGENTS.md` §4 Board-Hygiene). Jede offene Position trägt einen der drei
> Gründe, warum sie noch steht: **`manuell prüfen:`** (der Owner sieht es sich selbst
> an — nach einem Deploy, an einem echten Gerät oder im Stripe-Dashboard),
> **`Entscheidung offen:`** (der Owner muss entscheiden), **`wartet auf`** (Bedingung oder
> Folgetask fehlt noch). Positionen ohne Präfix sind **gewöhnliche, sofort umsetzbare
> Arbeit** — ein Präfix ohne Grund wäre schlechter als keiner.
>
> **Die Entscheidungen liegen in `features/owner-decisions.md`**, ihr Umsetzungsstand im
> Protokoll ganz oben. Eine Position mit `Entscheidung offen:` bedeutet, dass eine **neue**
> Frage entstanden ist — nicht, dass eine vergessene offen blieb.
>
> **Struktur-Hinweis, fortgeschrieben (2026-10-21):** **4 offene `[ ]`** und **16 wartende
> `[~]`** über 1300 Zeilen, **0 erledigte** — **am committeten Stand dieses Commits** gemessen
> (`git show <stand>:AGENTS.todo.md | grep -c '^- \[ \]'` → 4, `… | grep -c '^- \[~\]'` → 16,
> `… | grep -c '^- \[x\]'` → 0, `… | wc -l` → 1300; `<stand>` = der committete Stand dieses
> Commits — der Board wird von diesem Commit geschrieben, ein Vorgänger-Vergleich des Boards
> trägt deshalb nicht; Regel: `skills-marker/SKILL.md` → „Bezugsrahmen-Regel"). Aufteilung der
> 4: **3× `manuell prüfen:`** und **1× gewöhnliche Arbeit**; **0× `Entscheidung offen:`** — die
> DB-Engine-Policy ist inzwischen als **D-49** entschieden (`features/owner-decisions.md`), ihre
> Position ist deshalb aus dem Board entfernt.
> **Alle 14 Review-Befunde** sind geschlossen und aus der Liste entfernt (Nachweis in
> `features/security/2026-10-18-review-closure.md`); die 3 enthalten die Punkte, die in den
> Freigaben der Wellen A–D als nicht-blockierend eingestuft und deshalb hier festgehalten
> wurden (siehe „Neue offene Punkte aus den Wellen A–D") sowie die seitdem aufgedeckten Reste
> (die Zwei-Verbindungs-Messung, die Restform der Dialog-Befunde, das Audit vom 2026-10-20 aus
> Code statt vom Gerät beantwortet hat) und die manuellen Prüfpunkte der Deploys. Die beiden
> Query-Zähl-Kosten sind geschlossen: die Basislinien sind gepinnt und der Lesepfad löst den
> Brand-Filter gesammelt und den Org-Scope **einmal pro Request** auf — ein Cache über
> Requests hinweg ist bewusst **nicht** umgesetzt, weil er eine gerade widerrufene Delegation
> ausliefern würde (`features/auth/01-roles-and-access.md` §6). Die **Aktualitätsprüfung vom
> 2026-10-20** hat 16 alte Owner-Positionen durchgesehen: 11 davon sind erledigt, maschinell
> abgedeckt oder überholt und deshalb aus der Liste gegangen; die restlichen sind mit korrigiertem
> Wortlaut und dem richtigen Werkzeug (Firewalld statt ufw, `ROOT.PEM` statt `cert.pem`,
> authentifiziert statt anonym lesen) stehen geblieben. Alle 16 `[~]` tragen ein `wartet auf`.
> **Diese Zählung ist ein Stand, keine Zusage** — sie wandert mit jeder Welle; wer eine Position
> sucht, zählt selbst nach. Der Vergleich zum 2026-09-28-Stand (69 Positionen) ist **kein**
> Fortschrittsmaß: seither kamen Positionen hinzu, andere wurden entfernt.
>
> **Gemessen, nicht geschätzt:** die Zahlen oben stammen aus
> `git show <stand>:AGENTS.todo.md | grep -cE '^[[:space:]]*[-*][[:space:]]*\[[x ~]\]'`
> (20 = 4 offen + 16 wartend + 0 erledigt) und `git show <stand>:AGENTS.todo.md | wc -l`
> (1300) — beides **am committeten Stand dieses Commits**; ein `grep` über den Arbeitsbaum
> würde den Vorgänger-Stand messen, nicht den committeten. **Der hier früher genannte Befehl war
> defekt:** die Fassung `\[\[[x ~]\]]` verlangt **zwei** öffnende Klammern und traf deshalb
> **nichts** — am 2026-10-18 gemessen: 0 Treffer, Exit 1. Das Entscheidungs-Protokoll
> (Zeilen 202–283) trägt **keine** solchen Listenpunkte, der frühere Ausschluss-Hinweis ist
> damit gegenstandslos.
> Die Positionen liegen in **10 von 33 `##`-Abschnitten**
> (`git show <stand>:AGENTS.todo.md | awk '/^## /{h=$0} /^- \[[ ~]\]/{print h}' | sort -u | wc -l`
> gegen `git show <stand>:AGENTS.todo.md | grep -c '^## '`; **neu gemessen am 2026-10-21** —
> die vorherige Angabe war mit dieser Methode nicht reproduzierbar und wurde durch den Messwert
> ersetzt). Diese Zahl ändert sich mit jeder Runde, deshalb steht sie hier **nur** als
> Orientierung und ist nicht festgeschrieben.
> Wer hier eine Position sucht, nutzt `git show <stand>:AGENTS.todo.md | grep -n '^- \['` und
> nicht die Reihenfolge im Dokument.

## 🚀 PRODUKTIONS-DEPLOY 2026-10-21 (`d8ef650b`) — Preisstrategie-Overlay und Contact Sheet Phase 3+4 live

Achter Deploy desselben Tages: `./sync.sh` gegen den grünen **Code-Head** `30eb32b0`
(CI `39665170779`, **12 Jobs grün** — Backend, Frontend, alle acht E2E-Shards,
Security-Contract, Ratchet). Der Kopf `d8ef650b` ist ein reiner Board-Commit
(`AGENTS.todo.md`); sein Code-Baum ist mit `30eb32b0` **identisch**
(`git diff 30eb32b0 d8ef650b` → nur `AGENTS.todo.md`). Für ihn löst der Pfad-Filter
der Workflow-Regel (`!**/*.md`) bewusst keinen Lauf aus — die Jobs, die `.md` lesen
können, wurden deshalb lokal auf dem neuen Kopf ausgeführt: alle drei
Security-Contract-Skripte grün, die Ratchet-Registry unberührt (0 offene Findings
= Baseline ✓).

**Vorher auf dem Host geprüft:**

```
./scripts/check-license-pricing.sh --expect=base_price=14900
  → 9 Keys, 0 Verstöße, Exit 0
./scripts/check-seeded-settings.sh
  → 3 von 28 Werten weichen ab (calc_outdoor_images_per_hour, base_price, company_email)
```

identisch zum Stand des siebten Deploys → **kein Seed**.

**Übertragen:** 15 Backend-Dateien (der Resolver, der öffentliche Controller, der
Link-Service, die Collection-View, `routes/api.php`) und das neue Frontend-Bundle
`index-BYTUJDGu.js`. Keine Manifest- oder Lockfile-Änderung im Umfang → kein
`pnpm install` nötig. Nach den synchronisierten PHP-Änderungen:
`docker restart portal_backend` (Container danach `Up (healthy)`).

**Gesundheitsprüfung und das geänderte Verhalten (live gemessen):**

```
portal.reisinger.pictures/up                                          → 200
portal.reisinger.pictures/api/notifications/preferences (ohne Token) → 401
GET /api/settings/license-pricing
  → 200 {"pricing_strategy":"scope_licensing","volume_pricing":null}
GET /api/public/model-contact-sheet/<ungültiger Token>
  → 404 {"error":"Dieser Link ist ungültig oder abgelaufen."}
GET /kontaktbogen/<ungültiger Token>                                  → 200 (SPA)
```

Das ausgelieferte Bundle ist **byte-identisch** mit dem lokalen `dist/`:
`index-BYTUJDGu.js`, `sha256(index.html) = f4d42cec8be9bbf4fbec6a9e7508783f`
(der vor dem Deploy notierte Hash ✓). `route:list` zeigt die drei neuen Routen ✓;
die öffentliche Route unterscheidet sich von einer erfundenen Route (Laravel-HTML-404)
— **kein Token-Orakel** ✓.

**Nach dem Deploy auf dem Host gemessen:**

```
docker exec portal_backend php artisan ops:check-license-pricing --expect=base_price=14900
  → 9 Keys, 0 Verstöße, Exit 0        (die Auflösung läuft über den neuen Resolver)
php artisan migrate:status              → V046–V048 Ran, keine offene Migration
storage/app/private                     → 6 Einträge, unversehrt
```

**Preisneutral:** das Overlay `brand_config.pricing_strategy` wird nicht geseedet →
in Produktion gilt weiterhin die `settings.pricing_strategy`-Zeile, der effektive
Modus ist live gemessen `scope_licensing` ✓. Der neue Preisstrategie-Pfad wird erst
wirksam, wenn ein Super-Admin im Admin-UI ein Overlay setzt.
## 🔍 HEAD-AUDIT 2026-10-21 — die drei Feature-Commits geprüft, 9 Befunde dispositioniert

Die Commits `605048a6` (Preisstrategie je Marke), `f230a26d` (Contact Sheet Phase 3+4)
und `d8ef6500` (Board) wurden von einem unabhängigen Audit geprüft: **CHANGES REQUIRED**
mit 2 `medium`, 1 `low` und 5 **nicht ausgeführten** Messpunkten. Der Haupt-Agent hat
die fünf offenen Punkte selbst nachgemessen; die beiden `medium`-Befunde sind gemessen
folgenlos bzw. prozessbedingt nicht mehr schließbar. **Es entsteht keine neue
Position** — jeder Befund ist geschlossen oder bewusst angenommen (siehe die Tabelle).
Nachweis je Zeile: der Audit-Bericht und die Nachmessungen des Haupt-Agenten.

| Befund | Status | Nachweis |
|---|---|---|
| F-1 (medium) Der i18n-Katalog wurde nicht je Commit getrennt (Commit 1 trägt auch die Strings der zweiten Lieferung) | **angenommen — folgenlos belegt** | Commit 1 **allein** besteht den echten i18n-Check (`✅ i18n check passed (1699 messages compiled)`); die Prüfregel lautet `compiledCount >= msgids.size` und erlaubt das Superset ausdrücklich (`frontend/scripts/check-i18n.mjs:1052`). Ein Rewrite der **gepushten** Historie ist durch die Amend-Regel ausgeschlossen („amend only while … none of it pushed — otherwise a new commit"). Die Abweichung ist in der Commit-Nachricht offengelegt. |
| F-2 (medium) Die `Verify:`-Footer nennen den Status jedes Finding-ID nicht | **geschlossen** | Der einzige `high`-Befund aus Runde 1 (Scope-Drift) ist durch den **Commit-Split** geschlossen — am Baum nachweisbar: Commit 1 enthält weder Contact-Sheet-Code noch die Bulk-Route noch `namePrefix`, `owner-decisions.md` trägt dort D-47 ohne D-48. N7 (fehlende Drosselung der Bestandsroute) ist als bewusster Zustand in `features/crm/07-model-contact-sheet-export.md` §1 dokumentiert. Die Footer der gepushten Commits sind eingefroren; **dieser Record trägt die Statusliste**. |
| F-3 (open) 14 fachfremde E2E-Specs in Commit 1 | **geschlossen (nachgemessen)** | 13 der 14 Specs haben je **genau 7 echte neue Zeilen** (Import + `withGlobalSettingsLock`-Wrapper) und **0** entfernte — der Rest der Diff ist reine Re-Einrückung; die 14. Datei ist der Writer-Spec mit dem neuen Preisstrategie-Test. |
| F-4 (open) Scope-Drift-Muster (`console.log`, `eslint-disable`, `@ts-ignore`, Scratch-Dateien) | **geschlossen (nachgemessen)** | Ein einziger Treffer: `/*eslint-disable*/` in der **generierten** `frontend/locale/de/messages.js` — **vorbestehend** (steht so schon in `01e7f757`). Keine Debug-Ausgabe, keine Scratch-Datei, kein `any`. |
| F-5 (open) Baum-Ebene der vier geteilten Dateien | **geschlossen (nachgemessen)** | 14 von 14 Prüfungen OK: Commit 1 → `BrandRegistry.php` mit der **alten** `frontendUrl(?Brand $brand = null)` und dem **neuen** `pricingStrategy`, `E2ESessionHelper.ts` **ohne** `namePrefix` und **mit** `pricing_strategy`, `owner-decisions.md` mit D-47, ohne D-48, Kopfzeile „D-1 through D-47"; Commit 2 → derselbe Stand voll („D-1 through D-48", `namePrefix`, `frontendUrl(Brand\|string\|null)`). |
| F-6 (open) Doku-Aussagen vs. Code im jeweiligen Commit | **geschlossen (nachgemessen)** | Routen, die 25er-Grenze (`ModelContactSheetService::MAX_BULK_PROFILES = 25` + 422 im Controller), das Byte-Budget (32 MiB über die eingebetteten Bytes), die 7-Tage-TTL, die generische 404 und die Auflösungsreihenfolge stimmen zwischen Code und Doku **im selben Commit** überein. |
| F-7 (open) Board-Zahlen und Board-Commit | **geschlossen (nachgemessen)** | Auf dem **Commit-Blob** gemessen: offen 6 → **4**, wartend **46**, erledigt **0**, Zeilen 1807 → **1802**, `##`-Abschnitte 32 → **31**, Abschnitte mit Position 17 → **15**, Summe der Marker **50**. Die 12 eingefügten Zeilen des Board-Commits sind die **neu gemessene** Struktur-Notiz (die Zeilenzahl 1800 war zuvor um 2 daneben). |
| F-8 (low) Betreff-Großschreibung „Contact Sheet" | **keine Änderung** | „Contact Sheet" ist ein Eigenname und wird im Deutschen großgeschrieben. |
**E2E-Laufzeit nach der Lock-Änderung neu gemessen (Pflicht aus der Timeout-Policy):**
der lokale Volllauf am 2026-10-21 um 22:01 Uhr — **466 Tests, 458 passed, 5 skipped,
3 failed in 13,9 min** (8 Worker, eine Datenbank). Das 25-Minuten-`globalTimeout` ist damit
**nicht** ausgeschöpft (rund 55 % davon); eine Anpassung ist **nicht** nötig und wurde **nicht**
vorgenommen. Die drei Fehlschläge sind **lastbedingt und nicht durch die beiden Features
verursacht**: `admin/documents/wysiwyg-editor.spec.ts:19`,
`admin/pricing/package-calculator-config.spec.ts:69` und
`admin/galleries/meta-gallery-licensing.spec.ts:29` laufen **isoliert und in der
Dreier-Kombination grün**; der Calculator-Pfad (`getLicenseTerms()` / `updateLicenseTerms()`)
ist im Diff der Features **unverändert**, und im Backend-Log des Volllaufs steht in diesem
Zeitfenster **kein einziger Fehler** (kein `database is locked`, keine Exception). Der grüne
Voll-CI des exakt gepushten Heads `d8ef6500` (12/12 Jobs) ist das Gate.

- [~] wartet auf eine Ursache für die drei lastabhängigen Volllauf-Fehlschläge —
  `frontend/tests/e2e/admin/documents/wysiwyg-editor.spec.ts:19` (WYSIWYG-Editor),
  `frontend/tests/e2e/admin/pricing/package-calculator-config.spec.ts:69` (Paket-Rechner) und
  `frontend/tests/e2e/admin/galleries/meta-gallery-licensing.spec.ts:29` (Meta-Gallery): alle
  drei laufen isoliert und in der Kombination grün und fielen nur im Volllauf (8 Worker, eine
  SQLite-Datenbank). Nicht featuresbedingt (der Calculator-Pfad ist im Diff unverändert, das
  Backend-Log des Laufs ist fehlerfrei). Ursache offen — Verdacht: Suchindex-Lag
  (Scout/Meilisearch) bzw. DB-Lock unter 466 Tests. **Kein Produktcode betroffen.**

## ⏳ Backend Offen — nicht implementierte Vorschläge


## 🗳 Entscheidungs-Protokoll (Owner, 2026-09-28)

**Was gilt, steht in `features/owner-decisions.md` (D-1 bis D-48).** Diese Datei sagt nur, **ob es schon
gilt** — und hält fest, welche Ansätze zurückgezogen wurden, damit sie nicht erneut
implementiert werden.

**Zur Form — und warum dieses Board doch `[x]` enthält:** §4 Board-Hygiene verbietet `[x]` für
**Arbeitspositionen**, weil ein abgehakter Eintrag wie eine offene aussieht. Das gilt hier nicht:
eine Entscheidung ist eine Aussage, keine Aufgabe, und ihr Zustand ist genau zweiwertig. Ein
`[x]` bedeutet hier **„die Entscheidung ist in Kraft"**, ein `[ ]` **„die Entscheidung steht, ist
aber nicht umgesetzt"**. Beide Formen stehen ausschließlich in diesem Abschnitt, nie im Board
darunter. Wer diese Trennung auflöst, hat die Board-Hygiene gebrochen.

### Stand

Alle Entscheidungen D-1 bis D-49 sind getroffen. Soweit sie Code erforderten, sind sie **umgesetzt
und verifiziert** — die Entscheidungen stehen in `features/owner-decisions.md`, die
zurückgezogenen Varianten direkt darunter in „Verworfen".

Dieses Protokoll führt **abgeschlossene** Positionen seit der Bereinigung vom 2026-10-15 nicht
mehr. Ein `[x]`-Eintrag ist per Board-Hygiene (`AGENTS.md` §4) ein Signal für offene Arbeit und
wird entfernt, nicht abgehakt: wer nach einem Befund sucht, fände sonst erledigte Arbeit und
hielte sie für offen. Wer den Umsetzungsstand oder die Belege einer geschlossenen Position
braucht, findet sie vollständig in `git log` — die Blöcke sind in der Historie erhalten.

Bewusst offen bleiben nur die einzeln geführten Positionen weiter unten; sie tragen `[ ]` mit
einem konkreten Auftrag oder `[~]` mit der Bedingung, auf die sie warten.

**Zwei Lehren aus den geschlossenen Positionen**, die sonst nirgends stünden: die
`gh run list --commit`-Messfalle (nur die **volle** SHA liefert Runs, eine Kurz-SHA liest sich als
„CI lief nie") und die `ENOENT`-statt-`chmod`-Testdesign-Lehre (CI läuft als root, ein
`chmod`-Fixture wird dort ignoriert) sind historische Erkenntnisse aus D-23 und D-2; die aktuellen
Entscheidungen stehen in `features/owner-decisions.md`.

### Verworfen — nicht erneut implementieren

Zurückgezogen am 2026-09-28, ergänzt am 2026-10-15 (D-24, D-25). Der Owner hat jede dieser
Varianten gesehen und verworfen; die geltenden Entscheidungen stehen in `features/owner-decisions.md`.

| Zu | Verworfene Variante | Warum verworfen |
|---|---|---|
| D-1 | `1000:1000` behalten, Ausnahme für ein Verzeichnis dokumentiert | mischt zwei Ownership-Modelle im Website-Baum |
| D-1 | Drift nur dokumentieren, Recreate aufschieben | der erste spontane `up -d` bricht dann trotzdem alles |
| D-2 | manueller Host-Schritt bzw. UI-Warnung statt automatischer Anlage | der Zustand „Slug gesetzt, Ordner fehlt" bliebe möglich |
| D-2 | Setzen gelingt, Fehler erst beim Import | Fehler käme zu spät und wäre schwerer zu entdecken |
| D-3 | Branch-Protection mit verpflichtendem CI-Status, oder mit Review-Pflicht | Trust-Frage; der Owner entscheidet, main bleibt ungeschützt |
| D-4 | Secrets rotieren, oder zusätzlich Secret-Manager einführen | kein Repo-Risiko; ein Code-Fix kopiert das Secret nur umher |
| D-5 | Aufräumen plus Meldeschwelle, oder nur melden | die Rückfrage „nicht relevant auf diesem Host?" hat die Messung beantwortet: gilt dem Deploy-Host, dort ist es Deploy-Arbeit neben D-1 |
| D-6 | nur `.env.ci` anheben, `.env.example` unkommentiert lassen | 60 als Produktions-Sinnwert soll sichtbar bleiben |
| D-6 | unverändert lassen (60 als Canary) | der nächste parallelsinnige Test wird flaky statt rot |
| D-7 | Regel unverändert, die Findings manuell abarbeiten | erzeugt bei zerrissenen Sätzen mehr Rauschen als Nutzen |
| D-7 | als Altlast dokumentieren, nicht abarbeiten | die Regelerweiterung ist billiger als 246 Einzelbefunde |
| D-8 | nach Akteur aufteilen, oder Taxonomie stehen lassen | die übrigen Verzeichnisse sind nach Domäne benannt |
| D-9 | `truncate` mit `title`, oder so lassen | stille Kürzung ohne sichtbaren Effekt; Umbruch löst die Asymmetrie |
| D-10 | Scoping im Harness aufweichen, oder die Lücke dokumentiert lassen | Testfix schwächt die Regel; Strukturänderung behebt die Ursache |
| D-11 | nur benannte Höhe, oder nichts ändern | ein Slot ohne Migration wäre unbenutzte API in 28 Aufrufstellen |
| D-12 | Fokusfalle behalten, Hook in der Shell, Einzelfall im Dialog | **alle drei** unzulänglich; daher erst ausarbeiten und vorlegen. Die Recherche hat danach bestätigt, dass alle drei **weiterhin** unzulänglich sind — und eine vierte Option gefunden |
| D-12 | B: DOM umsortieren, Close-Button hinter den Inhalt | verschiebt die **Tab-Reihenfolge** in allen 28 Dialogen, nicht nur den Anfangsfokus |
| D-12 | C: auf das `<dialog>`-Element fokussieren | beste Screenreader-Ansage, aber es setzt den Cursor in **kein** Feld — scheitert am eigentlichen Einwand |
| D-12 | D: `showModal()` als einzige Antwort | die einzige Option, die auch die Hintergrund-Inertheit mitlöst; greift aber über `ModalHelper` (`.modal-open`) in 36 E2E-Dateien ein. **Nicht verworfen**, sondern als eigene Welle zurückgestellt |
| D-12 | Rückkehr-Zielpunkt über einen neuen `initialFocusRef`-Prop | der sauberere Rückweg, aber genau die Form der verworfenen Variante 2 — nur für den Rückweg statt für den Hinweg |
| D-13 | Skala vollständig bauen, oder unverändert dokumentieren | ein Prop, der seine Hälfte verwirft, lügt |
| D-14 | nur Schließen und Abbrechen fragen, oder Undo-Toast | ein Modal, der sich nicht schließen lässt, ist eine Sackgasse |
| D-15 | Frist festlegen und Löschpfad implementieren, oder Frist selbst nennen | Owner-Entscheidung: **unbegrenzt, bewusst so** |
| D-20 | `readOnly` als negierender Rename (Vorschlag des Owners) | **widerlegt durch Messung:** `editing={!!initialName}` wird zu `readOnly={!initialName}`, beim Bearbeiten truthy → der Button rendert weiter. Der Rename ändert den Namen, nicht den toten Button. Dazu ist der Name sachlich falsch (der Dialog ist editierbar) und kollidiert mit `CartItemList.tsx:15` |
| D-20 | nur den Live-Bug in `VolumePresetSettingsCard.tsx:118` beheben | `editing` und `onDelete` blieben unabhängig; der nächste Aufrufer trennt sie wieder |
| D-21 | Kollision als dokumentierte Falle belassen | sie ist es zwar, aber D-20 nimmt den Shell-Bedeutungsträger weg — das Wort bliebe zweimal im selben Baum |
| D-18 | Assertions auf sichtbare Merkmale umstellen | die 3 `expect`-Assertions prüfen dann nicht mehr, welches Dialogelement gemeint ist (die 4 Locator-Ketten ebenso) |
| D-19 | nur `ManagementOrgsView` testen, oder bewusst ungetestet lassen | die Detail-View bleibt die ungeschütztere; „bewusst" wäre eine Feststellung ohne Deckung |
| D-16 | vollständig ausarbeiten, oder nach Kategorie trennen | der rechtliche Teil ist DPO-Freigabe und wird nicht erfunden |
| D-17 | beide Policies bündeln, oder beide als akzeptiertes Risiko | zwei Systeme, zwei Reviewer; „bewusst offen" wäre begründungspflichtig |
| D-24 | `frontend/.env.local` → `.env.development.local` umbenennen | strukturell robuster (kein zweiter Ablageort für den Live-Key) — der Owner hat die zusätzliche Datei `.env.production` gewählt |
| D-25 | beide Live-Secrets rollen (Key per Zwischenablage übergeben, Endpunkt neu anlegen, damit sein `whsec` nie durch den Chat läuft) | Owner: der Provider fährt ZDR, also kein Aufbewahrungsrisiko; ein Roll wäre Zeremonie mit Ausfallfenster bis der neue Wert in Stack 23 steht |
| D-26 | Prüfung 5 umgebungsabhängig machen — fehlender Webhook-Endpunkt = `warn` in local/testing, `fail` in production | eine Prüfung mit zwei Bedeutungen verliert die Eindeutigkeit von „rot heißt kaputt"; der Ist-Zustand ist auch lokal ein Befund, kein Sonderfall |
| D-26 | den Webhook-Endpunkt im E2E per `page.route` mocken und so einen komplett grünen Zustand assertieren | der grüne Assert wäre dann ein Test der Darstellung statt der Verbindung; die D-24-Klasse (falscher Modus im Bundle) bliebe allein ungemockt geprüft. Der Zweig-Test gehört in PHPUnit, wo ein gefakter Client ihn deterministisch erzeugt |

**Zurückgezogen heißt nicht vergessen:** Wenn jemand eine dieser Varianten erneut vorschlägt,
ist die Frage nicht „ist das besser?", sondern „ist D-x überholt?" — und dann wird **D-x in
`features/owner-decisions.md` ersetzt**, nicht ergänzt.

---

## 🚀 PRODUKTIONS-DEPLOY 2026-10-21 (`52980e2c`) — die Prüfbefehle laufen live

Siebter Deploy desselben Tages: `./sync.sh` gegen den grünen Head `52980e2c`
(CI `39572251086`, **13 Jobs grün** — Backend, Frontend, alle sechs
E2E-Shards, Security-Contract, Ratchet). **Übertragen:** die Backend-Dateien
(die beiden Prüfbefehle, `LicenseTermRules`, `BrandSelection`, der Seeder mit
der öffentlichen Konstante) und das **unveränderte** Frontend-Bundle — seit dem
sechsten Deploy hat sich keine Frontend-Quelle geändert (`git log dc290ec..HEAD
-- frontend/src` ist leer), das Bundle `index-86wfO5VA.js` ist unverändert live.

**In der Produktion ausgeführt und gemessen:**

```
docker exec portal_backend php artisan ops:check-license-pricing --expect=base_price=14900
  → 9 Keys, 0 Verstöße, Exit 0        (base_price 14900 = 149,00 €)
docker exec portal_backend php artisan ops:check-seeded-settings
  → 3 von 28 Werten weichen ab, Exit 1
    (calc_outdoor_images_per_hour, base_price, company_email)
docker exec portal_backend php artisan ops:check-license-pricing --expect=base_price=8000
  → Exit 1                             (die falsche Erwartung wird erkannt)
docker exec portal_backend php artisan ops:check-license-pricing --brand=other
  → Exit 1                             (eine unbekannte Marke wird verweigert)
```

Der Seed-Vergleich nennt **genau** die drei Abweichungen, die auch die
unabhängige Messung per `SELECT` gefunden hat — der Befehl und die Messung
sagen dasselbe. App-Preflight: `/up` → 200, `/api/notifications/preferences`
ohne Token → 401, `storage/app/private` unversehrt.

**Zum Neustart:** `AGENTS.md` §13 verlangt nach synchronisierten PHP-Änderungen
ein `docker restart portal_backend`, damit die Caches die neuen Dateien laden.
Diese Änderung fügt **weder eine Route noch eine Konfiguration** hinzu, und die
neuen Befehle sind bereits jetzt wirksam (an der laufenden Instanz gemessen) —
der Neustart ist damit kein offener Punkt, kann aber ohne Risiko gesetzt
werden.

## 🚀 PRODUKTIONS-DEPLOY 2026-10-21 (`dc290ec`) — Neustart **erfolgt**

Sechster Deploy desselben Tages: `./sync.sh` gegen den grünen Head `dc290ec` (CI `39556861211`,
alle 11 Jobs grün). **Übertragen:** nur die Frontend-Dateien — ein neu gebautes Bundle
(`assets/index-86wfO5VA.js`) mit dem Dialog-Contract (`dvh`-Cap, das `€` im Preisfeld,
44-px-Tap-Targets, der leere Listenrahmen) und `backend/AGENTS.md`; der Backend-Code
unverändert gegenüber dem vorigen Deploy. **Maschinell verifiziert:** das neue Bundle wird
ausgeliefert (200), `portal.reisinger.pictures/up` → 200, `/api/notifications/preferences`
ohne Token → 401, `storage/app/private` unversehrt (5 Einträge).

**Der Neustart ist erfolgt** (zwischenzeitlich durch den Owner): `migrate:status` zeigt
**V046, V047 und V048 als `Ran`**, die Tabellen `gallery_notification_subscriptions` und
`group_notification_subscriptions` existieren in der Produktion.

**Die beiden Reparaturlisten sind leer — es gibt nichts zu reparieren.** Beide
Trockenläufe melden das ausdrücklich:

```
docker exec portal_backend php artisan notifications:prune-legacy-opt-in-grants
  → "No row in [user_galleries] carries the opt-in flag. Nothing to repair."   (Exit 0)
docker exec portal_backend php artisan notifications:prune-legacy-group-opt-in-grants
  → "No row in [user_gallery_groups] carries the opt-in flag. Nothing to repair." (Exit 0)
```

In der Produktion trug **keine** Pivot-Zeile das Opt-in-Flag (gemessen: 0 in
`user_galleries`, 0 in `user_gallery_groups`), der Backfill der beiden Migrationen hatte
also nichts zu übernehmen, und es gibt keinen Rest, der eine Zuweisung erzeugen könnte.
**Ein `--apply` ist hier weder nötig noch sinnvoll.**


## 🚀 PRODUKTIONS-DEPLOY 2026-10-21 (`bd48c03`) — abgeschlossen

Fünfter Deploy desselben Tages: `./sync.sh` gegen den grünen Head `bd48c03` (CI `39547499786`,
alle 11 Jobs grün; der Head enthält den automatisch gemergten Dependabot-Bump `324ff65`,
vite 8.2.2 → 8.2.4, dessen Lockfile vorher mit `pnpm install --frozen-lockfile` installiert
wurde). **Übertragen:** die Gruppen-Abo-Tabelle mit Migration V048
(`GroupNotificationSubscription`, `PruneLegacyGroupOptInGrants`, `NotificationController`,
`MailController`, `User`), der set-basierte Verwaltungspfad in `AuthorizationService`
(`canManageGalleryGroup()`), die Test-Group-Verträge (kein Produktivcode) und ein neu
gebautes Frontend-Bundle (`assets/index-CHI4CK5n.js` — der Dialog-Contract: `dvh`-Cap statt
`vh`, das `€` im Preisfeld, 44-px-Tap-Targets). **Maschinell verifiziert:** die neuen Dateien
liegen auf dem Host, beide Reparaturbefehle sind registriert, `migrate:status` zeigt **V047
und V048 als `Pending`** — der Neustart führt sie aus —, das neue Bundle wird ausgeliefert
(200), `portal.reisinger.pictures/up` → 200, `storage/app/private` unversehrt.

**Erledigt (2026-10-21):** der Neustart hat die Migration ausgeführt — `migrate:status` zeigt
V047 und V048 als `Ran`, beide Abo-Tabellen existieren, `/up` → 200. **Kein
`db:seed --force`** war nötig (die Migrationen fügen keine Settings-Schlüssel hinzu, und der
Seeder überschreibt in der Produktion seine autoritativen Werte).
**Erledigt (2026-10-21):** beide Trockenläufe melden ausdrücklich „Nothing to repair." — in
der Produktion trug keine Pivot-Zeile das Opt-in-Flag (gemessen: 0 in `user_galleries`,
0 in `user_gallery_groups`). Es gibt keine Kandidaten, also **kein `--apply`** und keine
Restzeile, die eine Zuweisung erzeugen könnte. Die Verfahrensanleitung (Trockenlauf zuerst,
`--only` je Zeile, `unmatched` endet mit Exit 1) bleibt für Datenbestände, die das Flag
doch tragen.
## 🚀 PRODUKTIONS-DEPLOY 2026-10-21 (`a65e9d7`) — abgeschlossen

Dritter Deploy: `./sync.sh` gegen den grünen Head `a65e9d7` (CI `39503917134`, alle 11 Jobs
grün). **Übertragen:** neun inhaltlich geänderte Backend-Dateien — die Abo-Tabelle mit
Migration V047 (`GalleryNotificationSubscription`, `PruneLegacyOptInGrants`,
`NotificationController`, `MailController`, `GalleryFrontendController`, `User`) und der
Ahnen-Ketten-Bound in `AuthorizationService` — sowie ein neu gebautes Frontend-Bundle
(`assets/index-DF75gXdS.js`; der Build-Zeitstempel in `frontend/vite.config.ts:10` macht jedes
Bundle eindeutig, obwohl die Frontend-Quellen unverändert sind — geändert hat sich nur ein
Kommentar im Screenshot-Seed). **Maschinell verifiziert:** die neuen Dateien liegen auf dem
Host, `migrate:status` zeigt V047 als **Pending** — der Neustart führt die Migration aus.

**Die offenen Schritte dieses Deploys sind in den Abschnitten vom 2026-10-21 (`bd48c03`, `dc290ec`) übernommen** — dort steht der Neustart, der **beide** Migrationen (V047 und V048) ausführt, und die **beide** Reparaturlisten (Galerie und Gruppe).

## 🚀 PRODUKTIONS-DEPLOY 2026-10-20 (`64b8ae2`) — abgeschlossen

Zweiter Deploy desselben Tages: `./sync.sh` gegen den grünen Head `64b8ae2` (CI `39470617556`,
alle 11 Jobs grün). **Übertragen:** drei Backend-Dateien (`OrgController` — aufsteigende
Mitgliedersperre, `AppServiceProvider` — Bindung + Guard, `AuthorizationService` — set-basierter
Brand-Filter + Scope-Memo) und ein neu gebautes Frontend-Bundle (`assets/index-g_hP4jz6.js`, 42
Assets; der Build-Zeitstempel in `frontend/vite.config.ts:10` macht jedes Bundle eindeutig, auch
bei identischen Quellen — deshalb der Asset-Tausch trotz unveränderter Frontend-Quellen).
**Maschinell verifiziert:** aufsteigende `orderBy` im Destroy (`:167`, `:169`) und beide Guard-
Hooks auf dem Host, `BrandRegistry.php` byte-identisch (`md5 1ccba57…`), `portal.reisinger.
pictures/up` → 200, das neue Bundle wird ausgeliefert, `storage/app/private` unversehrt (93
Altersnachweise, `stripe_secret.txt`, `watermark.svg`).

**Neustart erfolgt 2026-10-20:** der Container startete um 20:16:29 lokal, die synchronisierten
Dateien lagen seit 19:56:40 auf dem Host — der laufende Prozess hat den neuen Stand geladen.
`portal.reisinger.pictures/up` → 200, `/api/auth/me` ohne Token → 401,
`POST /api/internal/ftp/upload-event` ohne Secret → 401.

## 🚀 PRODUKTIONS-DEPLOY 2026-10-19 (`64fa9be`) — abgeschlossen

Deploy ausgeführt: `./sync.sh` (43 Backend-Dateien, 58 Frontend-Assets) gegen den grünen
Head `64fa9be` (CI `39291191094`, alle 11 Jobs grün). **Maschinell verifiziert:** die neuen
Dateien liegen auf dem Host (`app/Support/AtomicRateCounter.php`, `app/Support/
DirectGalleryGrant.php`, `config/ftp.php`, `app/Jobs/ImportFtpBatch.php`), `ext-gd` ist im
Produktionscontainer geladen (PHP 8.5.11), das Frontend liefert das neue Bundle
(`assets/index-Ce94b7M7.js`, HTTP 200), die alten Hash-Assets sind gelöscht, `portal.
reisinger.pictures/up` antwortet 200. `tests/`, `vendor/` und `storage/` sind per
`rsync-backend-exclude.txt` ausgenommen, `features/` wird nicht übertragen.

**Abgeschlossen:** Neustart (`docker restart portal_backend`) vom Owner ausgeführt und die sechs
Gegenproben bestätigt (Kontaktbogen-Hinweis, Zugriffs-Dialog, registrierter Invite-Client,
Org-Sync, Coupon-/Webhook-/Checkout-Limiter, FTP-Status) — 2026-10-19. Die Position ist damit
geschlossen und wandert ins Git.

## 🚀 PRODUKTIONS-DEPLOY 2026-09-27 (`002e9eb`) — manuell prüfen

Deploy ausgeführt: `./sync.sh` → `docker restart portal_backend` → Gegenprobe.
**Maschinell verifiziert:** 4 Container healthy, der neue PHP-Code ist im laufenden
Prozess vorhanden (OPcache/Compiler-Zustand nach Neustart), Frontend-Bundle-Hash
unverändert gegenüber dem vorigen Deploy, Homepage **200**.
**Noch manuell durch den Owner zu prüfen** (nicht automatisierbar, kein Test deckt sie):

- [ ] manuell prüfen: **FTP-Karte im Browser** — anmelden, Verwaltungs-Dashboard
  (`/management`, Ansicht „Struktur" — **kein** `/management/ftp`: diese Route gibt es nicht).
  Die Inbox-Tabelle gibt es nicht mehr; die Karte zeigt `ftp_folder`, Warteschlangen- und
  Quarantäne-Zähler samt Pfad (`ManagementFtpInbox.tsx:286-331`). **Worauf es ankommt:**
  das Konto steht auf `active`, das SFTPGo-Heimverzeichnis entspricht `ftp_folder`
  (`FtpController.php:116`), `_quarantine` existiert auf dem Host
  (`FtpInboxImporter.php:135`) — und ein Upload **ohne** Button importiert (D-36-Ereigniskette).
  **Die Hälfte „kein 500er auf `GET /api/management/ftp/status`" ist am 2026-10-19 bestätigt**
  und erledigt. **Vorher maschinell:** die D-36-Ereignisregel ist am 2026-10-21 **gegen ein
  laufendes SFTPGo verifiziert** — `scripts/e2e-up.sh` provisioniert sie bei jedem
  Test-Stack-Setup, und der Test-SFTPGo zeigt die Aktion (POST, Secret-Header,
  JSON-Body, Timeout 10 s) und die Regel (`fs_events: ["upload"]`, aktiv) zurück.
  **In der Produktion ist die Kette aber noch nicht scharf** (gemessen 2026-10-21:
  der laufende `portal_backend`-Container führt **kein** `FTP_EVENT_SECRET` in seiner
  Umgebung — 0 Treffer — und `php artisan ftp:provision-events --dry-run` verweigert mit
  „FTP_EVENT_SECRET ist nicht gesetzt."). Die Aktion und die Regel wurden also **nie** in
  das Produktions-SFTPGo geschrieben, und jedes Ereignis würde mit 401 abgewiesen
  (fail-closed, korrekt). **Ein Upload ohne Button importiert in der Produktion daher
  heute nicht.** Die Schritte stehen in `deployment/README.md:110-127`: Secret in der
  Portainer-GUI setzen → Stack-**Recreate** (ein Restart übernimmt keine neuen
  `environment:`-Zeilen) → `docker exec portal_backend php artisan ftp:provision-events`.
  Erst danach ist der Upload-Teil dieser Position sinnvoll prüfbar.

---

## 📌 OFFENE ARBEIT — Einstieg (Stand 2026-09-26)

Die Einstiegs-Tabellen unten sind ein **Snapshot vom 2026-09-26** und
zählen nicht die 142 offenen Positionen von heute — sie ordnen nur die
Zuordnung nach Abhängigkeit. Maßgeblich ist die Liste selbst, nicht diese
Einstiegs-Reihenfolge. Sortiert nach dem, was die Position **für den Start**
braucht, nicht nach Schwere oder Familie.

### A. Jetzt umsetzbar (keine Entscheidung, kein Dienst nötig)

| Position | Was | Quelle |
|---|---|---|
| ~~FE-8~~ | **Erledigt** — `ModalShell`-Migration vollständig: 28/28 Nutzungsdateien (inkl. `UIProvider`; Kommando in der ID-Übersicht unten) | unten |
| ~~P1-M21~~ | **Erledigt** — `FtpSlug` + Validierung | FTP-Block |
| ~~P1-M22~~ | **Erledigt** — `SftpGoClient` + `FtpCredentialService` | FTP-Block |
| ~~P1-M23~~ | **Erledigt** — Passwort-Fluss | FTP-Block |
| ~~P1-M25~~ | **Erledigt** — Regression lokale Disk | FTP-Block |
| ~~P1-M28~~ | **Erledigt** — Concurrency-Guard | FTP-Block |
| ~~P1-M30~~ | **Erledigt** — Migration V041/V043 + Reconciliation (`php artisan ftp:reconcile`) | FTP-Block |
| ~~P1-M27~~ | **Erledigt** — Kamera-Spec + Harness | FTP-Block |
| FE-2 (halb) | i18n-AST-Regel gebaut und satzebenen erweitert, meldet 212 Treffer, warnt nur | unten |
| P1-M24 | Ordner-Anlage und UID-Modell auf dem Host | FTP-Block |
| ~~P1-M26~~ | **Erledigt 2026-09-26** — `status()` zeigt den Provisionierungsstatus | FTP-Block |
| ~~P1-M33~~ | **Erledigt 2026-09-26** — Reset mit Rate-Limit und Audit-Trail | FTP-Block |
| TST-* (4) | Testqualitäts-Lücken, siehe Test-Audit | Test-Audit |

**Priorität 2026-09-26: Live-Deployment.** Ziel ist SFTPGo live zu schalten,
pure-ftpd abzuschalten, und dann mit echten Kameras zu testen und zu korrigieren.
M26 und M33 sind inzwischen abgeschlossen.

### B. Braucht eine Entscheidung oder Betriebs-Evidenz

| Position | Blockiert durch | Wer entscheidet |
|---|---|---|
| P1-M27 / M35 | Kamera-Protokoll ungeklärt; Messung steht aus | Fotograf + laufende Instanz |
| P1-M31 | `ftp_slug` vs. SFTPGo-Store: wer führt? | Owner |
| P1-M29 | Brand-Scope des Folder-Namespaces (bewusst YAGNI) | Owner, bei 2. Brand |
| P1-M36 | Cutover gegen **laufenden** `pure-ftpd` | Owner + Runbook 7.12 |
| P1-I9 | `portal_backend` läuft als root, Repo fordert `1000:1000` | Owner, vor Cutover |
| Auth-* (5) | Auth-Befunde, Verifikation ausstehend | Owner |
| P1-M24 | Ordner-Anlage und UID-Modell auf dem Host | Owner, Host-Zugriff |

### C. Ungeprüft — nicht als offen oder erledigt lesen

Diese Familien sind **weder** verifiziert **noch** abgehakt. Sie stehen in
alten Abschnitten und sehen je nach Abschnitt erledigt oder offen aus. Erst
prüfen, dann anfassen:

| Familie | Anzahl | Wo |
|---|---|---|
| `AIS-*` | 8 | verstreut, Abschnitt „P1 — AI / Mail / Jobs" |
| `AUTH-*` | 5 | dito |
| `TST-*` | 4 | Test-Audit, dort als Lücken geführt |
| P1-M32 | 1 | Kameraneukonfiguration — operativer Schritt, kein Code |

### Reihenfolge, die sich daraus ergibt

1. **Kamera prüfen** (P1-M32, ~20 Min, nur beim Fotograf). Klärt, ob SFTP oder
   FTPS überhaupt der Weg ist. Danach ist die Grundsatzentscheidung belegt.
2. **Harness bauen** (P1-M35) — beantwortet die Cipher-Frage und ist die
   Grundlage für den Integrationstest (P1-M38).
3. **A-Gruppe abarbeiten** — die M-Positionen in der Reihenfolge
   M21 → M22 → M23 → M30.
4. **Cutover** (P1-M36) erst, wenn 1–3 stehen und M27 beantwortet ist.

> **ID-Nennräume, am 2026-09-28 nachgemessen:** Die früher behaupteten Kollisionen
> sind **größtenteils erledigt**. Die `P1-M`-Kollision ist aufgelöst — die
> Invariante ist leer:
> `grep -oE "^- \[.\] \*\*P1-M[0-9]+" AGENTS.todo.md | grep -oE "P1-M[0-9]+" | sort | uniq -d`
> (`P1-M9a`/`P1-M9b` zeigen, dass umnummeriert wurde). Von den fünf `DOC`-IDs ist
> keine doppelt, `DOC-7` kommt gar nicht vor. `FE-2` ist keine Dublette (Index und
> Definition sagen beide „halb").
> **Aufgelöst am 2026-09-28:** Die letzte offene Doppelnennung ist beseitigt —
> `FE-8` stand in der Übersicht als *erledigt* und im Arbeitsplan als offener
> Brocken; der Arbeitsplan führt es nicht mehr als offene Arbeit, und die
> Übersichtszeile trägt den nachgemessenen Stand (28/28 Nutzungsdateien):
> `grep -rnE '<(ModalShell|ModalDialogShell)' frontend/src | grep -v __tests__ | grep -v ModalDialogShell.tsx | awk -F: '{print $1}' | sort -u | wc -l`
> gibt **29** aus — die 28 Nutzungsdateien (inkl. der globalen Bestätigung `UIProvider.tsx`)
> plus `ModalShell.tsx` selbst: seit `7cc5046` matcht dort die Typ-Annotation
> `Record<ModalShellHeight, string>` auf das Suchmuster, nicht ein JSX-Gebrauch. Die
> Migrations-Zahl 28/28 bleibt gültig; wer 29 zählt, hat die Shell-Datei selbst mitgezählt.

---

## 🚦 DEPLOY-STATUS (2026-09-26) — hier zuerst schauen

`main` ist grün: `CI` auf `be2977a` mit allen 11 Jobs (Backend inkl. Pint-Gate,
Frontend, Security-Contract, 7× E2E, CI-Gate). Offene PRs: 0. Offene
Security-Alerts: 0.

**Bevor dieses Board als Auftragsliste gelesen wird, eine Warnung:** Am
2026-09-26 wurden alle 81 offenen Positionen systematisch gegen den Code
verifiziert (5 Verifikationsläufe über P1-A/I/F/M/L, INFRA, DOC, FE). Ergebnis:

| Familie | Geprüft | Already done | Wirklich offen |
|---|---|---|---|
| P1-A (Auth/AI/Jobs) | 7 | 5 | 0 Code, 2 Evidenz |
| P1-I (Infra/CI) | 8 | 6 | 1 Code, 1 Einstellung |
| P1-F (Frontend) | 10 | 9 | 0 |
| P1-M (Model/Services) | 13 | 12 | 0 (behoben in `43e8943`) |
| P1-L (Lua-Plugin) | 7 | 5 | 0 (behoben in `43e8943`) |
| INFRA-3…12 | 10 | 10 | 0 |
| FE-2…8 | 7 | 5 | 2 |
| DOC-2…12 | 11 | 3 | 8 (Doku-Wahrheit) |

**Das Board war zu ~85 % veraltet.** Wer hier blind eine Position abhakt oder
abarbeitet, fasst überwiegend bereits korrigierten Code an. Jede Position
unten ist gegen den Code geprüft; Belege stehen bei der jeweiligen Zeile.

### Offene Code-Arbeit

- [~] wartet auf die Produktentscheidung, ob Fotografen ihr Passwort selbst ändern dürfen (Confirm-Flow mit aktuellem Passwort) oder nur der Admin. **P1-M23 (P0) — Passwort-Erzeugung und Show-once, statt Verschlüsselung
  at rest.** Das ist die **entscheidende Entlastung gegenüber P1-M18** und
  der eigentliche Grund für den Wechsel: SFTPGo hält das Passwort, das
  Portal muss es **nicht** speichern. Damit entfallen `ftp_credentials` als
  verschlüsselte Tabelle, `FILE_ENCRYPTION_KEY` und der
  `FILE_ENCRYPTION_PREVIOUS_KEYS`-Rotationspfad vollständig.
  **Ablauf:** PHP erzeugt ein kamerataugliches Passwort
  (`^[a-z0-9]{16,24}$`, keine Sonderzeichen — Kameras können sie nicht
  eingeben), übergibt es per HTTPS an SFTPGo, **zeigt es einmal** an und
  verwirft es. Verloren → `resetPassword()`, nicht wiederherstellbar.
  **Offen (Produktentscheidung, nicht Implementierung):** dürfen Fotografen ihr
  Passwort selbst ändern (dann Confirm-Flow mit dem aktuellen Passwort) oder
  nur der Admin? Nicht als Code-Task vergeben, bis das geklärt ist.
  **Der Provisionierungsstatus selbst ist in P1-M30 festgelegt** (Spalte auf
  `users`, kein Live-Query) — die frühere Unklarheit ist aufgelöst.
  **Tests:** PHPUnit für Erzeugung (Länge, Charset, keine Kollision nach
  `Str::random` + Prüfschleife) und dafür, dass im Request-Log/Response
  nach dem Show-once **kein** Klartext mehr auftaucht; Playwright-E2E für
  Anzeige und Reset.
- [~] wartet auf die Owner-Entscheidung, ob **zusätzlich** ein `SFTPGO_API_KEY` in die Portainer-Stack-Env kommt —
  **der Repo-Teil ist korrekt, und der Fall funktioniert auch ohne API-Key (live gemessen am 2026-10-21):**
  `deployment/docker-compose.yml:107-108` führt `SFTPGO_BASE_URL=${SFTPGO_BASE_URL:-http://sftpgo:8080}` und
  `SFTPGO_API_KEY=${SFTPGO_API_KEY:-}` (Platzhalter ✓, Regressionstest in
  `backend/tests/Feature/Services/SftpGoClientTest.php:446`), und auf dem Host sind gesetzt:
  `SFTPGO_BASE_URL` (18 Zeichen), `SFTPGO_ADMIN_USERNAME` (12), `SFTPGO_ADMIN_PASSWORD` (28) —
  `SFTPGO_API_KEY` ist **leer** (`printenv | wc -c` → 1, also nur der Zeilenumbruch).
  Der JWT-Fallback über das Admin-Wertepaar ist damit der gepflegte Weg
  (`features/infrastructure/19-ftp-upload-pipeline.md` §7.15: „Ein Wertepaar, nicht zwei") ✓ — der API-Key ist
  **nicht** zwingend, wäre aber die sicherere Variante (kein Admin-Passwort im Stack).
  **Die Entscheidung:** API-Key zusätzlich setzen (und den Admin-Weg auflösen) oder den JWT-Fallback als
  endgültig festschreiben (und §7.15 um diese Festlegung ergänzen).
- [~] wartet auf den nächsten Watchtower-Recreate als Gegenprobe und auf die Owner-Entscheidung zur Watchtower-Policy — **der Auslöser des täglichen Recreate ist identifiziert (2026-10-21):** es ist **Watchtower**, kein Cron und kein Timer. Belege (read-only auf dem Host): `docker logs --since 96h watchtower` zeigt `Stopping /portal_backend … Creating /portal_backend` am 2026-10-18, -19, -20 und -21 um 08:0x CEST; `crontab -l` kennt nur `volume-backup.sh`, `update_portainer.sh`, `dind-image-gc.sh`; `systemctl list-timers` zeigt keine passende Unit. **Die gefürchtete Folge tritt nicht ein:** `sync.sh:44` synct nach `/home/webadmin/websites/api-portal.reisinger.pictures/`, und `deployment/docker-compose.yml:97` mountet genau dieses Verzeichnis als `/var/www/html` (Bind-Mount) — Watchtower tauscht nur das Image, nicht den Anwendungscode; der UID-Stand `1000:82` (D-1) bleibt stabil (`docker inspect portal_backend --format "{{.Config.User}}"`). Offen: (a) die Gegenprobe beim nächsten Recreate (`docker inspect portal_backend --format "{{.Config.User}} {{.Created}}"`), (b) die Entscheidung, ob Watchtower für `portal_backend` abgeschaltet oder digest-gepinnt werden soll — er zieht derzeit das Tag `portal-base:8.5`, nicht einen Digest.
- [~] wartet auf die Owner-Entscheidung, ob die **Vertrauensgrenze der Proxy-Regel enger** gezogen wird —
  **der Rest ist gemessen (2026-10-22):** `trustProxies(at: 'REMOTE_ADDR')` vertraut den **direkten
  Peer**, und der legitime Schreiber des `X-Forwarded-For` ist allein Caddy (der FastCGI-Port 9000 ist
  nicht zum Host publiziert ✓). Aber: der Backend hängt am **extern geteilten** Netz `webret`
  (`ipv4_address: 172.18.0.31`) und an `portal_internal` — **jeder** Container dieser Netze kann den
  FastCGI-Port öffnen und damit den Header selbst setzen. Das Gewicht ist gering (es verschafft nur
  eine falsche Client-Adresse — keine Authentifizierung, keinen Datenzugriff ✓), und **nichts
  Vertrauenswürdiger schreibt den Header**: Caddy setzt ihn aus dem echten Peer und übergibt den vom
  Client gelieferten nicht. **Die Alternativen und ihre Preise:** (a) so bleiben (empfohlen — das
  Nageln auf eine Adresse bricht bei jedem Recreate des Caddy-Containers, und die Regel „vertraue den
  direkten Peer, lies die Kette von rechts" ist ohnehin die schwächere, aber stabile Zusage ✓); (b) den
  Backend aus `webret` nehmen und Caddy an `portal_internal` hängen (Compose-Änderung plus Recreate,
  betrifft `proxy-stack` und damit den Owner ✓); (c) einen geheimen Header von Caddy setzen lassen
  (`proxy-stack` ✓, Owner ✓) — der stärkste, aber auch der aufwendigste Weg ✓. **Bis zur Entscheidung
  gilt (a)**, und die Restlücke steht hier.

- [~] wartet auf die **Einrichtung der D-36-Ereigniskette in der Produktion** — **am 2026-10-21 auf
  dem Host gemessen: die Kette fehlt vollständig.** (a) Die SFTPGo-Instanz der Produktion hat **keine**
  Event-Action und **keine** Event-Regel (`GET /api/v2/eventactions` und `/api/v2/eventrules` liefern
  leere Listen; die konfigurierten Namen `portal-ftp-upload` / `portal-ftp-upload-rule` antworten mit
  404). (b) `FTP_EVENT_SECRET` ist im laufenden Container **leer** (`printenv | wc -c` → 1, also nur der
  Zeilenumbruch); der Wert ist laut `deployment/docker-compose.yml:215` ohne Default — ein leeres Secret
  laesst den Endpunkt jedes Event mit 401 abweisen (fail closed ✓) und den Provisionierungsbefehl
  abbrechen ✓. (c) Der SFTPGo-Container selbst ist gesetzt: 1 Konto, `status: 1` (aktiv).
  **Folge:** ein Kamera-Upload landet **ohne** Button nicht automatisch in der Zielgalerie — die
  D-36-Ereigniskette feuert nie. **Der Weg dorthin (Owner-Aktion, zwei Schreibvorgaenge auf dem Host):**
  `FTP_EVENT_SECRET` in der Portainer-Stack-Env setzen (nie ins Repo), den Backend-Container neu erzeugen
  (die Env wird erst dann gelesen), danach `php artisan ftp:provision-events` (idempotent: legt die
  Aktion an bzw. aktualisiert sie und die Regel) und die Regel mit
  `GET /api/v2/eventrules/portal-ftp-upload-rule` gegenlesen (`fs_events: ["upload"]`, Status aktiv).
  **Der Code-Pfad ist gemessen:** die Ereignisregel ist gegen ein laufendes SFTPGo verifiziert
  (`scripts/e2e-up.sh` provisioniert sie beim Test-Stack-Setup).
- [~] wartet auf den **Mail-Nachweis** — Queue, Worker und Scheduler sind am 2026-10-21 live belegt: die PID-Dateien `/tmp/portal-{scheduler,queue-supervisor,queue-worker}.pid` existieren, der stündliche Scheduler-Mutex in `cache_locks` trägt den Slot-Suffix `2100` (erworben um 21:00:02Z), der Wochenimport lief am Montag-Slot `03:30` (Meilisearch-Batch **2335**, Task 7200, `enqueuedAt` 2026-10-19T03:30:32Z, `status: succeeded`), der manuelle Lauf vom 2026-10-21 21:07Z erzeugte Batch **2402** (39/39 Tasks succeeded, 19.477 Orte im Index `locations`), und die Queue-Tabellen `jobs`/`failed_jobs`/`job_batches` sind leer und fehlerfrei. Die **SMTP-Duplikat-Policy** ist entschieden (D-17 in `features/owner-decisions.md`: gültige Tokens erhalten, kein pauschales Queue-Uniqueness, ein SMTP-Retry darf keine zweite Belastung oder Zustandsänderung auslösen). Offen: ein **zugestellter** Mail-Nachweis aus der Produktion — der Admin-SMTP-Test (`POST /api/management/settings/test-email`) muss ausgelöst und der Provider-/Mailpit-Log danebengelegt werden; die Produktions-DB führt keine Mail-Logtabelle, und `storage/logs/laravel.log` ist vom 2026-04-23.
## 🧪 TEST-QUALITÄTS-AUDIT (2026-09-26) — zwei unabhängige Audits

Scope: 84 E2E-Specs (191 `test()`, 278 Testinstanzen, 9.769 Zeilen) und 129
Vitest-Dateien (1.051 Tests) plus 2.440 PHPUnit-Tests. Zwei read-only Audits
auf getrennten Ebenen, beide mit der Auflage, Testnamen nicht für bare Münze zu
nehmen.

**Ergebnis vorweg: die Suite ist gesünder, als die Fragestellung nahelegte.**
77 % der E2E-Specs testen echte Use-Cases, 19 % Frontend-Features, 4 % gemischt.
Die Tag-Policy wird **ohne eine einzige Verletzung** eingehalten (190 von 191
`test()`-Aufrufen explizit getaggt; die eine Ausnahme nutzt eine berechnete
Variable, die regelkonform auflöst). Das Problem ist nicht die Ebene, sondern
**Tiefe und Redundanz**.

## 🔍 VOLLSTÄNDIGER HEAD-AUDIT (2026-09-24)

> Scope: vollständiger tracked Codebestand bei `HEAD=72f55da` (1.127 Dateien; 531 PHP, 376 TS/TSX, 78 E2E-Specs), nicht nur der letzte Diff. Der Working Tree war zu Auditbeginn sauber. Die Prüfung läuft als unabhängiger Full-Repository-Audit; Befunde werden erst nach Verifikation gegen Implementierung, Call-Path und bestehende Tests als Probleme dokumentiert.

**Review- und Remediation-Tracking**
**Post-Audit-Folgeentscheidungen (2026-09-24; in den Backlog-Wellen umzusetzen)**
- **CR-PAY-010:** vollständige serverseitige Idempotency-Key-Unterstützung für Invoice/Free/Quote ist als nächster Umsetzungsschritt ausgewählt.
- **CR-FE-030:** Meta-Gallery-Lizenzierung soll pro Child/Gallery-Gruppe aufgelöst werden; Tests und API/UI-Vertrag folgen in einem Folge-Change.
- **CR-CRM-008:** dauerhaft retrybarer Dispatch-/Outbox-Fallback für CRM-Dateisystem-/Scout-Cleanup ist ausgewählt; bestehende Jobs/Tabellen werden bevorzugt, eine neue V039+-Migration nur bei unvermeidbarem Schema-Defizit.
- **CR-DATA-018/CR-BE-018:** Contract-E-Mail-Uniqueness wird zuerst mit bestehenden Mechanismen/Schema geprüft; V039+ nur bei unvermeidbarem Bedarf.

**Backlog-Entscheidungen (2026-09-24; User)**
- **V035 bleibt unverändert** (Produktion). Neuere Migrationen dürfen nur fachlich passend konsolidiert werden; bestehende Migrationen/Strukturen sind bevorzugt. Eine neue V039+-Migration ist nur zulässig, wenn die Anforderung mit bestehenden Tabellen/Indizes/Job-Verträgen nicht sicher erfüllbar ist.
- **CR-CRM-008:** Durable Outbox-/Dispatch-Retry mit bestehenden Tabellen/Jobs bevorzugen; nur bei unvermeidbarem Schema-Defizit V039+.
- **CR-DATA-018/CR-BE-018:** Contract-E-Mail-Uniqueness zuerst mit bestehenden Mechanismen/Schema lösen; V039+ nur als letztes Mittel.
- **CR-PAY-013/CR-CODE-002:** Purchase-time-Organisationszuordnung ist beschlossen; Snapshot-/Migrationsauswirkungen vor Implementierung spezifizieren.

- [~] wartet auf die zwei verbleibenden Betriebsnachweise der Welle 4 — die **Plugin-Live-Runtime** (bisher nur der statische Harness `admin.lrplugin/tests/run.sh`; ein Lauf mit laufendem Lightroom Classic und installiertem Plugin steht aus) und **CR-INF-012** (der Live-Runtime-Nachweis). Erledigt und deshalb nicht mehr Teil dieser Position: der E2E-Harness (`tests/scripts/ftp-transport-test/`), Live-Smoke und die acht Deployments (mit `/up` → 200, Bundle-Hash und `migrate:status`), die GHCR-Pullability (`portal-base:8.5` pullbar, der laufende Container auf Digest `sha256:4e9533a8…`), die Dependency-Nachweise (Security-Contract- und Ratchet-Job grün) sowie die Secret-/Artifact-Hygiene.
## 🚀 EXECUTION PLAN — CURRENT SESSION (2026-09-25)

> Ziel: alle **actionable** TODOs in Abhängigkeitsreihenfolge abarbeiten. Historische `[x]`-Einträge werden nicht automatisch neu geprüft; stale/duplicate Findings werden durch einen aktuellen Reproduktionstest geschlossen oder als offen markiert. Der aktuelle Working Tree ist dirty; kein Checkbox-Status und kein Diff allein gilt als Verifikationsnachweis.

### 🆕 Validierung der Remediations-Welle `e5d20f0..2bfaed8` — 7 DeepSeek-v4.1-Reviewer

> **Methode:** 7 unabhängige read-only DeepSeek-v4.1-Reviewer über die gesamte Welle (664 Dateien, ~76k Zeilen). Ergebnis: **65 Befunde, 0× P0, 9× P1, 56× P2.** Authorization/Brand/Gallery-Tree/Media-Delivery als **READY** beurteilt (0 P0/P1; 6 Verdachtsmomente aktiv verworfen). Umsetzung in 5 nicht-überlappenden Workstreams, je mit separatem Verifier.

#### P1


#### P2 (Details je Workstream)

<details><summary>INFRA (10)</summary>

</details>

<details><summary>AI/Media/Storage/Payouts (8)</summary>


</details>

<details><summary>Contracts/Pricing (6)</summary>

</details>

<details><summary>Frontend (7)</summary>

</details>

<details><summary>Checkout/Payments (5)</summary>

</details>

<details><summary>Authorization/Brand (5, alle P2 — Bereich READY)</summary>


</details>

<details><summary>Tests/Dokumentation (15)</summary>

## 🛑 SESSION-HANDOVER (2026-09-25, Ende der Arbeitssession)

**Ergebnis in einem Satz:** 13 verifizierte Commits sind auf `main`; die verbleibenden Arbeitsstränge sind mit **unterschiedlichem Nachweisgrad** committed (2× fertig, 3× halb fertig), und die CI ist aus **genau einem** externen Grund rot.

### Commit-Stand
| Commit | Inhalt | Nachweisgrad |
|---|---|---|
| `f659de3`…`aa6dafd` | CI/Plugin, Frontend, Backend/Contract/V039, AI, Cleanup/Scout, Quote-Mail, Payout/V040 | **verifiziert** (unabhängige Verifier + grüne Suite 2349–2365 Tests) |
| `d7f3596` | Image-Namespace `reisinger-pictures` | verifiziert, **löst aber den GHCR-Blocker aus** |
| `aac83b4`, `70185eb` | GHCR-Blocker + P1-I5-Korrektur | Doku |
| `cf5e8fc`, `2bfaed8` | Gallery-Traversal/Status-Guard, Brand-Invariante (fail-closed) | **verifiziert** |
| `8cc3fcb` | Non-Root-Image-Gate | verifiziert (Fail-the-Guard belegt) |
| P1-M11 (`id` raus aus `$fillable`) | `Photo::createWithId()` | **FERTIG** — 275 Tests/996 Assertions + 46 Proben, PASS |
| Flake A+C (`attempts 0!==1`, `ai_img_*`-Glob) | Test-Härtung + injizierbares Temp-Prefix | **FERTIG** — serial 10/10, parallel 5/5, Ballast 25/25, 2× Revert-to-red |
| exiftool-Testbudget | explizites Budget + 1 Retry | **HALB** — Testmitigation fertig, **Produktionspfad ungefixt** |
| `preset_id`-Vertrag + GalleryModal-Zod-Bug | Integer-Kontrakt beidseitig | **HALB** — Frontend belegt, **Backend und 4 E2E-Specs unbelegt** |
| `SidebarHelper` + `E2E_SKIP_DOCKER_SERVICES` | kein Mobile-only-Gate mehr | **HALB** — Unit 4/4, **kein E2E-Lauf** |

### ⚠️ Umgebung während der Session verloren — nicht reproduzierbar
`/usr/local/bin/meilisearch`, `/usr/local/bin/mailpit`, `/usr/bin/exiftool`, `/usr/bin/pgrep`, `/usr/bin/ss` sowie die aus PHP-8.5.10-Quellen gebaute `gd.so` sind **alle weg**; `/tmp` wurde geleert. Das native Setup hat **nicht persistiert**. Konsequenz: **im aktuellen Zustand ist keine Testausführung möglich**; alle nach dem Verlust gemessenen Zahlen sind entsprechend zu behandeln. Vor jedem Resume müssen GD, exiftool sowie ein host-erreichbares Meilisearch + Mailpit wiederhergestellt werden.

### ⛔ Einzelner CI-Blocker
Beide Org-Pakete sind `private`; `backend` und alle 7 E2E-Jobs scheitern mit `unauthorized` beim Image-Pull, das neue Non-Root-Gate zusätzlich mit HTTP 401. `Frontend (Lint, Build, Vitest)` bleibt grün. **Owner-Aktion:** `portal-base` und `portal-e2e` auf `public` — https://github.com/orgs/reisinger-pictures/packages/container/package/portal-base (analog `portal-e2e`).

### Offene Defekte (bewusst nicht gefixt)
- **`CrmCleanupDispatchFallbackTest`** (6 `queue:work`-Aufrufe: 215/299/370/384/416/552) teilt exakt den Defekt, der bei A behoben wurde: unguarded `--memory=128` bei In-Process-Worker. Dieselbe 2-Zeilen-Fix + Exit-Code-Assertion fehlt dort.
- **Produktions-Exiftool-Timeout**: `PhotoDownloadController.php:225/228`, `ImageProcessor.php:254/401`, `PhotoProcessingService.php:85` rufen `Process::run()` ungeschützt mit 60 s Default. Auf einem überlasteten Host wird ein Download zum 500.
- **P1-M11 D1**: die bewusst erhaltene `captured_at`-Divergenz (FTP persistiert, HTTP nicht) hat **keinen Repo-Test** — ein künftiges `captured_at` in `$fillable` würde stillschweigend greifen.
- **P1-M11 D2**: `createWithId()` koppelt `PhotoProcessingService` per Ausnahmeliste; ein neues EXIF-Feld wirft `InvalidPhotoIdentifierException`, `FtpController` behält die Datei → der Import-Poll klemmt dauerhaft.
- **P1-I5**: Non-Root-Eigenschaft des gepinnten `d762d47c` ist **unverifiziert** (Pull 401). Das Gate erzwingt den Nachweis, sobald die Pakete public sind.
- **`preset_id`-Backend**: 3 neue PHPUnit-Tests wurden **nie ausgeführt**. `features/infrastructure/27-volume-licensing-presets.md:55` dokumentiert den numerischen Vertrag noch nicht.

### Umgebungsfallen
1. Nach jedem `gh auth refresh` muss `gh auth setup-git` laufen, sonst scheitert `git push` an `could not read Username`.
2. `concurrency: cancel-in-progress: true` in `ci.yml` **vernichtet den Log eines laufenden Laufs**, sobald gepusht wird. Beim Debuggen nicht währenddessen pushen.
3. Die Platte war zweimal über 90 % voll (fremde Projekte: `LuminaRust` 163 GB, Docker-Volumes 82 GB). Volle Testläufe brechen dann mit `No space left on device` ab.
4. `backend/tests/Fixtures/sample.jpg` wurde von einer parallelen Session **gelöscht** und aus dem Git-Objekt wiederhergestellt; `git status` ist für die Datei clean.

### Abbau dieser Session (durchgeführt)
Docker-Container `e2e-head`, `e2e-ci`, `e2e-mariadb`, `e2e-meili`, `e2e-mailpit`, `e2e-pf`, `e2e-fix`, `pf-mailpit`, `pf-meili`, `pf-mariadb`, `pf-meili-local`, `seed-ws-pf` sowie Volumes `ws-head`, `ws-ci-base`, `ws-pf`, `ws-fix` und die Netze `e2e-net`, `pfnet` entfernt; Host-Dienste Meilisearch/Mailpit gestoppt; Worktree `portal-staged-verify` geprüft (`git diff --cached main --diff-filter=A` leer ⇒ kein Unique-Work) und entfernt; Scratch unter `/tmp` bereinigt.

### Gate 0 — Baseline und Änderungsdisziplin
- [~] wartet auf die zwei verbleibenden Betriebsnachweise — die **Secret-Rotation** von `AI_API_KEY` und `ADMIN_PASSWORD` (das Wertepaar stand beim Auslesen der aufgelösten Compose-Datei im Klartext im Terminal) und die **Plugin-Live-Runtime**. Erledigt und aus dieser Position entfernt: GHCR-Pullability (beide Pakete `public`), die Deployment-Nachweise (acht Deploys mit Live-Messungen), die Storage-Nachweise (`storage/app/private` unversehrt) und der Scheduler-Nachweis (live am 2026-10-21: PID-Dateien, Mutex-Slot, Wochenimport; siehe `features/infrastructure/29-production-operations-runbook.md` §5).
- [~] wartet nur noch auf die **betrieblichen** Live-GO-Punkte — die Radar-/3DS-/Webhook-/Privacy-Checkliste
  (Stripe-Dashboard, `features/security/card-testing-protection.md`) und den **Test der Monitoring-Alarme**
  (in `features/security/card-testing-runbook.md` §1–§3 beschrieben, aber nie ausgelöst).
  **Erledigt und nicht mehr Teil dieser Position:** der Contract-/Card-Testing-Review (14 Befunde SEC-01…SEC-14,
  `features/security/2026-10-18-review-closure.md`), das Monitoring-Runbook (§1–§3), der Rollback-Ablauf ohne
  Datenverlust (§6, 2026-10-21) und die unabhängige Verifikation der Card-Testing-Implementierung (eigene
  Position unten).
- [~] wartet nur noch auf die **Operations-Evidenz** (die Mail-Zustellung, siehe die Position zum Queue-/Mail-/Worker-/Scheduler-Nachweis) — die Code-Subblöcke R1–R7 sind verifiziert geschlossen, und die **Product Decisions** sind entschieden (D-17 „Duplicate mail and prompt-injection policies“ in `features/owner-decisions.md`, umgesetzt in `98766c14`). Die im alten Text genannten aktiven Bugs sind heute durch Tests abgedeckt: Session-Header-Validierung (`AISessionHeaderDeploymentPolicyTest`), Bild-Budget (`ModelContactSheetTest`, `ModelContactSheetBulkTest`), Cleanup-Dauerhaftigkeit (`GalleryPhotoCleanupDurabilityTest`, `CleanupGalleriesFailureTest`), Temp-Löschung (`AiTempFileSweepAgreementTest`, `WatermarkMarkerCleanupTest`), Mail-Verlust bei Quotes (`WebhookInvoiceMailAtomicDedupeTest`, `WebhookReplayMailTest`), fehlerhafte Provider-Antwort (`StripeWebhookTest`, `WebhookNullPiTest`), Reihenfolge beim manuellen Löschen (`ManualInvoiceNumberValidationTest`, `GalleryUpdateDeleteTest`).
## Model-Registrierung (Magic-Link) + Profile-Iteration (2026-09-18/19 deployiert) — OFFENE Restarbeiten

> Plan: `~/.opencode/plan/model-registrierung.md` · SOLL: `features/crm/05-model-registration.md` + `features/crm/06-model-profile-iteration.md`.
> Admin lädt eine Managerperson per kopierbarem Magic-Link ein (Mail optional); diese registriert login-frei einen **Act** mit 1..n Personen (je Person = CRM-Customer + ModelProfile). Altersnachweis **immer Pflicht**. Fragenkatalog **Code-first + Answers-Snapshot**.
> **Status:** deployed (Commit `3db7437`, push + Redeploy done). Backend **1577** PHPUnit, Frontend **706** Vitest, Lint/Build grün, **12** Feature-E2E.
> **Nachtrag 2026-09-19 (committed in `6137d3a`):** Profil-Update-Mail + Contact-Sheet-Export (Backend+Frontend) + E2E-Ausbau. Backend **1587** PHPUnit, Frontend **722** Vitest, E2E-Grep (`model-registration|model-access|model-export`) **22** passed — alles READY-verifiziert.
> **Nachtrag 2 (2026-09-19, committed in `e5d20f0`):** N1 (Manager-Transfer + Nachfolge + V035) + N2 (Inaktive nur Super-Admin, 403 fail-closed). Backend **1596**, Vitest **726**, E2E-Grep **28/28** — READY-verifiziert.

**Backend (PHPUnit) — implementiert & verifiziert**
**Frontend (Vitest + Playwright) — implementiert & verifiziert**
**Offen (User-Entscheidungen / Nachträge)**
- Hinweis (Setup): lokale `backend/.env` braucht `MODEL_REGISTRATION_THROTTLE_LIMIT=1000` (Parität zu `.env.ci`), sonst 429-Flakes im E2E-Grep-Lauf. `.env` ist gitignored.

**Model-Zugang (User-Anforderung 2026-09-19) — umgesetzt**
---

## E2E-Ausbau Runde 2 (Model-Registrierung/-Zugang, 2026-09-19) — Verifikationslauf erledigt, Restarbeit OFFEN

> Auftrag: Lücken der Runde-2-Features mit **echten Nutzer-Interaktionen** schließen (semantische, gescopte Locators, Tags, kein `page.goto`-SPA-Missbrauch, keine localStorage-Injektion).
> **Historischer Verifikationsstand:** `pnpm vitest run` **706 passed**, `pnpm lint:fix` 0, `pnpm build` grün; `npx playwright test --grep "@feature:model-registration|@feature:model-access" --workers=1` **20 passed** (Desktop + Mobile). Dieser Lauf betraf die E2E-Erweiterung; Backend-Code blieb unverändert.

**Neue/geänderte Tests**
**Gefundener & gefixter Frontend-Bug (durch den neuen E2E-Test aufgedeckt)**
**Bewusst ausgelassen (begründet)**
- **Age-Proof-Positivfall (Re-Upload ohne vorhandenen Proof):** nicht ohne Backend-Eingriff erzeugbar. `ProfileEditForm` blendet das Feld nur bei `profile.age_proof_required && !profile.age_proof_uploaded_at` ein; das öffentliche `POST /api/model-registration/{token}` erzwingt den Nachweis (`required`, v2), und der Owner-`POST /api/model-profil/{token}` setzt `age_proof_required=true` + die Datei (beim Bestehen bleibt `age_proof_uploaded_at` gesetzt). `age_proof_path === null` bei `age_proof_required === true` ist damit nur über Altbestände/einen direkten DB-Reset erreichbar — ein solcher Zustand existiert produktionsseitig nicht regulär.

**Umgebungs-Hinweise (kein Code-Delta)**
- Lokal scheiterte der Lauf zunächst an `429 Too Many Attempts`: das (gitignored) `backend/.env` hat **keinen** `MODEL_REGISTRATION_THROTTLE_LIMIT` → Limiter-Default 10/min. CI setzt in `backend/.env.ci` `MODEL_REGISTRATION_THROTTLE_LIMIT=1000`. Für die Verifikation wurde `.env` temporär auf 1000 gesetzt und danach **byte-identisch wiederhergestellt** (md5-geprüft).
- `pnpm test:e2e:smoke` (ohne `--workers=1`) verzeichnet die bekannte lokale SQLite-Flakiness `database is locked` (siehe Offen N3) — unabhängig von dieser Änderung.

---

## CODE REVIEW (2026-09-12) — Full-Main-Audit (9 Subareas) — umgesetzt; zwei Befunde 2026-09-24 wiedereröffnet

> Methodik: 9 read-only Subagenten über Backend (Auth/Security, Checkout/Payments, Controllers/Requests, Modelle/Data, AI/Mail/Jobs), Frontend (Logic, UI), Infra/CI, Lua/Tests. Fixes durch **separate** Implementer-Subagenten, nie der Reviewer.
> **Status (2026-09-12):** Alle P0- und die meisten P1-Findings umgesetzt + getestet. **Verifikation:** Backend `php artisan test` **1392 passed / 0 failed (3452 Assertions)**; Frontend `pnpm test:run` **628 passed**, `pnpm lint:fix` 0, `pnpm build` grün.
> **Entscheidungen (User):** Brand-Isolation **strikt** (nur `brand=null` = cross-brand, z. B. erster/Super-Admin; brand-gebundene Admins isoliert); **100%-Coupon → freie Orders erlaubt** (kein Stripe-Call bei 0, Status `paid`, Download frei); **Teil-Refund behält Zugriff** (nur Voll-Refund entzieht); **kein VAT** (Kleinunternehmer/§6 UStG bzw. Reverse-Charge → aktuelles Verhalten korrekt); `.env.production` **bleibt machine-local/untracked** (`APP_DEBUG=false` gesetzt); **keine Playwright-Trace-/Report-Uploads auf PRs**; **Lua-Passwort verschlüsselt** via `LrPasswords` (OS-Keychain, Klartext migriert + entfernt); **Contract-Signer-Magic-Link = by design** (Signer haben kein Konto).
> **Offen (Rest):** P2-Tests/Doku (E2E-Tags/serielle Suite-Isolation/Kanban-Flakiness), SHA-Pinning von Actions/Images, `Photo::$fillable 'id'` (bewusst **nicht** gefixt: `ImageController` schreibt Datei unter vorab generierter ID → Kopplung), `PricingService::calculateItemPriceCents` (bewusst behalten, öffentliche Preview-API + 20 Tests), gleich-brand Group-Ownership (Produktentscheidung), Frontend-Low-Hygiene (F10–F12 teils).
> **Regeln:** Jeder Fix braucht einen Regressionstest (DoD, Bugfix = mind. 1 Test). Backend-Fix gilt nur mit grünem `php artisan test`; Frontend mit `pnpm test:run` + `lint:fix` + `build`.
> Priorität: **P0** = Security/Geld (kritisch/hoch), **P1** = funktionale Bugs, **P2** = Härtung/Hygiene.

### P0-A — Brand-Isolation (Kernursache, Backend) — geschlossen 2026-09-28 (P0-A13 als Nicht-Leak nachgewiesen)

> **Was hier noch steht — und was nicht:** Die behobenen Findings des
> 2026-09-12-Audits sind am 2026-09-28 entfernt worden (§3); sie stehen in den
> Commits, die sie geschlossen haben. Der wiedereröffnete Befund `P0-A13` ist am
> 2026-09-28 **als Nicht-Leak nachgewiesen und geschlossen** — dieser Block ist damit
> leer. Es gibt hier **keine `[x]`-Einträge**;
> die frühere Konvention, mit der ein Kästchen hier Erledigung signalisierte, wird
> nicht mehr verwendet.

> **P0-A13 — kein Leak, geschlossen (2026-09-28).** Der historische Leak war real:
> ein Fotograf **ohne Zuweisung** auf einer öffentlichen `restricted_photographers`-Galerie
> bekam das Original, weil der alte Zweig rein über die Rolle entschied
> (`$user && ($svc->isAdmin($user) || $svc->isPhotographer($user))`). Behoben in
> `f21d78d` durch den Weg über `canManageGallery`, das an
> `canPhotographerAccessGallery` delegiert und damit `restricted_photographers`
> durchsetzt. **Nachträglich mit einem Test festgenagelt**, den es vorher nicht gab:
> `FileDeliveryControllerTest::test_unassigned_photographer_on_public_restricted_gallery_gets_watermark_not_original`
> — mit dem historischen Code rot (403 erwartet, 200 erhalten), mit dem heutigen grün.
> **Der verbleibende Weg, auf dem ein Gast ohne Auth das unmarkierte Original bekommt,
> ist `effective_is_free_download` — und der ist gewolltes Produktverhalten**, nicht
> Leck: `GalleryModal.tsx:324` („Deaktiviert Wasserzeichen … Direkter Download für
> Gäste") und `features/delivery/03-file-delivery-controller.md:60`. Der dritte Test
> grenzt das sauber ab: free_download umgeht die Wasserzeichen-Pflicht, **nicht** die
> Authentifizierungspflicht einer privaten Galerie.
> **Die Zeilenanker `:35,54-72` im alten Eintrag waren verrottet** — sie passten weder
> zur heutigen noch zur historischen Datei. Künftig nach Symbol benennen
> (`$logicalNeedsWatermark`, `canManageGallery`), nicht nach Zeile.

### P0-B — Checkout/Payments (Geld) — geschlossen 2026-09-28 (P0-B7 als Nicht-Leak nachgewiesen)

> **Was hier noch steht — und was nicht:** Die behobenen Findings des
> 2026-09-12-Audits sind am 2026-09-28 entfernt worden (§3); B16 ist über die
> dokumentierte Entscheidung „kein VAT" fachlich geprüft. Der wiedereröffnete
> Befund `P0-B7` ist am 2026-09-28 **als Nicht-Leak nachgewiesen und geschlossen** —
> dieser Block ist damit leer. Es gibt hier **keine
> `[x]`-Einträge**.

> **P0-B7 — nicht reproduzierbar, geschlossen (2026-09-28).** Die Antwort kann keinen
> fremden `personal_token` tragen: der 201-Body liefert ausschließlich den Token des
> **in diesem Request erzeugten** Signers. Eine Kollision auf
> `(join_scope_key, normalized_email)` scheitert geschlossen mit generischem 409
> **ohne** Token, Namen oder Rollen — in beiden Schreibpfaden
> (`ContractJoinController` und `ContractTemplateService`) und zusätzlich über den
> V039-Unique-Index. `ContractSigner::$hidden` lässt `personal_token` außerhalb der
> Join-Antwort gar nicht erst serialisieren. Festgenagelt in
> `ContractJoinTest::test_standard_join_never_returns_a_pre_existing_signers_personal_token`
> und der Template-Variante.
> **Der einzige verbleibende Spalt ist die bewusst ungeprüfte E-Mail-Zugehörigkeit** —
> eine akzeptierte Produktentscheidung (CTR-7, `ContractJoinTest.php:121`): wer einen
> gültigen Join-Link hält, darf unter einer noch nicht vertraglich gebundenen Adresse
> beitreten und bekommt den Token inline. Das war nie der gemeldete Befund, und es ist
> dokumentiert entschieden, nicht übersehen.

## 🚫 Blockiert — Dependency-Migration TypeScript 6→7

- [~] wartet auf TypeScript-7-Support im Tooling. **Nachzuziehen, sobald das Tooling
  TS7 deklariert.** TS 7.0 ist zu frisch: kein Support durch die
  Vite/Rolldown-Babel-Pipeline, den ESLint-Typescript-Stack oder das
  React-Compiler-Preset.
  **Verifiziert 2026-09-28, unverändert seit 2026-08-25:** `frontend/package.json`
  deklariert `"typescript": "^6.0.3"`, installiert ist `6.0.3`
  (`node -p "require('./frontend/node_modules/typescript/package.json').version"`).

---

## 🟡 Card-Testing-Schutz (SOLL: V036, 2026-09-24) — implementiert; BETRIEB, ROLLOUT & FINAL REVIEW OFFEN

> Approved architecture: `features/security/card-testing-protection.md`. V036 und die zugehörige Implementierung sind im aktuellen Working Tree vorhanden. Der CI-Lauf `35917265654` auf Commit `e73d5cf` ist ein **historischer Verifikationsdatensatz**; er liegt vor den aktuellen uncommitted Änderungen und ist kein Nachweis für diesen vollständigen Working Tree. Eine lokale E2E-Ausführung wurde in diesem Docs-only-Pass nicht gestartet. Betriebs-, Rollout- und Final-Review-Gates bleiben ausdrücklich offen; diese Dokumentationskorrektur behauptet keine neuen Application-Tests.

**Offene Punkte:** Die Positionen stehen im Board an zwei Stellen — die betrieblichen Live-GO-Punkte
(Stripe-Dashboard-Checkliste, Monitoring-Alarm-Test) und die unabhängige Verifikation. Der unabhängige Diff-Review
vom 2026-10-22 ist mit **APPROVED MIT AUFLAGEN** in `features/security/card-testing-protection.md` (§ Status) protokolliert;
die Auflagen, die ohne den Owner umsetzbar waren, sind am 2026-10-22 geschlossen.



## Produktionsdeploy SFTPGo — Vorfall vom 2026-09-26 (abgeschlossen)

Der Cutover auf SFTPGo hat die Produktion am 2026-09-26 mehrfach lahmgelegt.
Ursache war nicht SFTPGo, sondern Compose v5.0.2. Drei davon gefundene Fehler,
alle mit Regressionstest:

**Weiterhin offen (bewusst):**

- [ ] manuell prüfen: **erstes Kamera-Foto über FTPS/SFTP hochladen** und prüfen,
  dass es **ohne** Button in der Zielgalerie landet — der Import läuft über die
  D-36-Ereigniskette (`FtpInboxImporter::importBatch()`, `FtpController.php:194`; die
  alte Nennung `FtpController::process()` ist überholt, `runImport()` ist heute privat).
  Drei Auslöser gibt es: das SFTPGo-Ereignis, der Ketten-Nachlauf und der Button.
  **Vorher maschinell:** `php artisan ftp:provision-events --dry-run` — die Ereignisregel
  wurde nie gegen ein laufendes SFTPGo verifiziert. **FTPS-Fallback:** das Zertifikat
  liegt als `/var/lib/sftpgo/ftps/cert.pem` im Container, muss aber auf die Speicherkarte
  als **`ROOT.PEM`** kopiert werden — Canon importiert nur `ROOT.CER` / `ROOT.CRT` /
  `ROOT.PEM` und überspringt `cert.pem` still (`deployment/kamera-einrichtung.md:157-168`).
  **Firewall-Gegenprobe (10 Sekunden, kein Owner-Task) — am 2026-10-21 gemessen und erledigt:**
  `firewall-cmd --zone=public --list-ports` → `989/tcp 2222/tcp 50000-50100/tcp` (seit dem
  2026-09-27 gesetzt, `deployment/README.md:187-193`), 8080 bleibt zu; der Host nutzt
  **firewalld**, nicht `ufw`. Gegenprobe auf dem Host: `ss -lntp` zeigt 2222 und 989 als
  `docker-proxy` (IPv4 und IPv6), der TCP-Handshake gegen 127.0.0.1 gelingt für 2222 und 989
  und **schlägt für 8080 fehl** (erwartet); die passive Range 50000-50100 wird von mehreren
  `docker-proxy`-Prozessen bedient. Damit ist der Firewall-Stand gemessen; offen bleibt nur
  der Kamera-Upload selbst.
- [ ] manuell prüfen: `AI_API_KEY` und `ADMIN_PASSWORD` im Portal-/Host-Secret-Store rotieren — beide sind beim Auslesen der aufgelösten Compose-Datei im Klartext durch ein Terminal gelaufen. **Die Rotation selbst bleibt eine Owner-Aktion; das Runbook dafür steht jetzt:**
  `features/infrastructure/29-production-operations-runbook.md` §7 „Rotating a secret after an exposure" —
  die Reihenfolge (Wert auf dem Host erzeugen und direkt in die Stack-Env schreiben, Container neu starten bzw.
  neu erzeugen, mit einer **Länge** statt mit dem Wert verifizieren, die Funktion prüfen, den alten Wert beim
  Anbieter widerrufen, Nachweis ohne Wert protokollieren) und die drei Dinge, die nicht passieren dürfen (der Wert
  im Chat oder im Log, ein Test des alten Werts nach dem Neustart, eine Rotation „beiläufig" in einem Deploy).
## Nachtrag 2026-09-26, Kamera-Setup aus der Oberfläche bedienbar machen

**Offen (aus dieser Runde):**

## UI-Review 2026-09-26 — Screenshot-Verifikation der neuen Oberflaechen

Erstmals das Screenshot-Harness auf die neuen Oberflaechen angewendet
(`tests/screenshots/`, Manifest um `kamera-einrichtung` und
`photographer-dashboard` erweitert, inkl. Fotografen-Auth). 4 Aufnahmen
(je Desktop/Mobile), alle Seiten rendern korrekt, keine kritischen Befunde.

| Schwere | Stelle | Befund | Vorschlag |
|---|---|---|---|
| medium | `KameraEinrichtung.tsx` (Schritt 2) | Der Guide nennt **„Neues Kamera-Passwort"**, aber der Button heißt fuer `pending` **„Kamera-Zugang einrichten"**. Ein Fotograf, der dem Guide folgt, sucht einen Button, den es nicht gibt — und genau diese Diskrepanz hat den Slug-Wechsel-Blockade beheben sollen. | Beide Labels nennen, oder den zustandabhaehngigen Text erklären. **Erledigt 2026-09-26: der Guide nennt jetzt den pending-Button „Kamera-Zugang einrichten".** |
| medium | `ManagementFtpInbox.tsx:57` | **„Fuer FTPS ist kein Verschluesselungsmodus hinterlegt — bitte den Support kontaktieren"**. Wird nur angezeigt, wenn der Modus fehlt (in Produktion gesetzt). „Support kontaktieren" ist fuer einen Fotografen eine Sackgasse: er kann eine Server-Konfiguration nicht aendern. | Neutralere Formulierung ohne Support-Weg, z. B. was fehlt und warum. **Erledigt 2026-09-26: neutrale Formulierung „Ohne diesen Modus kann die Kamera FTPS nicht aushandeln — nutze daher SFTP" (Inbox und Guide).** |
| low | `photographer-dashboard` (Mobile) | Der lange Upload-Ordner-Pfad (`ftp_folder`) bricht ueber **drei Zeilen** um und quetscht die Karte | Zeilenumbruch kontrollieren (`break-all` nur fuer lange Pfade) oder kuerzer anzeigen. **Erledigt 2026-09-27: `break-all` + `text-base md:text-lg` in `ManagementFtpInbox.tsx`, linke Spalte `min-w-0` — der Pfad bricht jetzt kontrolliert in zwei Zeilen.** |
| low | Header (Mobile) | **„Reisinger Fot…"** abgeschnitten | Vorbestehend, nicht von dieser Runde — nur vermerkt. **Erledigt 2026-09-27: Root Cause war eine dreifach duplizierte Kopfzeile — nicht der zuerst gefixte `GlobalSearchHeader`. Siehe Folge-Runde unten.** |

**Nicht Beanstandungen:** Die Verbindungstabelle rendert auf beiden Viewports sauber;
die Status-Warnung (`pending`) und der Button sitzen in einer Zeile; die
Abschnitte des Guides sind klar getrennt. Das FTPS-Modus-Problem und der
Button-Name sind **eigenen** Fuerke, keine vorbestehenden Maengel.

**Folge-Runde 2026-09-26 (die drei Kernbefunde des Owners umgesetzt):**
**Folge-Task (Harness-Luecke) — erledigt 2026-09-27:**
**Folge-Runde 2026-09-27 (die zwei `low`-Befunde umgesetzt):**
## Dialog-Screenshots 2026-09-27 — Abdeckung, Blocker und tote Stellen

Ziel: **jeder Dialog ist bildgeprueft**, nicht nur funktional getestet. Die
Kamera-Anleitung war der Anlass — genau diese Lqecke (kein `click`-Nav-Schritt)
hat den Dialog-Test erzwungen. Bestand (Inventur, gegen den Code geprueft):

| Kategorie | Anzahl | Bemerkung |
|---|---|---|
| `ModalShell`-Familie (inkl. `ModalDialogShell`) | 18 | davon 3 keine eigenen Dialogflaechen (Shared Contract, Form-Wrapper, Inhalt der FTP-Anleitung) und 1 bereits erfasst (`photographer-guide-dialog`) |
| davon neu erfassbar mit vorhandenen Seeds | 4 | `shooting-calculator`, `photo-job`, `volume-preset`, `model-detail` |
| davon neu erfassbar mit neuem Gallery-Seed | 8 | Zugriff, Einladungslink, Bewertungen, Metadaten-Vorgaben, Fotografen-Team, E-Mail, Galerie bearbeiten, Meta-Galerie bearbeiten |
| strukturell nicht erfassbar | 2 | `AIGalleryDefaultsModal` (nur aus einem anderen Dialog heraus) und der globale Bestaetigungsdialog (programmatisch, kein `click`-Target) |
| Roh-Dialoge ohne `ModalShell` | 11 | 6 mit `role="dialog"`, **5 ohne** — die brauchen ein `data-testid`, bevor `waitFor` sie ueberhaupt greifen kann |

## Dialog-UI-Review 2026-09-27 — Auswertung aller 13 Dialog-Aufnahmen

Vollauf `pnpm test:screenshots`: **44 passed / 0 failed (1,6 min)**, 13 Dialoge ×
Desktop/Mobile. Auswertung in zwei Hälften (5 + 8 Dialoge) gegen
`ui-review-checklist.md`, danach **jeder high- und medium-Befund von mir am Bild
nachgeprueft**. Protokoll: 26 Bilder, 38 Befunde, davon **6 bestaetigt, 1
widerlegt**, Rest low. Widerlegt heisst: ein plausibler Scheinbefund, der als
Produktionsfehler verkauft worden waere.

| Befund | Schwere | Von mir geprueft |
|---|---|---|
| `gallery-edit-dialog`: Dialog hoeher als der Viewport, Footer mit „Speichern" abgeschnitten (Desktop + Mobile) | **high** | **bestaetigt** — Desktop zeigt nur einen ~10 px-Streifen der Footer-Buttons, der Primaer-Button ist ohne Scrollen unerreichbar |
| `gallery-invite-dialog` (Mobile): zwei Optionsbeschreibungen verlieren Text am Kartenrand, „durchgewun" endet mitten im Wort — kein Umbruch, kein Ellipsis | **high** | **bestaetigt** — Textverlust; die dritte Karte daneben bricht korrekt um |
| `gallery-access-dialog`: letzte Listenzeile mitten durch die Glyphen abgeschnitten, keine Scroll-/`Fade`-Affordanz | **medium** | **bestaetigt** |
| **Waehrung mit Punkt-Dezimaltrennzeichen** (`369.00 €`, `30.00`) in durchgehend deutscher Oberflaeche | **medium** | **bestaetigt als echter Produktionsfehler** |
| `gallery-metadata-defaults` (Mobile): Fusszeile stapelt vollbreit | — | **bestaetigt korrekt** — das ist das richtige Mobile-Muster |
| `Hinzufügen`-Button zentriert gegen den Name/E-Mail-Block statt auf der Namenslinie | low | **bestaetigt** |
| `model-detail` (Mobile): „Altersnachweis"-Karte 48 % breit neben 100 % breiter „Datenstand"-Karte | low | gemeldet |
| `model-detail`: „Profil-Angaben" / „Basisdaten" typografisch identisch, Hierarchie geht verloren | low | gemeldet |
| `volume-preset`: `€` ist Flex-Geschwister statt Feld-Suffix, Input dadurch schief | low | gemeldet |
| `mm/dd/yyyy` im nativen Datumsfeld | medium | **WIDERLEGT — Harness-Artefakt**, siehe unten |


**Umsetzung 2026-09-27 (alle gegen frische Aufnahmen verifiziert):**
## CI-Befund 2026-09-27 (Push fa8e19d) — drei CI-Ursachen, alle belegt; danach sechs Dialog-Befunde aus derselben Welle

**1. `Frontend (Lint, Build, Vitest)` rot am Schritt „Build" (Lint war gruen).**
`check-i18n.mjs` schlug auf einem **unberuehrten** Checkout fehl. Ursache: Ich
habe `FtpConnectionRows.tsx` selbst geaendert (den Support-Text) und mit
`git add frontend/` committet, **ohne den Lingui-Katalog neu zu erzeugen** — die
Quelle hatte den neuen Text, das committete `messages.js` noch den alten.
**Regel daraus: wer einen UI-String aendert, erzeugt den Katalog im selben
Commit neu** (`pnpm lingui:extract && pnpm lingui:compile`). `pnpm build`
allein genuegt nicht — es laeuft `extract`, aber nicht `compile`.

**2. Sechs E2E-Tests rot, jeweils auf allen drei Retries** (kein Flake) — nicht
drei, wie es zuerst aussah: `magic-link.spec.ts:22` und `:74`,
`client.spec.ts:26`, `photographer/communication.spec.ts:93`,
`selection/photoswipe.spec.ts:24`, `selection/rating-regressions.spec.ts:10`.
Alle neun Fehlschlaege (6 Tests × 3 Retries, Desktop 2/3 + Desktop 3/3 + Mobile
3/3) mit derselben Ursache:
`strict mode violation: .modal-open >> getByRole('button', {name:'Schließen'})
resolved to 2 elements`. Ursache: die Migration der `InviteModal` vom
handgebauten `modal modal-open` (unbenannter `✕`) auf `ModalShell`
(`aria-label="Schließen"`, `ModalShell.tsx:186`) hat den Schliessen-Button
**benannt**, waehrend der Footer denselben Text traegt. **Alle sechs schliessen
dieselbe `InviteModal`** — ich hatte aus dem Shard-2/3-Auszug geschlossen, die
drei anderen Specs betroffen nicht.
**Die UI wird NICHT geaendert:** `ModelDetailModal.tsx:316-324` dokumentiert die
Doppelung ausdruecklich als Entscheidung — ein Kopf- und ein Fuss-Schliessen
gehoeren zusammen, und den Fuss-Button zu entfernen, damit ein Locator
eindeutig wird, waere eine Bedienmoeglichkeit fuer den Test geopfert. Die
Migration hat schlicht ihre Specs nicht mitgezogen; die vier CRM-Specs scopen
deshalb bereits auf `.modal-action`. **Regel daraus: eine Dialog-Migration
zieht alle Specs mit, die auf den Dialog schliessen** — und wer eine
Fehlermeldung sieht, muss den **ganzen** Lauf lesen, nicht den Shard, in dem
sie gelandet ist.

**3. Fast ein dritter Fehlschluss beim Diagnostizieren.** Ich schloss zuerst
„ich habe die Regression eingeschleust" und stuetzte das auf zwei
`cf066a5 success`-Eintraege von `gh run list`. Die waren die Image- und
Tag-Workflows, **nicht** die CI; erst `--workflow=ci.yml` zeigte, dass CI auf
`cf066a5` gruen war — und die Diagnose damit bestaetigte. **Regel daraus:** bei
`gh run list` immer **nach Workflow filtern**, bevor ein alter Lauf als
Referenz dient, sonst zieht man den falschen Lauf als Beweis heran. Das ist
derselbe Fehler wie bei der Auth-Drosselung: eine Zahl aus dem Kontext
gerissen und zur Entscheidung gemacht.

**Folgepunkte aus der Waehrungs-Migration (2026-09-27):**
- [~] wartet auf eine Live-Reproduktion im Lastbereich zwischen 15 und 22 sowie eine Erklärung der 6-von-8-Schieflage auf Mobile. Ohne positiven Beleg wird nichts gebucht. **Offen und ausdruecklich nicht als geloest gebucht:** (a) Die
  **Last-36,9-Variante** des `projects-board`-Fehlers bleibt unerklaert. Das
  behobene Rennen wird unter Last *leichter* zu gewinnen; was bei Last 36,9
  einen 15-s-`waitForResponse`-Timeout erzeugt hat, ist mit hoher Wahrschein-
  lichkeit eine **eigenstaendige, lastgebundene Ursache**. (b) Im
  Fehler-Schnappschuss stand `Galerie-Typ` wieder auf `Privat`, obwohl
  `selectByLabel` zuvor `Delivery (Downloads)` erfolgreich gewaehlt hatte — das
  deutet auf eine spaete asynchrone Re-Renderung, die das Formular zuruecksetzt.
  Der Agent hat das nicht verfolgt und **nicht als Befund ausgegeben**.
## Dialog-UI-Befunde aus derselben Welle (2026-09-27) — sechs Produktfragen, keine Implementierungsdetails

- ~~D-14~~ **`CouponFormDrawer`: Escape und Backdrop lösen die Ungespeichert-Warnung bereits aus**
  — bestätigt, keine Änderung (`CouponFormDrawer.tsx:210-213` beschreibt, dass die Shell
  Kopf-Button, Backdrop, Escape und Abbrechen über einen Prop routet). Der Nutzer wird den
  Verlust des getippten Texts spüren; das ist der bewusst gewählte Preis, kein Versehen. Steht
  als D-14 in `features/owner-decisions.md`.
- [~] wartet auf eine Live-Reproduktion im Lastbereich 15–22 und einen positiven Beleg für die 6-von-8-Schieflage auf Mobile; die mobile Hälfte hat bisher keinen Mobile-spezifischen Defekt ergeben. **Was der Agent nicht klaeren konnte, offen gesagt:** (a) Er konnte die
  Fehler **nicht reproduzieren**; er hat den Bereich zwischen „gesaettigte Box"
  (Last 15, 22/22 gruen) und den gemeldeten Lasten 186–334 eingegrenzt, diesen
  aber als 10–20-fache Ueberlastung nicht reproduziert. Die Mechanismen stuetzen
  sich auf **Artefakt-Forensik plus deterministischen Mechanismusnachweis**, nicht
  auf eine Live-Reproduktion. (b) Die **6-von-8-Schieflage auf Mobile kann er
  nicht erklaeren**: Gruppe B ueberspannt beide Viewports, Gruppe A ist 4/4
  Mobile — bei 31 Tests pro Projekt mit Chance erklaerbar, aber **kein positiver
  Beleg** fuer eine Mobile-spezifische Ursache und kein Mobile-spezifischer
  Defekt gefunden.
  **Die `maxWidth`-Typhaelfte ist geschlossen (2026-10-21):** der Build-Gate fährt
  jetzt `tsc -b --force` (`frontend/package.json:12`), damit die verengte Union
  nicht von einem veralteten `tsbuildinfo` abhängt; die Sabotage-Probe und die
  Begründung stehen in `features/tech/04-testing-guidelines.md` §6a.
- [~] wartet auf ein späteres Aufräumen der Stack-Env — der Wert hat keinen Secret-Bezug, wird aber über die Ports-Liste geführt. **`SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN`** ist im Compose ein
  Container-Wert ohne Secret-Bezug, wird aber über die Ports-Liste geführt; bei
  einem späteren Aufräumen prüfen, ob es in die Stack-Env gehört.

**Verifikation / Übergabe**
- [~] wartet auf die **betrieblichen** Live-GO-Punkte — die Radar-/3DS-/Webhook-/Privacy-Checkliste
  (Stripe-Dashboard, `features/security/card-testing-protection.md`), den **Test der Monitoring-Alarme**
  (in `features/security/card-testing-runbook.md` §1–§3 beschrieben, aber nie ausgelöst), den
  **Live-Nachweis des Kill-Switches** (§6.2 des Runbooks: der Schalter wurde nie in Produktion ausgeloest)
  und die **Datenschutz-Freigabe** (§10: Rechtsgrundlagen und Aufbewahrungsfristen sind laut
  `frontend/src/ui/Privacy.tsx` nicht abschliessend festgelegt; die DPO-Pruefung steht aus).
  **Die Reihenfolge ist nicht beliebig:** §12.4 des Vertrags erlaubt den Turnstile-Rollout erst, wenn
  Dashboard-, **CSP-**, Datenschutz- und Siteverify-Checks bestanden sind. Die CSP-Pruefung ist der
  **Blocker**: die ausgelieferte Policy erlaubt `https://challenges.cloudflare.com` nicht, das
  Turnstile-Skript und sein Frame wuerden blockiert, und der Checkout-Button bliebe fuer jeden
  risikobedingten Versuch deaktiviert (Befund des unabhaengigen Reviews vom 2026-10-22; die
  Aenderung gehoert in das Caddyfile von `proxy-stack` und **nicht** in dieses Repo — der Mirror
  `deployment/content-security-policy.txt` folgt danach, `./scripts/check-csp-policy.sh` belegt die
  Uebereinstimmung). `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET` sind in Produktion leer, die Policy passt
  also zum Ist-Zustand. **Erledigt und nicht mehr Teil dieser Position:** der Contract-/Card-Testing-
  Review (APPROVED MIT AUFLAGEN, `features/security/card-testing-protection.md` § Status), das
  Monitoring-Runbook (§1–§3), der Rollback-Ablauf ohne Datenverlust (§6) und die Auflagen, die ohne
  den Owner umsetzbar waren.
## 📌 SKILLS-STAND (2026-10-19) — Marker auf `787670a` fortgeschrieben

> `AGENTS.skills.md` steht jetzt auf `787670a` (Skills-Repo-HEAD vom 2026-10-19), geprüft am
> 2026-10-19; 17 Skills, **12× angewendet, 5× nicht zutreffend**. **Von dem, was hier steht, ist
> nichts umgesetzt** — Position 7 ist am 2026-10-20 (`1818909`) und Position 6 am 2026-10-21
> (`db13b2c`) umgesetzt und deshalb **aus diesem Board entfernt** (siehe „Historie der Nummern"
> unten; die Belege stehen in den genannten Commits).
> Die Positionen sind Befund-Protokoll, kein Arbeitsauftrag (Board-Regel: Log, kein Auftrag).
> Jede Zahl ist an `Datei:Zeile` zitierbar und wurde vor dem Schreiben verifiziert.
> **Historie der Nummern in diesem Abschnitt:** Position 1–3 (Named-Locks-Schwelle, Dreiklang,
> Matrix) sind am 2026-10-18 **committet** (`e1ed87a`, Playwright-Bump auf 1.64.1) und
> entfernt; Position 4 (GHCR-Blocker) war am 2026-10-15 geschlossen und entfernt; Position 5
> (`cancel-in-progress`) ist als Betriebs-Notiz in die Session-Handover-Liste gewandert
> (`AGENTS.todo.md:989`). Position 6 und 7 sind neu aus dem Marker-Fortschreiben vom
> 2026-10-19; Position 7 (`AGENTS.md` §6 verlangte den abgeschafften `vision`-Subagenten) ist am
> 2026-10-20 **geschlossen und entfernt** (`1818909` — die Regelzeile in `AGENTS.md` §6 nennt
> jetzt den verbleibenden Weg, das Modell liest die Bilder selbst); Position 6 (Lockfile-Regel des
> Test-Images) ist am 2026-10-21 **geschlossen und entfernt** (`23ca866`) — die Regel ist seit
> `db13b2c` umgesetzt (Browserversion aus dem aufgelösten Lockfile). **Position 8 ist neu aus der
> Nachprüfung von `7b1ca62` (2026-10-21) und der einzige offene Eintrag in diesem Abschnitt.**

## 🔑 STRIPE-KONTOWECHSEL (2026-10-15) — neues Konto, Keys, Vite-Vorrang

> Anlass: neues Stripe-Konto. Der Wechsel tauscht den Key, an dem die rote E2E-Kette seit
> 2026-10-12 scheitert (`401 api-key-expired` im Balance-Preflight). **Die Kette schließt sich —
> gemessen: Run `38738659755` auf `d4fc972` ist `success`, 11/11 Jobs inkl. aller
> 7 Playwright-Jobs (Mobile 1–3, Desktop 1–3, serial).** Dauerhafte Regel zur
> Key-Ablage: `features/owner-decisions.md`/**D-24**.

**Zwei Befunde vor der Umstellung, je mit Befehl belegt** (sie haben den Zuschnitt bestimmt):

- **Der Kontowechsel braucht keine Datenmigration.** Gemessen in der Prod-DB, reproduzierbar mit
  `docker exec portal_db sh -c 'mariadb -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -N -B -e "<SQL>"'`
  und `<SQL> = SELECT (SELECT COUNT(*) FROM users), (SELECT COUNT(*) FROM users WHERE
  stripe_customer_id IS NOT NULL AND stripe_customer_id <> ""), (SELECT COUNT(*) FROM orders);`
  → `1  0  0` (gemessen 2026-10-15): `users_total = 1`, `users_mit_cus = 0`, `orders_total = 0`. Ohne diese Messung wäre der Wechsel ein
  Checkout-Ausfall für jeden Bestandskunden gewesen: `StripeCustomerService.php:44` gibt einen
  gespeicherten `cus_…` **blind** zurück — `if (is_string($lockedUser->stripe_customer_id) && $lockedUser->stripe_customer_id !== '')` → im neuen Konto existiert er nicht mehr, und
  `paymentIntents->create(['customer' => …])` antwortet `No such customer`.
- **Das Live-Bundle trug den Test-Key.** `md5` von `assets/ClientCartView-3L6eh2lZ.js` live und im
  lokalen `frontend/dist/` identisch (`67b769fd0dbc1c4f293410433d3d8f9d`), beide mit
  `pk_test_51TJyXb0zjsh…`, während der Backend-Container `STRIPE_SECRET=sk_live_…` führt. Ursache
  (Vite-Env-Vorrang) und Entscheidung: D-24.

**Umgestellt und gemessen (2026-10-15):**

- **Lokale Envs auf dem neuen Konto** (`51UQksN0…`): `backend/.env` (`pk_test`/`sk_test`;
  `STRIPE_WEBHOOK_SECRET` geleert, sonst blockiert der Wert den Tunnel-Fallback aus
  `WebhookController.php:74`), `frontend/.env` + `.env.local` (`pk_test`), `frontend/.env.production`
  **neu** (`pk_live`, D-24). CI-Preflight nachgestellt: `/v1/balance` 200, `payment_intents` create 200.
- **CI-Secrets neu gesetzt** (`gh secret set`, 2026-10-15T09:57Z): `STRIPE_KEY`, `STRIPE_SECRET`,
  `STRIPE_WEBHOOK_SECRET`, `VITE_STRIPE_PUBLIC_KEY`.
- **Prod-Env im Container nachgewiesen, nicht angenommen:** `docker exec portal_backend printenv`
  liefert für alle drei Werte denselben sha256 wie `.env.production` (`de208c971757` / `0d02aad55cb9` /
  `9883869cb065`); Container recreated 2026-10-15T10:22:03Z.
- **Live-Webhook** `we_1UQlhS0qxbyNaMESN5PNyhlM` → `…/api/webhooks/stripe`, `enabled`, genau die vier
  Events. **Signaturpfad end-to-end belegt** mit selbst signierten Proben auf `charge.succeeded`
  (Default-Zweig `WebhookController.php:200` — keine DB-Schreibung, keine Mail): neues `whsec` 200,
  Zufallssignatur 400, altes `whsec` (sha `de59b9b54cee`) 400.
- **Frontend-Sync nachgeholt:** der erste „done"-Stand lieferte weiter `ClientCartView-3L6eh2lZ.js`
  mit `pk_test_51TJyXb0…` aus (`last-modified` 30.09.). Nach dem rsync der Frontend-Zeilen aus
  `sync.sh` (58 Dateien, 2,6 MB) ist die HTTP-Antwort byte-identisch mit dem lokalen `dist/index.html`
  (`defb9ae9c7ab73cd9ea3d4a56fe8dc7b`) und trägt `pk_live_51UQksN0…`. **Damit ist auch belegt, dass
  der rsync-Zielpfad der ausgelieferte Pfad ist** (md5 HTTP-Antwort == Datei am Ziel).
- **E2E-Kette geschlossen** (2026-10-15): `gh run view 38738659755` → `success`, 11/11 Jobs
  (Playwright: Mobile 1–3, Desktop 1–3, serial — 7 Jobs; plus Frontend, Backend,
  `CI security contract`, `CI gate (push)` = 11 insgesamt).
  Der Key-Tausch ist damit als Ursache **bestätigt**, nicht bloß adressiert.

- **Test-Key ist jetzt der Standard-Key des Kontos, nicht der der CLI.** Das sind zwei verschiedene
  Werte (`sha256` `5ce6d2c3849b` vs. `949838090def`); nur der CLI-eigene trägt ein Ablaufdatum
  (2027-01-13) — der Dashboard-Key trägt laut UI keines. Getauscht in `backend/.env` und im
  GitHub-Secret (`gh secret set STRIPE_SECRET`, 2026-10-15T10:30Z); Gegenprobe mit dem Wert **aus der
  Datei**: `/v1/balance` 200 und `payment_intents` create 200. `pk_test` war in allen drei Quellen
  derselbe Wert (`c9b329739363`) — dort war nichts zu tauschen, der CLI-Key ist nirgends mehr im
  Einsatz (0 Treffer).

**Entschieden, ohne technische Umsetzung:** **D-25** (`features/owner-decisions.md`) — der Roll der beiden
Live-Secrets ist abgelehnt; Begründung ist die Owner-Aussage „Provider fährt ZDR". Eine
Implementierung dazu gibt es nicht — die verworfene Alternative steht in der Tabelle „Verworfen".

Die Betriebs-Fakten (drei Werte, Konto, Endpunkt-ID, vier Events, die drei Fallen) stehen in
`deployment/README.md`, Abschnitt „Stripe (Live)".

**Offene Positionen:**

*(keine — die pk_test-Gate-Position ist mit `ea07783` erledigt und entfernt)*

## 🔍 Stripe-Selbsttest im Super-Admin (umgesetzt 2026-10-15)

> Anlass: der Kontowechsel oben hat zwei Fehlerklassen gezeigt, die **von außen unsichtbar** sind —
> ein `pk_test`-Bundle bei `sk_live`-Backend (D-24) und einen **abgelaufenen** Key
> (`401 {"code":"api_key_expired"}`). Beide waren nur durch Messen zu finden, nicht durch Lesen der
> Konfiguration. Der Selbsttest macht sie je zu einem Klick.
>
> **Entschieden (Owner, 2026-10-15):** read-only Prüfung **ohne Schreibprobe**; der Soll-Modus wird
> **aus `APP_ENV`** abgeleitet (`production` → live, `local`/`testing` → test), keine zusätzliche
> Setting-Zeile. Vorbild ist der SMTP-Test: `POST /api/management/settings/test-email` →
> `MailController::sendTest`, UI-Karte in `ManagementSettingsView.tsx`
> (`data-testid="send-test-email"`), Tests in `MailTestControllerTest`.
>
> **D-26 (Owner, interaktiv 2026-10-15):** Prüfung 5 bleibt streng, und die E2E-Spec assertiert nur die
> vier umgebungsdeterministischen Befunde. *Warum:* gemessen am 2026-10-15 hat der **Test**-Modus
> **0** Webhook-Endpunkte —
> `curl -sS -H "Authorization: Bearer $STRIPE_SECRET" 'https://api.stripe.com/v1/webhook_endpoints?limit=100'`
> → `"data": []`. Prüfung 5 ist im E2E-Lauf also zwangsläufig rot, und das ist der gemessene
> Ist-Zustand, kein Defekt; den Test-Modus-Endpunkt legt nur der lokale Tunnel ad hoc an. Der
> Zweig-Test der Prüfung 5 (fehlend / `disabled` / unvollständige Events) liegt deshalb in PHPUnit,
> wo ein gefakter Client ihn deterministisch erzeugt. Die zwei abgelehnten Auswege stehen in der
> „Verworfen"-Tabelle.

**Fünf Prüfungen, alle mit dem heutigen eingeschränkten Live-Key als machbar gemessen (HTTP 200):**

1. **Modus-Abgleich, rein lokal** — `STRIPE_KEY` und `STRIPE_SECRET` tragen denselben Modus
   (`_test_`/`_live_`), `STRIPE_WEBHOOK_SECRET` ist nicht leer.
2. **Das Frontend-Bundle** — der Kern, den nur der Browser sehen kann: die Einstellungsseite schickt
   den Modus ihres eigenen `import.meta.env.VITE_STRIPE_PUBLIC_KEY` mit (**nur** `test`/`live`, nie den
   Wert), das Backend vergleicht ihn mit dem Soll-Modus. Genau hier wäre D-24 aufgefallen.
3. **`GET /v1/balance`** — Key gültig, `livemode` == Soll-Modus. Ein abgelaufener Key wird als
   `api_key_expired` benannt statt zu „irgendwas geht nicht" zu verflachen.
4. **`GET /v1/account`** — `charges_enabled`, `payouts_enabled`, `details_submitted` und die
   **Konto-ID**: ein Admin sieht damit, *welches* Konto verdrahtet ist.
5. **`GET /v1/webhook_endpoints`** — existiert ein Endpunkt mit unserer URL, ist er `enabled`, und
   sind die vier Events abonniert (`payment_intent.succeeded`, `payment_intent.payment_failed`,
   `charge.dispute.created`, `charge.refunded`)?

**Vertrag:** `POST /api/management/settings/stripe-check` (POST wie der SMTP-Nachbar — ein GET könnte
ein Proxy cachen), `super_admin` only, Antwort als Liste von Befunden
`{key, status: ok|warn|fail, message}` **ohne** Key-Material. Läuft **nur auf Klick**, nicht im
Render-Pfad (keine Stripe-Aufrufe beim Seitenaufbau). Fehlermeldungen aus 3–5 werden durchgereicht —
`more_permissions_required` benennt die fehlende Berechtigung selbst, das ist wertvoller als ein
eigener Text.

**Umgesetzt (2026-10-15).** Der dauerhafte Vertrag — Route, die fünf Befunde mit ihrer
Statusbedeutung, die Antwortform, „kein Key-Material" — steht in
`features/security/env-hardening.md` („Super-Admin Stripe-Selbsttest") und wird hier nicht
wiederholt. Gepinnt durch `backend/tests/Unit/StripeKeyModeTest.php` (6 Tests, Grenzfälle der
Modus-Ableitung), `backend/tests/Feature/StripeKeyCheckControllerTest.php` (23 Tests: Auth-Matrix
inkl. Fotograf, je Befund ein Fall, alle drei Zweige der Prüfung 5, die drei Fehlerpfade des
gefakten Clients und ein echter Redaktions-Test, in dem `sk_test_…` wirklich im Stripe-Fehlertext
steht) sowie `frontend/tests/e2e/admin/settings/stripe-check.spec.ts` (`@smoke` + `@feature:settings`).
Der Tunnel-Fallback des Webhook-Secrets lebt seit der Umsetzung in `App\Support\StripeWebhookSecret`,
damit Ingress (`WebhookController::handleStripe`) und Diagnose (Prüfung 1) **eine** Regel lesen.

**Deploy 2026-10-15, nach grünem CI auf `8bf8882`.** `./sync.sh` übertrug genau **sechs**
Dateien — belegt mit `./sync.sh --dry-run --itemize-changes`: die vier neuen
(`StripeKeyCheckController`, `StripeKeyCheckService`, `StripeKeyMode`, `StripeWebhookSecret`)
und die zwei geänderten (`WebhookController.php`, `routes/api.php`); Tests nimmt
`rsync-backend-exclude.txt` aus. Danach `docker restart portal_backend` — reiner Datei-Inhalt,
kein Compose-Feld, also **kein Recreate** (§13), und **keine** Migration im Satz. Gegenproben:
Container `Up (healthy)`, `/up` → 200, die neue Route als Gast → **401** (ein 404 hätte alten
Code bedeutet), `stripe-check-button` im ausgelieferten `dist/assets/ProtectedDashboard-*.js`,
und die D-24-Zeile auf dem Host → `pk_live_51UQksN0qxby…` **ohne** `pk_test`. Zuvor lokal über
`scripts/e2e-up.sh` gefahren — CI übt dieses Skript **nie** aus: `2 passed`, und
`backend/.env.e2e` trägt nach dem Lauf `STRIPE_WEBHOOK_SECRET=whsec_e2e_dummy`, der B1-Fix
wirkt also im echten Pfad statt nur in der Beschreibung.

**Owner-Prüfung 2026-10-15 (Produktion, Live-Modus): alle fünf Befunde `ok`.** Der Restart war zu
diesem Zeitpunkt bereits erfolgt (Container `healthy`, `/up` 200, Route 401). Die Prüfung
bestätigt zusätzlich, was D-26 für den **Test**-Modus ausschließen musste: **Prüfung 5 ist im
Live-Modus grün** — der Endpunkt ist vorhanden, `enabled` und mit allen vier erforderlichen
Events abonniert, also genau der Zustand, den der Test-Modus nicht hergibt. Die Strenge der
Prüfung ist damit belegt statt nur begründet: sie unterscheidet echte Lagen, statt
umgebungsabhängig zu warnen. Bundel- und Backend-Modus stimmen überein (beide live), die
Balance ist im erwarteten Modus abrufbar, das Konto ist vollständig freigeschaltet.
*Quelle:* die fünf Befundtexte der Oberfläche (Owner, 2026-10-15). Konto- und Endpunkt-ID sind
hier bewusst **nicht** zitiert — sie stehen im Befund der Oberfläche selbst.
