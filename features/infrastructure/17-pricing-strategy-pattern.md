# Pricing Strategy Pattern — Architektur (Soll-Zustand)

> **Status:** Current (2026-07-14). Beschreibt die Architektur des Strategy-Patterns zur
> Entkopplung der Preislogik. Das SRP-Portal wurde entfernt (Commit `1831116`); der
> VolumeLicensingStrategy-Name bleibt als generische Volumen-Preislogik erhalten.
> Verknüpft: `AGENTS.todo.md` F5, `features/infrastructure/16-srp-volume-pricing.md` (historical),
> `features/infrastructure/21-brand-config-driven.md`.
> Erstellt 2026-07-01, Update 2026-07-14.

## 1. Kontext

Bisher gibt es eine einzige Preislogik in `PricingService::calculateItemPriceCents()`, die auf
`license_use_cases` und `license_modifiers` basiert (B2B-Modell). Für ein B2C-Portal wird ein
vollständig anderes Preismodell benötigt (mengenbasiertes Volumen-Pricing). Das ehemalige SRP-Portal
wurde entfernt (Commit `1831116`), aber die generische Volume-Logik bleibt als alternative Strategie
erhalten.

Um die Modelle sauber zu trennen und zukünftige Preismodelle zu ermöglichen, wird das
**Strategy-Pattern** eingeführt.

## 2. Architektur

### 2.1 Klassen-Diagramm

```
┌─────────────────────────────────────────────────────────────────┐
│                      PricingStrategy (Interface)                │
│  + calculateCart(array $items, User $user): array              │
└─────────────────────────────────────────────────────────────────┘
                                  ▲
                                  │ implements
                  ┌───────────────┴───────────────┐
                  │                               │
    ┌─────────────────────────────┐  ┌─────────────────────────────┐
    │   ScopeLicensingStrategy    │  │    VolumeLicensingStrategy  │
     │   (B2B / RP)                │  │   (generic volume)          │
    │                             │  │                             │
    │   Einzelitem-Preis via      │  │   Mengenbasiert, retroaktiv  │
    │   LicenseUseCase +          │  │   über gesamten Warenkorb   │
    │   LicenseModifier           │  │                              │
    └─────────────────────────────┘  └─────────────────────────────┘
                  ▲                               ▲
                  │                               │
    ┌─────────────┴───────────────────────────────┴─────────────┐
    │                   AppServiceProvider                       │
    │     bindet PricingStrategy brand-gesteuert via             │
    │     BrandRegistry::current()                               │
    └───────────────────────────────────────────────────────────┘
```

### 2.2 Schnittstelle

```php
namespace App\Contracts;

use App\Models\User;

interface PricingStrategy
{
    /**
     * @param array $items Jedes Item hat:
     *   - 'id' (int|string): Eindeutige ID (z.B. photoId)
     *   - 'license_use_case_id' (string): Lizenz-Use-Case-ID (nur RP)
     *   - 'license_modifier_ids' (array): Modifier-IDs (nur RP)
     *   - 'is_quote' (bool): Ist ein Angebots-Item
     * @param User $user Der bestellende Benutzer
     * @return array
     *   - 'items' (array): [
     *       'itemId' => int|string,
     *       'priceCents' => int,
     *       'tier' => string,
     *       'useCaseName' => string,
     *       'modifierNames' => array,
     *     ]
     *   - 'totalCents' (int): Summe aller items
     */
    public function calculateCart(array $items, User $user): array;
}
```

### 2.3 Strategien

#### ScopeLicensingStrategy (RP/B2B)
- Kapselt die bestehende Logik aus `PricingService::calculateItemPriceCents()`
- Berechnet jedes Item einzeln über `LicenseUseCase::findOrFail()`, Flatrate-Tier-Prüfung,
  Modifier-Surcharges
- Summiert die Einzelpreise auf
- Enthält die `guardBrand()`-Logik (Defense-in-Depth)

#### VolumeLicensingStrategy (generic volume)
- Zählt alle Nicht-Quote-Items
- Ermittelt den Volumen-Tier anhand der Gesamtmenge
- Wendet den Tier-Preis retroaktiv auf **alle** Nicht-Quote-Items an
- Quote-Items → 0 Cent
- Preise via `SettingResolver` konfigurierbar
- Fallback auf Hardcoded-Defaults (3000/2500/2000 Cents, Thresholds 10/20)

### 2.4 Dependency Injection

Die Bindung erfolgt im `AppServiceProvider::register()`:

```php
$this->app->bind(PricingStrategy::class, function ($app) {
    $strategy = Setting::where('key', 'pricing_strategy')
        ->where('brand', BrandRegistry::currentOrDefault())
        ->value('value') ?? 'scope_licensing';

    return match ($strategy) {
        'volume_licensing' => new VolumeLicensingStrategy($app->make(SettingResolver::class)),
        default => new ScopeLicensingStrategy(),
    };
});
```

