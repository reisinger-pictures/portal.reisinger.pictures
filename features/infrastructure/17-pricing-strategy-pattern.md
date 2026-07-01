# Pricing Strategy Pattern — Architektur (Soll-Zustand)

> **Status:** Soll-Zustand. Beschreibt die Architektur des Strategy-Patterns zur Entkopplung der
> Preislogik für B2B (RP) und SRP.
> Verknüpft: `AGENTS.todo.md` T-20, `features/infrastructure/16-srp-volume-pricing.md`.
> Erstellt 2026-07-01.

## 1. Kontext

Bisher gibt es eine einzige Preislogik in `PricingService::calculateItemPriceCents()`, die auf
`license_use_cases` und `license_modifiers` basiert (B2B-Modell). Für das SRP-Portal wird ein
vollständig anderes Preismodell benötigt (mengenbasiertes Volumen-Pricing).

Um die beiden Modelle sauber zu trennen und zukünftige Preismodelle zu ermöglichen, wird das
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
    │   (B2B / RP)                │  │   (SRP)                     │
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

#### VolumeLicensingStrategy (SRP)
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

- **Trennung der Preismodelle**: RP und SRP haben unabhängige Implementierungen
- **Erweiterbarkeit**: Neue Preismodelle können durch Hinzufügen weiterer Strategien integriert werden
- **Testbarkeit**: Jede Strategie kann isoliert getestet werden
- **Keine Brand-If-Abfragen**: Die Strategie-Auswahl erfolgt zentral im ServiceProvider
