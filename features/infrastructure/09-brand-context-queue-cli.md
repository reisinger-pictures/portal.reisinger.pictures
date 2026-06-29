# Brand-Context in Queue-/CLI-Kontexten — Konzept (SOLL/Ist-Stand)

> **Status:** Beschreibt den **Ist-Stand** (Problem) und den **Soll-Zustand** (Ziel), keine
> Implementierungsschritte. Verknüpft: `AGENTS.todo.md` T-02, `features/infrastructure/06-multi-domain-branding.md`,
> `features/infrastructure/08-tenant-brand-concept.md`.
> Erstellt 2026-06-29. **P0 — kundenwirksam.**

## 1. Kontext

Das Portal unterscheidet zur Laufzeit zwei White-Label-Brands über den HTTP-Host
(`BrandContextMiddleware`): `reisinger.pictures` (B2B) und `all-the.rest` (B2C/ATR). Der Brand
steuert Branding (Theme, Logo, Wasserzeichen) und insbesondere **markenspezifische Bank-/Firmendaten**
im Rechnungs-PDF (ATR = `atr_`-Präfix in den Settings, B2B = kein Präfix).

## 2. Ist-Stand — Das Problem (Brand-Leck in Queue/CLI)

Der Brand wird **ausschließlich** über die HTTP-Middleware gesetzt
(`backend/app/Http/Middleware/BrandContextMiddleware.php:25` → `config(['app.brand' => $brand])`).
In **asynchronen Kontexten existiert kein HTTP-Request** → `config('app.brand')` ist undefiniert.

Konkret betroffen:

| Stelle | Auswirkung |
|--------|------------|
| `backend/app/Mail/InvoiceMail.php:28` (`build()`) | `ShouldQueue`-Mail liest `config('app.brand')` → leer im Worker → ATR-Rechnungen erhalten **B2B**-Branding und B2B-Bankdaten im PDF |
| `backend/app/Services/InvoiceService.php:68` | Sammelrechnung: Invoice-Nummer fest `P-` (B2B), kein ATR-Nummernkreis |
| `backend/app/Console/Commands/ProcessCollectiveInvoices.php:33-34` | Cron triggert `generateForTenant()` → dasselbe Leck |

**Folge (kundenwirksam):** Eine über `all-the.rest` getätigte Bestellung erhält per Mail ein
Rechnungs-PDF mit falschem Logo, falschen Farben und — kritisch — **falschen Bankdaten**
(B2B- statt ATR-Konto). Überweisungen landen auf dem falschen Konto.

## 3. Soll-Zustand

Der Brand muss **persistent** sein, damit er unabhängig vom Ausführungskontext (Request vs.
Queue vs. CLI) rekonstruierbar bleibt.

### 3.1 Persistenz auf Datenebene

- Neue Spalte `brand` (nullable, Default `reisinger.pictures`) auf:
  - `orders` — Marke, in der die Bestellung getätigt wurde.
  - `invoice_snapshots` — Marke, für die das PDF gerendert wird.
- Die Zuweisung erfolgt **bei der Entstehung** der Entität (Checkout/Invoice-Anlage), wo der
  Request-Brand (`config('app.brand')`) noch verfügbar ist.

### 3.2 Rekonstruktion im Queue/CLI

- `InvoiceMail::build()` liest den Brand **nicht** mehr aus `config('app.brand')`, sondern
  rekonstruiert ihn aus den persistierten Daten:
  `$snapshot->brand ?? $order->brand ?? 'reisinger.pictures'`.
- Damit sind Theme (Blade-Templates), Bankdaten (Settings mit `atr_`-Präfix) und bcc-Adresse
  im Worker korrekt markenspezifisch.

### 3.3 Markenspezifische Suffixe/Adressen

- **Invoice-Nummernkreis** pro Marke getrennt (z. B. B2B `P-`, ATR `AR-`), damit Nummern nicht
  zwischen Marken kollidieren.
- **bcc-Empfänger** brandabhängig: B2B `accounting@reisinger.pictures`, ATR über Setting
  `atr_accounting_email` (Default B2B-Adresse).

## 4. Abgrenzung

- **Keine** Verzahnung mit dem Tenant-Konzept auf Datenebene in diesem Schritt — der Brand
  wird aus dem Request-Kontext bzw. der Order/Snapshot gelesen. Eine optionale `tenants.brand`-
  Spalte ist in `08-tenant-brand-concept.md` als separates Thema geführt.
- Bestandsdaten (historische Orders/Snapshots ohne Brand) erhalten im Rahmen der Migration einen
  Backfill (Heuristik über Tenant-Zugehörigkeit des Users, Fallback `reisinger.pictures`).

## 5. Verifikation (später)

- Test: ATR-Order → im Queue-Pfad gerendertes PDF enthält ATR-Branding und ATR-Bankdaten
  (via `Bus::fake` oder direkter `InvoiceMail::build()`-Aufruf ohne Request-Kontext).
- Bestands-Backfill: Migration ist deterministisch testbar (`RefreshDatabase` + Assertions).
