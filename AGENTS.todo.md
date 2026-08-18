# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-18. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
> - Brand-Architektur (SOLL, Commit `1831116`): `features/infrastructure/21-brand-config-driven.md`
> - Pricing-Strategy-Pattern: `features/infrastructure/17-pricing-strategy-pattern.md`
> - Kanban-SOLL (Rollen-Matrix, DnD-Desktop-only, Status-Select): `features/b2b/11-kanban-board.md`
> - Security-Risiken (C1–C4) & Stärken: `AGENTS.md` §7 / §8
> - Backlog-Ausarbeitung 2026-08-04: A1 (Schritt 1 erledigt, Commit `e44f6dd`), F3 (offen), Stack-Konsolidierung (obsolet → SQLite-Richtung, s. u.).
>
> Test-Regel (DoD): Backend → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## ✅ Erledigt (2026-08-18, Tooling) — CodeGraph Sync Pre-Commit-Hook

- `.githooks/pre-commit` (versioniert) → `codegraph sync -q` vor jedem Commit; **fails open** (kein Commit-Gate). Aktiviert: `git config core.hooksPath .githooks` (lokal gesetzt, pro Clone einmal nötig). Test: `git hook run pre-commit` ✅ (beide Pfade verifiziert: sync ok / codegraph fehlt → exit 0, Warnung).
- `codegraph init` war bereits abgeschlossen (`.codegraph/` indexiert: 783 files / 8.665 nodes, Daemon läuft, Auto-Sync aktiv) — kein Rebuild nötig; `codegraph sync` → „Already up to date".
- Doku: `AGENTS.md` §11.

---

## ✅ Erledigt (2026-08-18, Tooling) — CodeGraph Sync Pre-Commit-Hook + zentrales Skills-Repo

**CodeGraph Pre-Commit-Hook (12 Kopien, alle identisch via sha256):**
- `.githooks/pre-commit` → `codegraph sync -q` vor jedem Commit; **fails open** (kein Commit-Gate), Guard `[ -d .codegraph ]` → stiller Skip ohne Index. Aktiviert: `git config core.hooksPath .githooks` (portal + 9 Projekte + agents-skills).
- Kanonische Kopie: `agents-skills/.agents/skills/codegraph-project-setup/templates/pre-commit.sh`.

**Zentrales Skills-Repo `agents-skills` (GitHub `reisi007/agents-skills`, privat):**
- Eigene Skills jetzt NUR dort: `ui-review`, `codegraph-project-setup`, `agent-config` (neu, dokumentiert globales Setup). Struktur `.agents/skills/<id>/` (portable Agent-Skills-Spec).
- Global registriert via `~/.config/opencode/opencode.jsonc` `skills`-Array (Backup: `opencode.jsonc.bak-20260818`) → in jeder Session/Projekt verfügbar (verifiziert: Skill-Listing dieser Session zeigt `codegraph-project-setup` + `agent-config`).
- Projekt-Kopien entfernt: `portal/.opencode/skills/`, `open-accreditation/.opencode/skills/`.
- **Nicht verschoben** (per User-Entscheid): daisyui, find-skills, blog-beitrag, testimonial, stripe-* (Portal), nx-* (angular-material-extended). Ownership-Regeln: `AGENTS.md` §12.
- GitHub-Repo-Description gesetzt (deutsch, siehe §12/Doku).

---

## ✅ UMGESETZT & VERIFIZIERT (2026-08-18) — WYSIWYG-Resize + PDF-Konsistenz + Kalkulation-oben/Dedup + Typografie

