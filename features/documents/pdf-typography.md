# PDF Typografie — Schusterjungen / Hurenkinder (orphans / widows)

> **Spec (Soll-Zustand).** Quelle für den CSS-Typografie-Vertrag aller PDF-Templates
> (`pdf.invoice`, `pdf.manual_offer`, `pdf.contract_signatures`), die
> dompdf-Engine-Entscheidung und den `page-break-inside`-Workaround.
> Stand: 2026-08-18.

## 1. Motivation

Die PDF-Generierung läuft über dompdf 3.x (`barryvdh/laravel-dompdf`) mit
Blade-Templates. dompdf hat eine Lücke bei der Absatz-Typografie:

- **`orphans` wird erzwungen** (`vendor/dompdf/dompdf/src/FrameDecorator/Page.php`,
  Break-Entscheidung `if ($line_number <= $parent_style->orphans)` ca. Z. 419–420).
- **`widows` wird NICHT erzwungen** — Vendor-FIXME
  `Page.php:426–427`: *"Checking widows is tricky without having laid out the
  remaining line boxes. Just ignore it for now..."*.

Ohne Gegenmaßnahme können WYSIWYG-Absätze (Tiptap: `terms_html`,
`custom_conditions`) so über Seitengrenzen gerissen werden, dass auf der
Folgeseite nur 1–2 Zeilen (Hurenkinder) stehen.

## 2. Engine-Entscheidung: bei dompdf bleiben

| Engine | orphans / widows | Bewertung |
| --- | --- | --- |
| **dompdf 3.x** (gewählt) | `orphans` ✓ (Page.php ~Z. 419–420), `widows` ✗ (FIXME Z. 426–427) | Eingeführt, Tests vorhanden, kein Wechsel nötig |
| **mPDF** | Keine orphans/widows-Unterstützung — mPDF-Manual: *"does not have 'widows' or 'orphans' protection"* (Issue mpdf/mpdf#48, seit 2015 offen, PR #71 nie gemerged) | Kein Vorteil, kompletter Renderer-Wechsel nötig |
| **dragonofmercy/phppdf** | Imperative API, keine orphan/widow-Logik (~109 Packagist-Installs) | Kaum verbreitet, gleiche Lücke |
| **Headless-Chromium / Browsershot** | Einziger voller Support (Chromium-Layout) | Schwergewicht (Node/Browser-Infrastruktur im Container), Overkill für die drei Dokument-Templates |

**Entscheidung (2026-08-18):** dompdf bleibt. Die widows-Lücke wird über den
CSS-Typografie-Vertrag (§3) kompensiert — kein Renderer-Wechsel.

## 3. CSS-Typografie-Vertrag (SOLL)

Gültig für alle drei PDF-Templates. Zentrale Quelle:
`backend/resources/views/pdf/fragments/styles.blade.php` (wird von allen drei
Templates im `<head>` includiert).

```css
body { orphans: 2; widows: 2; }
.editor-content p, .editor-content li { page-break-inside: avoid; }
h1, h2, h3, h4, h5, h6 { color: {{ $secondaryColor }}; page-break-after: avoid; }
```

- `orphans: 2` greift in dompdf sofort (Break-Regel Page.php).
- `widows: 2` dokumentiert die Intention; dompdf ignoriert den Wert (FIXME) —
  die eigentliche Kompensation liefert die nächste Regel.
- `.editor-content p, .editor-content li { page-break-inside: avoid; }` —
  WYSIWYG-Absätze/-Listenelemente werden nie über Seitengrenzen gerissen.
  Das kompensiert den dompdf-widows-FIXME vollständig für die betroffenen
  Inhalte (kurze Absätze passen immer auf eine Seite; nur sehr lange Absätze
  > 1 Seite könnten theoretisch verschoben werden — in der Praxis nicht
  relevant für Sonderkonditionen-/Vertragstexte).
- `page-break-after: avoid` auf Headings verhindert überschrift-allein-am-
  Seitenende.

**Verbot (SOLL):** Kein `page-break-inside: avoid` auf großen Blöcken (Items-
Tabelle, lange Inhalte) — das erzeugt Paginierungslücken (Block wird komplett
auf die nächste Seite geschoben). Ausnahme: die schmale Invoice-Totals-Zeile
(Wrapper im Invoice-Template, Paginierungsschutz) und das kompakte
`.audit-section`-Block-Element im Vertrags-Template.

## 4. Template-Deduplizierung (Struktur-SOLL)

Die drei Templates duplizierten Items-/Totals-Markup und `<style>`-Blöcke; das
Fragment `pdf/fragments/items_table.blade.php` war tot. SOLL-Zustand:

| Datei | Inhalt |
| --- | --- |
| `pdf/fragments/styles.blade.php` | Einziger `<style>`-Block: Body-Grundlagen, `.invoice-details`, `h1–h6`, `table.items`, `.editor-content`, `.footer`; Parameter `$primaryColor`, `$secondaryColor`, optional `$footerAbsolute` (Vertrag: `false`) |
| `pdf/fragments/items_table.blade.php` | Items-`<tbody>` + Zwischensumme + `discount_rows` + Gesamtbetrag (Rechenlogik `$subtotal`/`$hasDiscounts` NUR hier); Parameter `$items`, `$totalLabel`, `$totalGross`, `$invoiceMode` (Tier-/„Auflösung"-Rows + Spaltenkopf „Preis / Stück" nur Invoice), `$showSubtotal` (Default: nur bei Rabatten), `$separateTotals` (Invoice: eigene Totals-Tabelle im `page-break-inside: avoid`-Wrapper) |
| `pdf/fragments/discount_rows.blade.php` | Unverändert (Rabatt-Rows), wird vom Items-Fragment includiert |
| `pdf/invoice.blade.php`, `pdf/manual_offer.blade.php`, `pdf/contract_signatures.blade.php` | Includieren Styles- + Items-Fragment; keine eigenen `<style>`-Dupes mehr (nur Vertrag-spezifische Regeln bleiben lokal: `.signature-section`, `.audit-section`, `.section-title`) |

## 5. Warnung — `page-break-inside`-Workaround (analog Security Risk Register)

Der Workaround `.editor-content p, .editor-content li { page-break-inside:
avoid; }` darf **nicht entfernt werden**, ohne den dompdf-widows-FIXME zu
kennen (`vendor/dompdf/dompdf/src/FrameDecorator/Page.php:426–427`). Sobald
dompdf `widows` nativ unterstützt (Upstream-Fix), kann die Regel entfallen
oder auf `widows: 2` reduziert werden — bis dahin ist sie die einzige
Kompensation gegen Hurenkinder in WYSIWYG-Inhalten. **Nicht regredieren.**

## 6. Tests

`backend/tests/Feature/PdfTypographyTest.php` (Feature, SQLite `:memory:`,
läuft ohne laufenden Server):

- Data-Provider über alle 3 Templates: gerendertes HTML enthält `orphans: 2`,
  `widows: 2`, die `.editor-content p/li`-`page-break-inside`-Regel, die
  Brand-Farben (`BrandRegistry::configOrDefault()`), **kein** `#fcfcfc` /
  `border: 1px solid #eee` (Box entfernt), übergebene `<p>`-Inhalte im
  `.editor-content`-Bereich; Gesamtbetrag-Label + formatierter Betrag genau
  einmal (`substr_count` — Dedup-Regression).
- API-Streams (`/api/management/invoices/manual`): Invoice- und
  Offer-PDF beginnen mit `%PDF-1.`; das Angebot enthält `%OFFER_JWT:`.