### 2.5 Integration in bestehende Services

#### PricingService
- Erhält `PricingStrategy` per Constructor Injection
- `calculateItemPriceCents()` delegiert an `$this->strategy->calculateCart()` für Einzel-Item-Kompatibilität
- `guardBrand()` bleibt erhalten (wird von ScopeLicensingStrategy intern genutzt)

#### CheckoutService
- Erhält `PricingStrategy` per Constructor Injection (ersetzt `PricingService`)
- `processCheckout()` ruft **einmalig** `$strategy->calculateCart($items, $user)` auf
- Ergebnis liefert `items`-Array mit Preisen und `totalCents` für `orders.total_amount`

## 3. Vorteile

- **Trennung der Preismodelle**: B2B (scope-based) und B2C (volume-based) haben unabhängige Implementierungen
- **Erweiterbarkeit**: Neue Preismodelle können durch Hinzufügen weiterer Strategien integriert werden
- **Testbarkeit**: Jede Strategie kann isoliert getestet werden
- **Keine Brand-If-Abfragen**: Die Strategie-Auswahl erfolgt zentral im ServiceProvider

## 4. Resolution — Wie die Strategie ausgewählt wird

Die Strategy-Resolution erfolgt im `AppServiceProvider::register()` zur Laufzeit:

1. Die `pricing_strategy`-Einstellung wird aus der `settings`-Tabelle gelesen (brand-scoped via `BrandRegistry::currentOrDefault()`).
2. Der Wert steuert den `match`-Ausdruck, der die entsprechende Strategy-Instanz erzeugt.
3. Strategie-Hardcoding: `'volume_licensing'` → `VolumeLicensingStrategy`, alles andere → `ScopeLicensingStrategy`.

Interaktion mit der Brand-Architektur (`config/brands.php`):
- `config/brands.php` enthält ein `features.volume_licensing`-Flag, das dokumentiert, ob eine Brand die Volume-Logik nutzen *kann*.
- Die tatsächliche Runtime-Entscheidung liegt in der `settings`-Tabelle (brand-scoped), nicht in der Config-Datei. Das erlaubt Runtime-Umschaltung ohne Code-Deployment und ist die Grundlage für den geplanten Per-Gallery-Override (F2).

```
features/brands.php (doc flag)
      │
      ▼ (documentation only)
settings.pricing_strategy → AppServiceProvider → PricingStrategy binding
      │
      ▼ (brand-scoped)
VolumeLicensingStrategy or ScopeLicensingStrategy
```

## 5. Planned: Per-Gallery Override (F2)

> **Status:** Geplant, siehe `AGENTS.todo.md` F2. Nicht implementiert.

### Ziel
Galleries sollen einen eigenen `licensing_mode` erhalten, der das Brand-weite `pricing_strategy`-Setting überschreibt.

### Ist-Zustand
- `pricing_strategy` ist ein globales Brand-Setting → alle Galleries einer Brand teilen denselben Modus.
- `Gallery`-Model hat keine Licensing-Spalte.
- Frontend-Hook `useLicensingMode()` liest den Modus ohne Gallery-Kontext.

### Geplante Änderung

**a) Migration:** `licensing_mode VARCHAR(20) NULL` auf `galleries` (null = Brand-Setting gilt, Werte: `'scope_licensing'`, `'volume_licensing'`).

**b) Resolution refactorn:** Die aktuelle DI-Bindung im `AppServiceProvider` ist request-scoped und kann nicht pro Cart-Item entscheiden. Ansätze:
- `CheckoutService` gruppiert Items nach `licensing_mode` der zugehörigen Gallery und ruft `calculateCart()` pro Gruppe auf.
- Oder: `PricingStrategy` um eine `calculateItem()`-Methode erweitern.

**c) Mixed-Cart-Problem:** Ein Cart kann Items aus Galleries mit unterschiedlichen Modes enthalten. Lösung s.o. — Gruppierung im `CheckoutService`.

**d) Frontend:** `useLicensingMode(galleryId?)` erweitern, `GET /api/settings/license-terms?gallery_id=X` Endpoint.

## 6. Strategy History (for traceability)

1. **2026-07-01:** Dokument erstellt — zwei Strategien: `ScopeLicensingStrategy` (RP/B2B) und `VolumeLicensingStrategy` (SRP/B2C).
2. **2026-07-14 (Commit `1831116`):** SRP-Portal und `Brand::SRP` entfernt. VolumeLicensingStrategy bleibt als generische Volume-Logik. Strategy-Auswahl läuft über `pricing_strategy`-DB-Setting (brand-scoped).
3. **Geplant (F2):** Per-Gallery `licensing_mode`-Override.