> **Status: ✅ Implementierung abgeschlossen + verifiziert am 2026-08-18 — wartet auf manuellen User-Test vor Commit/Push** (etablierter Workflow 2026-08-17: kein Commit/Push vor User-Freigabe; danach Sync nach Prod wie gehabt: Backend-Sync + `docker restart portal_backend` + ggf. Portainer-Stack-Update).
>
> **Verifikations-Ergebnis (separate Agenten, 2026-08-18):** Backend volle Suite **1176 passed / 0 failed** (inkl. neuem `PdfTypographyTest` 5 Tests / 44 Assertions; Regression `ManualDocumentTest|ContractPdfServiceTest` 8/38; Pint PASS). Frontend **vitest 585 passed**, `pnpm lint:fix && pnpm build` 0 Fehler; E2E `@feature:admin:documents` **8/8**, `contracts.spec.ts` **14/14**, `@smoke` **58/58**.
>
> **Dokumentierte Abweichungen/Erkenntnisse:** (1) E2E-Resize-Test nach Verifikator-Befund kalibriert: Container startet bei 80 Absätzen am `max-h-160` → Drag **nach oben** (Verkleinern); Diagnose wasserdicht (Ziehen nach unten ist per CSS-`max-height` wirkungslos). (2) Resize-Drag-Substep **Desktop-only** (Mobile-Touch-Emulation unterstützt CSS-`resize` nicht; Präzedenzfall `projects-board.spec.ts`); Scroll-/Toolbar-Assertions laufen auf Mobile weiter voll. (3) **UX-Note:** CSS-`resize` ist Mouse-only → auf echten Touch-Geräten nicht resize-bar; falls gewünscht, Custom-Pointer-Events-Lösung in `WysiwygEditor` (separater Task). (4) `features/documents/` (neue Kategorie) ist inzwischen in `features/README.md` indexiert (Abschnitt „📄 Documents & PDF").

**User-Requests (2026-08-18, vier zusammenhängende Punkte):**
1. **WYSIWYG-Editor** soll per Drag am rechten unteren Eck wie eine Textbox in der Größe veränderbar sein; bei zu langem Inhalt scrollt **nur der Text** im Editor, nicht der Editor inkl. Toolbar.
2. **Generiertes PDF:** Der WYSIWYG-Bereich (`terms_html`) wird hervorgehoben (Rand + Hintergrund) und passt nicht zum Brand-Farbschema → soll „aus einem Guss" wirken.
3. **Rechnung & Angebot:** Kalkulation (Leistungen/Positionen + Summe) im Formular **nach oben**; **Duplizierung entfernen** (interaktiv geklärt 2026-08-18: = (a) Positionen-Block im Formular über den Text-Editor, (b) PDF-Templates entduplizieren).
4. **Typografie:** Schusterjungen (widows) & Hurenkinder (orphans) in der automatisierten PDF-Erstellung; **bevorzugt PHPUnit-Test** („Farben und Absätze").

**Engine-Entscheidung (interaktiv geklärt 2026-08-18, verifiziert per Web-Recherche):** **Bei dompdf bleiben — kein Umbau.** Begründung: mPDF hat **keine** widows/orphans-Unterstützung (offizielles Manual + GitHub-Issue #48, seit 2015 offen) → Seitwärtswechsel. `dragonofmercy/phppdf` (2026-08-18 geprüft) ist kein HTML→CSS-Renderer (imperative API), hat ebenfalls keine orphan/widow-Logik und nur ~109 Installs → verworfen. Einziger echter Upgrade-Pfad wäre Headless-Chromium (Browsershot; caniuse: widows/orphans ab Chrome 25) — Deployment-Kosten (Binary im Docker-Image, RAM) rechtfertigen die eine CSS-Eigenschaft nicht, die per `page-break-inside: avoid` kompensierbar ist. dompdf-Fakt (Vendor-Source verifiziert): `orphans` wird enforced (`FrameDecorator/Page.php:420`, Default 2), `widows` **nicht** (`Page.php:426–427`: `// FIXME: Checking widows is tricky … Just ignore it for now`). → Siehe Task E + Doku `features/`.

### Task A — WysiwygEditor: Resize-Griff + scrollbarer Textbereich

**Datei:** `frontend/src/ui/components/WysiwygEditor.tsx` (aktuell: Wrapper Z. 258 `overflow-visible flex flex-col`; `EditorContent` Z. 340 wächst unbegrenzt; kein Scroll-Container, kein Resize; `min-h-48` liegt derzeit auf den ProseMirror-Attributen Z. 181).

1. [x] Neuer Scroll-Container um `<EditorContent/>` (zwischen Toolbar und Zeichenzähler-Footer), Klassen: `min-h-48 max-h-160 overflow-y-auto resize-y` + `data-testid="editor-scroll"` — Startgröße 12rem wie bisheriges ProseMirror-`min-h-48`, max. 40rem (Tailwind-v4-Spacing-Skala, **keine** JIT-Bracket-Syntax, kein `style`-Attribut → Tailwind-Only-Policy). `resize-y` = nativer Textbox-Griff unten rechts (CSS-`resize` erfordert `overflow` ≠ `visible` → passt zu `overflow-y-auto`). `min-h-48` von den ProseMirror-Attributen (Z. 181) in den Scroll-Container verschieben (Attribut-Klassen: `prose prose-sm max-w-none focus:outline-none p-4 text-base-content/90`).
2. [x] Äußerer Wrapper bleibt `overflow-visible` (Link-Popover, Slash-Menü `position:fixed` → unbeschnitten); Toolbar + Footer liegen **außerhalb** des Scroll-Containers (Geschwister) → langer Text scrollt nur im Content-Bereich.
3. [x] Keine Änderung an Tiptap-Extensions/`immediatelyRender: false` (bestehende Crash-Fix-Regel bleibt).

**Tests:**
4. [x] **Vitest** `frontend/src/ui/__tests__/WysiwygEditor.test.tsx` (existiert): neuer Test — `getByTestId('editor-scroll')` hat Klassen `resize-y`, `overflow-y-auto`, `min-h-48`, `max-h-160`; Toolbar (Heading-Select) und Zeichenzähler-Footer sind **Geschwister** des Containers (nicht Nachfahren).
5. [x] **Playwright** `frontend/tests/e2e/admin/wysiwyg-editor.spec.ts` (Tag `@feature:admin:documents`): Manuelles Angebot → viele Absätze in Editor tippen → `evaluate(el => el.scrollHeight > el.clientHeight)` auf `[data-testid="editor-scroll"]` = true; Heading-Select (Toolbar) bleibt sichtbar; dann Resize-Griff (Bounding-Box unten rechts) per `page.mouse` ziehen → Höhe des Containers hat sich geändert.

### Task B — PDF: hervorgehobene Box entfernen („aus einem Guss")

**Befund:** `manual_offer.blade.php:31,86` und `contract_signatures.blade.php:61` rendern `terms_html`/`custom_conditions` in `background:#fcfcfc; border:1px solid #eee; border-radius:5px`. `invoice.blade.php` (Z. 128/134) nutzt keine Box → inkonsistent.

1. [x] Box-Stil (`#fcfcfc`/`#eee`) aus allen drei Vorkommen **entfernen**; `.editor-content`-Regeln wandern in das gemeinsame Styles-Fragment (Task D) — einheitlich: keine Box, konsistenter Textfluss, `h1–h6` in `$secondaryColor` (Invoice erhält die Heading-Farbe zur Angleichung — bewusste Änderung), Tabellen-Borders dezent auf `$secondaryColor` getönt statt `#ccc`.
2. [x] `contract_signatures.blade.php`: hartcodierte `#4A5568` (Headings, `.section-title`, `.signature-section th`, `.audit-section`) → Brand-Farben `$primaryColor`/`$secondaryColor` (Service übergibt sie bereits, `ContractPdfService.php:60–61`). Bewusste Farbangleichung, wird im Commit begründet.

### Task C — Formular: Kalkulation nach oben (interaktiv bestätigt)

**Datei:** `frontend/src/ui/management/ManagementManualInvoiceView.tsx` (aktuelle Reihenfolge verifiziert: Header → RecipientFormSection → **isOffer: „Angebotstext (Einleitung)"+Editor** → „Leistungen / Positionen" → **!isOffer: „Zusatztexte / Sonderkonditionen"+Editor** → Submit)

1. [x] JSX-Reihenfolge ändern: `ManualDocumentHeader` → `RecipientFormSection` → **`Leistungen / Positionen`-Block** (inkl. Paket-Kalkulator-Button, `InvoiceItemsTable`, `InvoiceDiscountsSection`, `InvoiceTotalSummary`) → danach Offer-`Angebotstext (Einleitung)` bzw. Invoice-`Zusatztexte / Sonderkonditionen` (jeweils mit `WysiwygEditor`) → Submit.
2. [x] Bestehende Tests (`ManagementManualInvoiceView.test.tsx`) prüfen nur Präsenz, keine DOM-Reihenfolge → bleiben grün. E2E `manual-documents.spec.ts` nutzt `.ProseMirror` `.first()` (nur ein Editor pro Typ) → unabhängig von der Reihenfolge.

**Tests:**
3. [x] **Vitest** `ManagementManualInvoiceView.test.tsx`: neuer Test — `invoice-items-table` steht **vor** `wysiwyg-editor` im DOM (via `compareDocumentPosition`), für beide Typen (invoice + offer). (Mocks mit den Testids existieren bereits in der Datei.)

### Task D — PDF-Templates entduplizieren (interaktiv bestätigt)

**Befund:** Items-/Totals-Markup („Kalkulation") 3× dupliziert (`invoice`, `manual_offer`, `contract_signatures`); `pdf/fragments/items_table.blade.php` existiert als **totes** Fragment (nirgends includiert); CSS-Blöcke der drei Templates duplizieren sich ebenfalls.

1. [x] **Neu `backend/resources/views/pdf/fragments/styles.blade.php`:** gemeinsamer `<style>`-Block (Body, `.invoice-details`, `h1–h6` inkl. `page-break-after: avoid`, `table.items`, `.editor-content` inkl. Tabellen-Regeln, `.footer`, Typografie-Regeln aus Task E); Parameter `$primaryColor`/`$secondaryColor`; von allen drei Templates im `<head>` includiert (`@include('pdf.fragments.styles', [...])`).
2. [x] **`backend/resources/views/pdf/fragments/items_table.blade.php` (tot → Single-Source-of-Truth):** rendert Items-`<tbody>` + Zwischensumme + `discount_rows` + Gesamtbetrag. Parameter: `$totalLabel` („Rechnungsbetrag" / „Voraussichtlicher Gesamtbetrag" / „Gesamtbetrag"), `$invoiceMode` (bool → Tier-Rows mit „Auflösung" + Header „Preis / Stück"), `$showSubtotal` (nur wenn Rabatte). Invoice-Totals-Box (`page-break-inside: avoid`) bleibt als dünner Wrapper im Invoice-Template (Paginierungsschutz der Totals).
3. [x] `invoice.blade.php`, `manual_offer.blade.php`, `contract_signatures.blade.php` auf Fragment-Includes umstellen (kein `@php $subtotal …`-Block mehr in den Templates).
4. [x] **Regression:** bestehende `ManualDocumentTest` (echte dompdf-Generierung, Real-PDF) und `ContractPdfServiceTest` (loadView-Mock) bleiben unverändert grün.

### Task E — Typografie (Schusterjungen/Hurenkinder) + PHPUnit-Test

**Fakten (verifiziert):** dompdf 3.0 enforced `orphans` (Default 2), **nicht** `widows` (Vendor-FIXME `Page.php:426–427`). → Zwei Maßnahmen:

1. [x] Im Styles-Fragment (Task D): explizit `body { orphans: 2; widows: 2; }` (orphans greift sofort, dokumentiert Intention) **und** `.editor-content p, .editor-content li { page-break-inside: avoid; }` → WYSIWYG-Absätze werden nie über Seitengrenzen gerissen → Schusterjungen im Nutzerinhalt ausgeschlossen (kompensiert widows-FIXME vollständig). `h1–h6 { page-break-after: avoid; }` in allen Templates (Invoice ergänzen). **Kein** `page-break-inside: avoid` auf großen Blöcken (Paginierungslücken vermeiden).

**Test (PHPUnit, bevorzugt — „Farben und Absätze"):**
2. [x] **Neu `backend/tests/Feature/PdfTypographyTest.php`:** Data-Provider über alle 3 Templates; `InvoiceSnapshot` per `new InvoiceSnapshot([...])` (wie `InvoiceController::generateManualInvoice`, Array-Cast `customer_details`, kein DB-Save), Settings für Header/Footer seeden (Muster `ManualDocumentTest::setUp`); `view('pdf.invoice'|'pdf.manual_offer'|'pdf.contract_signatures', $data)->render()`.
   - **Absätze:** HTML enthält `orphans: 2`, `widows: 2`, `.editor-content p` mit `page-break-inside: avoid`; übergebene `<p>`-Inhalte erscheinen im `.editor-content`-Bereich.
   - **Farben:** `primaryColor`/`secondaryColor` aus `BrandRegistry::configOrDefault()` sind im gerenderten HTML enthalten; **kein** `#fcfcfc`, **kein** `border: 1px solid #eee`.
   - **Regression Dedup:** Gesamtbetrag-Label (je Template) + formatierter Betrag erscheinen genau einmal.
3. [x] **Ergänzung Feature-Test** (in `PdfTypographyTest` oder `ManualDocumentTest`): `/api/management/invoices/manual` für invoice + offer → Stream-Content beginnt mit `%PDF-1.`, Offer enthält `%OFFER_JWT:` (Muster `test_offer_jwt_can_be_generated_and_extracted`).

### Doku (features/, SOLL-Zustand)

4. [x] **`features/documents/pdf-typography.md`** (neu): dompdf-Engine-Entscheidung dokumentieren (orphans enforced / widows FIXME, Engine-Vergleich dompdf vs. mPDF vs. phppdf vs. Chromium, Entscheidung „bei dompdf bleiben" + Kriterien für einen späteren Wechsel); CSS-Typografie-Vertrag (orphans/widows/page-break-inside) als SOLL; der `page-break-inside`-Workaround darf nicht ohne Kenntnis des widows-FIXME entfernt werden (analog Security Risk Register).

### Verifikation & DoD (Freigabe erteilt)

- [x] Backend: `php artisan test` (alle grün, inkl. neuem `PdfTypographyTest`).
- [x] Frontend: `pnpm test:run` → `pnpm lint:fix && pnpm build` (0 Fehler; keine `any`/`@ts-ignore`/`eslint-disable`; Tailwind-Only + daisyUI-Skill beachten).
- [x] E2E: `npx playwright test --grep @feature:admin:documents` nach jedem Code-Change + `@smoke`-Suite.
- [x] Workflow eingehalten: Delegation an `general`-Subagenten (Implementierung) + separater Verifikations-Subagent; Tests explizit als TODOs mitgeführt (siehe oben); kein Commit/Push vor manueller User-Freigabe.

---

## 🔄 IN ARBEIT (2026-08-18) — Follow-ups aus dem User-Manual-Test

Zwei neue User-Requests nach dem manuellen Test der Task-A–E-Umsetzung. Implementierung delegiert an `general`-Subagenten (parallel), Verifikation durch separaten Subagenten inkl. `vision`-Check (AGENTS.md §5.5). Kein Commit/Push vor manueller User-Freigabe.

### Task F — „Baustein"-Select in der WysiwygEditor-Toolbar ersatzlos entfernen

**User-Request (2026-08-18):** „'Baustein' selekt ersatzlos entfernen bitte" — der Snippet-Select in der Toolbar von `frontend/src/ui/components/WysiwygEditor.tsx` (Z. 260–272: `{isSuperAdmin && !hideSnippets && (<select …>Baustein...</select>)}`) soll komplett weg. Snippets bleiben ausschließlich über das **Slash-Menü** („/") nutzbar.

**Analyse (verifiziert):**
- Snippet-Daten kommen **nicht** per Prop, sondern intern via `useSWR('/api/management/text-snippets')` (Z. 66) — kein Caller betroffen.
- `useSWR`, `filteredSnippets`, `filteredSnippetsRef`, `applySnippet`, Slash-Menü (Z. 186–201, 357–369) werden **weiterhin** vom Slash-Menü gebraucht → bleiben.
- `hideSnippets` (Props-Interface Z. 22, Destructure Z. 64) wird NUR vom Select benutzt → nach Entfernen ungenutzt: Prop + Interface-Eintrag + `hideSnippets={true}` in `TextSnippetModal.tsx:80` entfernen.
- Tests: kein `Baustein`-Bezug in Vitest/E2E; `WysiwygEditor.test.tsx:115` (Spinner bei Snippet-Loading) bleibt gültig (SWR bleibt).
- i18n: `<Trans>Baustein...</Trans>` verschwindet → `pnpm lingui:extract && pnpm lingui:compile` für Catalog-Sync; check-i18n läuft im Build.

1. [x] Select-Block entfernen; `hideSnippets`-Prop (Interface + Destructure) + `TextSnippetModal.tsx`-Aufruf bereinigen; Slash-Menü unangetastet.
2. [x] Vitest `WysiwygEditor.test.tsx` grün; `pnpm lint:fix && pnpm build` (inkl. i18n-Extract/Compile); E2E `wysiwyg-editor.spec.ts` + `manual-documents.spec.ts` (Slash-Menü-Flow) grün.

**✅ VERIFIZIERT (2026-08-18, separater Verifikations-Agent):** Grep 0× `hideSnippets`/`Baustein...`, Slash-Menü intakt (`isSuperAdmin`, `filteredSnippets`, `applySnippet`), 585 vitest, lint/build 0, E2E 8/8 (wysiwyg-editor + manual-documents), `messages.po` `#~`-obsolet i18n-konform. Verbliebene 6× „Baustein"-Treffer sind bewusst erhaltene Funktionalität (Snippet-CRUD-Seite + Slash-Menü-Empty-State). Commit-Begründung (DoD §2): Select redundant zum Slash-Menü.

### Task G — Item-Zeile „Leistungen / Positionen": gequetschte/überlaufende Spalten (admin-manual-offer)

**User-Request (2026-08-18):** „Die einzelne Leistung / Position overflowt sehr stark, ist so unbenutzbar" — auf `http://localhost:4321/admin-manual-offer` (E2E-Stack).

**Analyse (diagnostiziert per Playwright + `vision`-Befund, 2026-08-18):**
- Datei: `frontend/src/ui/management/components/invoice/InvoiceItemsTable.tsx` (Z. 34: Row = `flex flex-col md:flex-row gap-3 items-start p-3 …`, 7 Spalten).
- Kein Page-Level-Horizontal-Scroll bei 820–1440px (scrollWidth == clientWidth), Row passt rechnerisch in die Card (overflowIntoCard = 0 bei 1100/960px).
- **Root Cause (per Vision bestätigt):** Die zwei Text-Spalten (`form-control flex-1 w-full`) teilen den Restplatz 50:50, kollabieren aber per Min-Content unterschiedlich: „Titel / Name" (AutocompleteInput) bei ≤1152px auf **82px** (bei 1440px: 245px), „Zusatz" 158px. Produktname „Alle Fotos (unbearbeitbare JPEG)" wird bei 1100px auf **„Alle Fo"** gekürzt (960px: „Alle ") → unbenutzbar. Autocomplete-Dropdown (`w-full` am 82px-Feld) wrappt den Namen auf **4 Zeilen** (auch der Preis steht einzeln darunter).
- **Fix-Plan (Vision-Empfehlung, Tailwind-v4-konform ohne Bracket-Klassen):**
  1. Row: `md:` → **`lg:`-Breakpoint** + `flex-wrap` (unter 1024px stapeln → volle Feldbreite; auf lg wrappen statt kollabieren): `flex flex-col lg:flex-row flex-wrap gap-3 items-start …`
  2. „Titel / Name": `flex-1` → **`flex-3 min-w-50`** (v4-dynamische Spacing-Skala, 200px Mindestbreite)
  3. „Zusatz": `flex-1` → **`flex-2 min-w-30`** (120px)
  4. Autocomplete-Dropdown (`AutocompleteInput.tsx`, Z. 129): **`min-w-72`** (288px) zusätzlich zu `w-full` → Produktname + Preis in einer Zeile lesbar; Regression checken (CRM-Autocomplete via E2E `manual-documents.spec.ts` „CRM autocomplete"-Test, da Shared Component).
  5. Feste Spalten behalten `shrink-0`; Label/Input-Struktur je Feld unverändert (E2E-Locators `.form-control` mit Label-Text bleiben gültig); kein `overflow-hidden` auf der Row.
3. [x] Row-Layout in `InvoiceItemsTable.tsx` robust umbauen (Mindestbreiten + Wrap); bestehende Props/Logik unverändert.
4. [x] Bestehende Tests grün (Vitest-Suite; E2E `manual-documents.spec.ts` — befüllt Item-Zeilen); `pnpm lint:fix && pnpm build`; visuelle Verifikation per `vision`-Subagent (Screenshots vorher/nachher, 960/1100/1440px).

**✅ VERIFIZIERT (2026-08-18, 3 Iterationen mit je separater Verifikation):**
- **Finaler Stand:** Row = `xl:flex-row flex-wrap` (unter 1280px gestapelt), „Titel / Name" `flex-3 min-w-50`, „Zusatz" `flex-2 min-w-30` + Label `whitespace-normal`, Dropdown `min-w-72` (288px).
- **Finale Messungen:** 960/1100px gestapelt (Titel-Feld 466–606px, voller Text), 1280px einzeilig (Label-Abstand `Menge`−`Zusatz`: **+12px**, vorher −16px Kollision), 1440px einzeilig ausgewogen; Dropdown einzeilig ohne Überstand.
- **Unabhängige Verifikationen:** 585 vitest, lint/build 0, E2E manual-documents 4/4 + wysiwyg-editor 4/4 + `@smoke` 58/58; CSS-Klassen (`.flex-3`/`.flex-2`/`.min-w-*`) im generierten CSS bestätigt; kein `any`/`@ts-ignore`/`eslint-disable`, keine Bracket-Klassen.
- **Backlog (cosmetic, optional, keine Aktion):** bei 1280px/1440px schneiden lange Texte im `<input>` intern ab (Standard-Verhalten, voller Text bei Fokus) — ggf. später `min-w`-Feinjustierung; „Gesamt"/Löschen-Button leichte Vertikal-Offsets (beabsichtigt durch `mt-7`/`self-center`).

### Task H — „Absatz"-Dropdown in der Editor-Toolbar schmaler

**User-Request (2026-08-18):** „Absatz Dropdown weniger breit machen wenn möglich" — der Heading-Select in `WysiwygEditor.tsx` (ca. Z. 260: `className="select select-sm select-bordered"`, `aria-label="Überschrift"`, Optionen `Absatz` / `Überschrift 1–4`) hat Auto-Breite.

**Root Cause (gemessen + in `node_modules/daisyui/components/select.css` verifiziert):** daisyUI 5.6.13 setzt auf `.select` `width: clamp(3rem, 20rem, 100%)` → ohne Breiten-Klasse immer **320px**, obwohl „Absatz" nur ~45px Text braucht.

**Fix (verifiziert):** `w-32` (128px) → längste Option „Überschrift 4" (85px Text + 40px Padding = 125px) passt ohne Clip; gemessen 320px → **128px** (−60 %), `scrollWidth` = `clientWidth` auch mit „Überschrift 4" selektiert.

5. [x] Select-Breite anpassen; `pnpm lint:fix && pnpm build`; kurzer E2E-Smoke (wysiwyg-editor.spec.ts) + Vision-Check geöffnetes Dropdown.

**✅ VERIFIZIERT (2026-08-18):** 585 vitest, lint/build 0, E2E wysiwyg-editor 4/4 + `@smoke` 58/58; Vision-Befund: Select ~128px, „Absatz" vollständig lesbar, fügt sich harmonisch in die Toolbar ein. Kein Commit/Push vor User-Freigabe.

### Task I — Item-Zeile: „Menge" + „Preis / Stück" in eine Zeile (ein Zeilen-Slot)

**User-Request (2026-08-18):** „Menge und Preis pro Stück kann in eine Zeile" — die zwei kleinen Zahlenfelder der Leistungs-Zeile sollen eine Einheit bilden (sichtbar gruppiert, ein Flex-Slot statt zwei, spart einen Spalten-Gap und verhindert getrenntes Wrappen).

**E2E-kritische Randbedingung (geprüft):** `manual-documents.spec.ts` (Z. 90/91/136/137) + `contracts.spec.ts` (Z. 50) filtern `.form-control` mit `hasText: 'Menge'` bzw. `'Preis / Stück'` und nehmen `input .first()`. → **KEINE Verschmelzung zu EINEM `.form-control`** (Filter griffe aufs falsche Input). Lösung: beide `.form-control`s (Labels „Menge" / „Preis / Stück" unverändert) in einen gemeinsamen Flex-Wrapper (`flex flex-row gap-2 shrink-0`) — Locators bleiben exakt gültig.

### Task J — „Rabatte & Abzüge": gleiches Kollaps-Problem wie die Item-Zeile

**User-Request (2026-08-18):** „Gleiches Problem bei den Rabatten und abzügen" — `InvoiceDiscountsSection.tsx` hat exakt das alte, bereits verifiziert gefixte Muster: Row `flex flex-col md:flex-row` (Z. 40) + „Titel / Beschreibung" `flex-1 w-full` (Z. 74) → kollabiert bei schmalem Content auf Min-Content (Autocomplete-Input ~82–100px, Dropdown schmal). Fix spiegelt Task G: `md:` → `xl:` + `flex-wrap`; Titel `flex-3 min-w-50`. „Art" (`md:w-1/4`) / „Wert" (`md:w-32`) bleiben — gleiche bewährte Fixed-Breiten-in-Stack-Modus-Semantik wie die Item-Fixed-Spalten (`md:w-28`).

Beide Komponenten werden auch in `ManagementContractView.tsx` genutzt → Fix wirkt dort automatisch (contracts.spec.ts deckt `Preis / Stück`-Locator ab, bleibt gültig).

6. [x] Task I: Menge+Preis-Wrapper in `InvoiceItemsTable.tsx`; Task J: Discount-Row `xl:flex-row flex-wrap` + Titel `flex-3 min-w-50` in `InvoiceDiscountsSection.tsx`; Locators/Labels unangetastet.
7. [ ] Vitest + `pnpm lint:fix && pnpm build` + E2E `manual-documents.spec.ts` + `contracts.spec.ts` (+ `@smoke`); Vision-Check Screenshots (Item-Zeile 1280px, Rabatt-Zeile 1100/1280px).

**Zwischenstand I+J (2026-08-18, Implementierer-Bericht):** Diff wie spezifiziert (Wrapper ohne `form-control`-Klasse, Labels unverändert; Discount-Row `xl:flex-row flex-wrap` + `flex-3 min-w-50`). 585 vitest, lint/build 0, E2E manual-documents + contracts **18/18** (2 Projects × 8 Tests). Messungen: Menge/Preis gleiche y=434 (eine Zeile); Discount-Titel 1280px=371px, 1100px=656px gestapelt. Screenshots im Temp-Ordner. **Ausstehend:** separate Verifikation (Verifikator ≠ Implementierer) + Vision-Check (§5.4/§5.5).

### Task K — Löschen-Buttons rechtsbündig statt linksbündig

**User-Request (2026-08-18):** „löschen rechtsbündig statt linksbündig" — im **gestapelten Modus** (< 1280px Viewport) hängen die Trash-Buttons der Item- und Rabatt-Zeilen linksbündig unter den Feldern. Fix: `ml-auto` auf beide Buttons (`InvoiceItemsTable.tsx` Z. ~132, `InvoiceDiscountsSection.tsx` Z. ~119: `btn btn-sm btn-ghost text-error shrink-0 mt-7` → `+ ml-auto`). In Column-Mode schiebt der Auto-Margin den Button rechts (rechtsbündig); in Row-Mode ist er bereits letztes Element → kein visueller Unterschied. E2E-locator-sicher (Buttons werden per `getByRole`-Name bzw. Icon adressiert — nur Klassen ändern).

8. [x] Task K: `ml-auto` auf beide Löschen-Buttons; Regression: vitest/lint/build + E2E manual-documents/contracts; Screenshot gestapelter Modus (1100px) für Vision-Check. **Verifiziert (2026-08-18):** 585 vitest, lint/build 0, E2E 18/18; Trash-Button-End x=997/1022 vs. Row-Ende 1010/1035 (Δ13px = Innenrand, rechtsbündig) @ 1100px.

### Task L — Rabatt-Zeile: „Art w-full", Preis/Stück ≥ 0, „Gesamt"-Spalte (= Wert der Zeile)

**User-Requests (2026-08-18):**
- „bei rabatten art w-full" → Art-Select im Stack-Modus volle Breite (aktuell `md:w-1/4` = 25% auch bei 768–1279px Viewport).
- „positiver preis / stück also ≥0 wäre wichtig" → Item-Zeile „Preis / Stück"-Input hat kein `min="0"` (Rabatt-Wert hat es) → ergänzen.
- „Mit Gesamt meine ich den Wert der Zeile. Den haben wir bei Leistungen, aber nicht bei Rabatten" → Rabatt-Zeile bekommt eine **„Gesamt"-Spalte** (wie Items): effektiver €-Wert der Zeile. Fixer €-Rabatt: `price` direkt; %-Rabatt: `subtotal × price/100` (Dokumentation: Sequenz-Ketten mehrerer Rabatte werden bewusst NICHT nachgebildet — die TotalBox bleibt autoritativ; subtotal = Σ items price×qty, kommt als neue Prop aus beiden Views, die es bereits berechnen: `useInvoiceDraft`-return Z. 283, `ManagementContractView` Z. 260).

**Dateien:** `InvoiceDiscountsSection.tsx` (Art `w-full xl:w-1/4 shrink-0`, Wert `w-full xl:w-32 shrink-0`, neue Gesamt-Spalte `w-full xl:w-28 shrink-0` mit Label „Gesamt" + Display `text-right font-mono font-bold mt-1 text-base-content`, neue Prop `subtotal: number`), `InvoiceItemsTable.tsx` (`min="0"` am Preis-Input, `ml-auto` am Trash), `InvoiceDiscountsSection.tsx` (`ml-auto` am Trash), `ManagementManualInvoiceView.tsx` + `ManagementContractView.tsx` (`subtotal={subtotal}` verdrahten). E2E-Locators unberührt (kein Spec nutzt „Gesamt"-Filter; `Titel / Beschreibung` via `.last()` gültig).

9. [x] Task L: Klassen/Prop/Gesamt-Spalte wie oben; vitest/lint/build + E2E manual-documents/contracts; Messung (%-Rabatt zeigt berechneten €-Wert, fixer €-Rabatt zeigt price) + Screenshots für Vision. **Verifiziert (2026-08-18):** 585 vitest, lint/build 0, E2E 18/18; Rabatt-Gesamt: fix €100 → „100.00 €", 10 % von 3198,00 → „319.80 €"; Art/Wert/Gesamt @ 1100px w-full (656px = Innenbreite). **Zusatzfix (direkt, ohne Delegation nach User-Anweisung):** Menge/Preis-Wrapper füllt im Stack die ganze Zeile (`w-full xl:w-auto` + `flex-1 xl:flex-none`) — gemessen 1100px: 606px (299/299), 1440px: 200px (80/112); E2E manual-documents 4/4.

---

## 🟡 OFFEN — Kanban: PDF-Drop auf Projekte-Seite — E2E-Test fehlt

Funktionalität vorhanden via `useProjectPdfDrop` (vorbefüllt client_name/email/amount/package, verdrahtet in `ManagementProjectsBoard.tsx`). **Offen: E2E-Verifikation** — kein Test in `frontend/tests/e2e/admin/projects-board.spec.ts`.

---

## 🟡 OFFEN (Future) — pricing_strategy als Brand-Setting in der UI konfigurierbar

**Kontext (Entscheidung 2026-08-04):** Coupon-Feature ist vom Lizenzmodus entkoppelt („offer both" — beide Lizenzmodelle immer mit Coupons; Gates in `Sidebar.tsx`/`ManagementCouponsView.tsx`/`CouponInput.tsx` entfernt). Langfristig soll das Brand-weite `pricing_strategy` (inkl. Coupon-Verfügbarkeit) per **Admin-UI konfigurierbar** sein.

**Umsetzung (Future-TODO, dokumentiert in `features/infrastructure/17-pricing-strategy-pattern.md` §7):**
- DB-Overlay-Muster (Option B, wie F3): `settings`-Tabelle (PK `(key, brand)`, V019), `config/brands.php` = Default/Fallback.
- Setting `pricing_strategy` je Brand in der Admin-UI editierbar (`BrandSettingsService`/`SettingsController`, Choke-Point `BrandRegistry::buildFromArray()`).
- Per-Gallery-Override (F2, `galleries.licensing_mode`) bleibt und hat Vorrang vor dem Brand-Setting.

---

## 🟡 OFFEN (manuell) — Prod-Infra-Nacharbeiten

- **portal-base:8.5 (2026-08-07):** `docker-compose.yml` referenziert jetzt `portal-base:8.5` → **Portainer Stack-Redeploy nötig** (User-Notify erledigt). Rest des Plans (Dockerfile, base-image.yml, CI-Referenzen, Base-Repo-Löschung, GHCR-Cleanup) erledigt.

---

## 📋 AUSGEARBEITETE BACKLOG-PLÄNE (2026-08-04)

> Nur Ausarbeitung (Planung). Umsetzung erfolgt in separaten Sessions durch Implementer-Subagenten + Review durch Verifikator (AGENTS.md §4). Offene Fragen wurden interaktiv geklärt (2026-08-04) und sind in den Plänen als Entscheidungen dokumentiert. **A1 Schritt 1 wurde umgesetzt (Commit `e44f6dd`, 2026-08-13).**

---

### 🔙 A1 — User-God-Entity entschärfen + Role-Prüfungen konsolidieren

**Status:** Schritt 1 erledigt (Commit `e44f6dd`, 2026-08-13: `AccessControlService` → `AuthorizationService`, additiv, 150 Scoped-Tests grün). **Offen: Schritte 2–7.**

**Ziel:** Rollen-/Autorisierungslogik aus `backend/app/Models/User.php` in einen **konsolidierten `AuthorizationService`** (Umbenennung von `AccessControlService`, Entscheidung 2026-08-04) überführen; Scatter (~170 direkte `is_*`-Prüfungen in 20+ Dateien) beseitigen; N+1 Role-Queries beheben. Serialisierung (`$visible`, `AuthController::me()`, `UserResource`) bleibt unverändert → kein API-Break.

**Bestandsaufnahme (Kern):**
- God-Entity: `User.php:24–128` — `getIsSuperAdminAttribute` (24–27), `getIsPendingAttribute` (80–84), `getIsPhotographerAttribute` (86), `getIsAdminAttribute` (87), `getIsOrgAdminAttribute` (88), `getIsPowerUserAttribute` (91), `getAllowedGalleryIds` (93–96), `canPhotographerAccessGallery` (98–117), `canAccessGallery` (119–128).
- `hasPurchasedPhoto` (130–162) = Kauf-/Bestelllogik, **kein Rollen-Thema** → bewusst NICHT Teil von A1.
- Scatter gruppiert: `is_admin` 53× (Controllers, Policies, Requests, Provider), `is_super_admin` 34×, `is_photographer` 41×, `is_org_admin` 18×, `canAccessGallery` 16×, `canPhotographerAccessGallery` 7×, `getAllowedGalleryIds` 8×.
- **Rekursions-Falle:** `AuthorizationService` (vormals `AccessControlService.php:57`) ruft `$user->is_photographer` (Model-Accessor!) → sobald Accessor → Service delegiert, rekursiv. MUSS mitfixiert werden.
- Gates in `AppServiceProvider.php:72–97` (`manage-catalog`, `manage-users`, `purchase-upgrades`) nutzen rohe `pluck('name')`-Blocklisten.

**SOLL-Architektur:** Konsolidierter **`AuthorizationService`** (vorher `AccessControlService`) mit `hasRole(User, ...roles)`, `roleNames(User)`, `isSuperAdmin/isAdmin/isPhotographer/isPowerUser/isOrgAdmin/isPending/isClient/isPrivileged(User)`, `canAccessGallery(User, id)`, `canPhotographerAccessGallery(User, id)`, `canManageGallery(User, id)` (Komposit). **Regel: Service referenziert NIE `$user->is_*`-Accessor** (Rekursionsschutz); alle Prädikate via `$user->loadMissing('roles')`. Model wird dünne Delegation (1-Zeilen-Delegates), Relations bleiben.

**Priorisierte Migrationsschritte (jeder einzeln grün testbar):**
1. ~~Service erweitern + umbenennen in `AuthorizationService` (rein additiv, keine Caller-Umbauten) — `AuthorizationServiceTest`~~ ✅ **erledigt (e44f6dd)**
2. Model auf Delegation umstellen — Guard: `AuthorizationTest`, `UserPermissionLogicTest`, `GalleryTreeServiceTest:304`.
3. Gates konsolidieren + Semantik verfeinern (`AppServiceProvider`) — Guard-Tests der Ist-Bool-Ergebnisse + neue `isClient`/`isPrivileged`.
4. Middleware (`SuperAdminMiddleware:14`, `ManagementMiddleware:21,28,37`) — Guard: `RoleAbortTest`.
5. Policies (`GalleryPolicy:15,20`, `PhotoPolicy:13,20,27,38,44,50` — 5×-Komposit `is_super_admin||is_admin||(is_photographer&&canPhotographerAccessGallery)` → `canManageGallery`).
6. Controller nach Fachgebiet (6a–6f, je eigener Commit): User/Org → Gallery/Frontend/Image → PhotoDownload/FileDelivery → Search/Mail/Notification/CheckoutService → Requests → Rest.
7. (Optional) `hasPurchasedPhoto` als eigener Backlog-Item extrahieren — NICHT in A1.

**Test-Strategie:** `AuthorizationServiceTest` (alle Prädikate, Prezedenz, Guest), neuer N+1-Regressionstest, bestehende Suiten als Guard (s. Schritt 2), E2E-@smoke nach jedem Schritt.

**Risiken:** Verhaltensdrift (`isAdmin` ⊃ `SuperAdmin`, `canAccessGallery`-Prezedenz), Model↔Service-Rekursion, Cache-Korrektheit (`unrestricted_photographer_gallery_ids`), `MailController.php:83–84` (`whereHas('roles')` ist Query, nicht pro-User-Check — darf NICHT in Service).

**Entscheidungen (interaktiv geklärt 2026-08-04):**
1. **Accessor bleiben dauerhaft** als dünne 1-Zeilen-Delegates im Model (kein API-Break, kein Frontend-PR). `$visible`/`me()`/`UserResource` unverändert. KEINE 2. Phase.
2. **Umbenennen in `AuthorizationService`** (statt `AccessControlService`) — inkl. Test-Datei (`AccessControlServiceTest.php` → `AuthorizationServiceTest.php`) und allen Referenzen. ✅ erledigt
3. **`ManagementMiddleware`: Path-Prefix-Logik bleibt in der Middleware**; nur die Rollen-Prädikate werden über den Service aufgelöst.
4. **Controller-Migration (Schritt 6): eigene Commits je Fachgebiet (6a–6f), direkter Push nach master wenn Tests grün**; wo fachlich unabhängig, parallel an Subagenten delegieren, aber Commits sauber splitten.
5. **Gates: Semantik verfeinern** (nicht 1:1) — `isClient`/`isPrivileged` und die Gate-Definitionen (`AppServiceProvider:79–97`) werden logisch neu modelliert; bestehende Bool-Ergebnisse je Gate vor der Umsetzung als Guard-Test einfrieren.

---

### 🔙 F3 — Admin-UI für Brand-Einstellungen (nur Settings, kein Full-CRUD)

**Ziel:** Admin-UI zum Editieren konfigurierbarer Brand-Felder. **Architektur-Entscheidung (getroffen):** Option **B — DB-Overlay** auf bestehender `settings`-Tabelle (PK `(key, brand)`, V019); `config/brands.php` bleibt Default/Fallback. Config-Write-Layer (Option A) verworfen (nicht haltbar / Deploy überschreibt / kein Audit). Kein Migration-Bedarf (V028 bleibt frei).

**Bestandsaufnahme (Kern):**
- Overridable Whitelist: `name`, `portal_name`, `impressum_url`, `primary_color`, `secondary_color`, `frontend_url`, `from_address`, `from_name`, `accounting_email`, **`features.orgs`** (Entscheidung 2026-08-04). Config-only (NICHT overridable): `theme`, `logo_path*`, `hostnames`, `is_active`. Dead-Flags `features.coupons`/`features.volume_licensing` werden aus `config/brands.php` entfernt (real schaltet `pricing_strategy`).
- Einbaupunkt: `BrandRegistry::buildFromArray()` (`BrandRegistry.php:156–177`) = Choke-Point aller 23 BrandConfig-Konsumenten (Middleware, Mails, Queue, public `brand-config`).
- Queue: `BrandRegistry::clearCache()` zusätzlich im `Queue::before`-Hook (`AppServiceProvider.php:123–125`).
- UI-Muster: `BillingDetailsCard.tsx` (react-hook-form + zodResolver, Load→Reset-Hydration, `disabled={!canEdit}`, showToast). Einbau als Card in `ManagementSettingsView.tsx:63–68`. Kein neuer Sidebar-Eintrag/Route nötig.

**SOLL-API-Vertrag** (`routes/api.php` im `management`-Block):
- `GET /api/management/brand-settings` → `{brands:[{id, editable_fields, defaults, overrides, effective}]}`.
- `PUT /api/management/brand-settings/{brand}` (+ `super_admin` Middleware, Muster `api.php:183`) → partial Payload; `null`-Wert = Reset auf Config-Default (löscht DB-Row). Response `{success, effective}`.
- Validierung: `StoreBrandSettingsRequest` (Route-Brand `Rule::in(array_keys(config('brands')))`; hex `regex:/^#[0-9a-fA-F]{6}$/`, email, url; Whitelist via `only()`). Frontend-Zod-Schema spiegelnd.
- Write via `BrandSettingsService` (neuer Service) mit `Setting::updateOrCreate(['key','brand'])` — NICHT `SettingResolver::set()` (der hängt am Host-Kontext).

**Umsetzungsplan (priorisiert):**
1. `BrandSettingsService` + Merge in `buildFromArray()` + Queue-Cache-Clear — `BrandSettingsServiceTest`, `BrandRegistryTest`.
2. Endpoint: `StoreBrandSettingsRequest`, `SettingsController::getBrandSettings/updateBrandSettings`, 2 Routen — `BrandSettingsControllerTest`.
3. Frontend: `useBrandSettings.ts` + Vitest.
4. `BrandSettingsCard.tsx` + Einbau in `ManagementSettingsView.tsx` — `pnpm lint:fix && pnpm build`.
5. Playwright `brand-settings.spec.ts` (`@feature:admin:brand-settings`).
6. Doku: `21-brand-config-driven.md` (§Follow-up F3 → Implementiert), `features/infrastructure/22-brand-settings-overlay.md` (SOLL, nach Implementierung).

**Test-Strategie:** PHPUnit (401/403/200-Matrix, Persistenz mit brand='rp' + `brand_config.*`-Key, 422-Fälle, null-Reset, Merge-Precedence, public `brand-config` reflektiert Override, kein Cross-Brand-Leak); Vitest (Hook, Zod-Schema); Playwright (Super-Admin ändern→persistiert, ungültige Hex→Client-Validierung, Reset, Plain-Admin read-only).

**Entscheidungen (interaktiv geklärt 2026-08-04):**
1. **features.orgs wird DB-overridable** (Whitelist erhält `features.orgs`; Sidebar-Gating `Sidebar.tsx:110` wird darüber gesteuert). **Dead-Flags `features.coupons` + `features.volume_licensing` werden aus `config/brands.php` entfernt** (real schaltet das DB-Setting `pricing_strategy` — `ManagementCouponsView.tsx:85`). `theme` bleibt config-only (Frontend-Theme-Abhängigkeit).
2. **Logos (`logo_path*`) bleiben config-only** (Upload = eigener Scope, nicht F3).
3. **Audit: Ja — einfacher Laravel-Log-Eintrag** bei jedem Brand-Settings-Write (User, Brand, geänderte Felder), da `Setting::$timestamps = false`.

---

### 🔙 Stack-Konsolidierung — ❌ OBSOLET / SUPERSEDED (2026-08-18)

**Nicht mehr gültig:** Die 2026-08-04 ausgearbeitete Richtung (ein MariaDB-Compose mit generischen Namen, 3306/7700/8025+1025) wurde durch die **SQLite-Richtung** abgelöst: Backend läuft lokal/test auf **SQLite** (`DB_CONNECTION=sqlite`, Tests `:memory:`, Commits `3144fb9` „local + test backend on SQLite", `3e2d823` „E2E-Isolation via separatem Backend + eigener SQLite-DB"), Mailpit nativ via Homebrew (`0a897a6`, `MAIL_PORT=1025`), `docker-compose.test.yml` betreibt nur noch Meilisearch. `scripts/e2e-up.sh` existiert und ist idempotent. Details der alten Planung sind nicht mehr heranzuziehen; bei künftiger Infra-Arbeit gilt der Ist-Zustand (SQLite + Homebrew-Mailpit + Meili-Container).