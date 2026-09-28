# Brand-Context in Queue-/CLI-Kontexten — Konzept & Implementierung

> **Status:** Beschreibt den **Ist-Stand** (Implementierung) und die zugrunde liegende Problemanalyse.
> Verknüpft: `AGENTS.todo.md` A-08, `features/infrastructure/06-multi-domain-branding.md`,
> `features/infrastructure/08-org-brand-concept.md`, `features/infrastructure/12-brand-registry-and-settings-fixes.md`.
> Erstellt 2026-06-29. Aktualisiert 2026-07-01 (A-08 Queue State-Resetter).
> Korrigiert 2026-09-28: Zeilenanker, Container-Bindung statt `config('app.brand')`,
> SRP-Bezüge entfernt.

## 1. Kontext

Das Portal hat aktuell eine einzige konfigurierte Marke (`rp`, B2B) — SRP wurde am
2026-07-14 entfernt (siehe `AGENTS.md` §3 und
`features/infrastructure/21-brand-config-driven.md`). Der Brand wird zur Laufzeit
aus dem HTTP-Host aufgelöst (`BrandContextMiddleware`, `BrandRegistry::fromHost()`)
und aus `config/brands.php` gespeist.

Queue-Worker (`php artisan queue:work`) sind langlebige Prozesse — sie starten nicht neu zwischen
Jobs. Da `BrandRegistry` den Brand über eine Container-Bindung verwaltet (privater
Konstantenschlüssel `BrandRegistry::CONTAINER_KEY = 'brand.context'`, gesetzt via
`app()->instance(...)`, nicht über `config('app.brand')`), würde ein Job, der
`BrandRegistry::set(...)` aufruft, diesen Wert für den nächsten Job im selben Worker hinterlassen.

## 2. Implementierte Schutzmaßnahmen

### 2.1 Queue::before()-Reset (AppServiceProvider)

`backend/app/Providers/AppServiceProvider.php:211-217` registriert einen `Queue::before()`-Callback
(`:211` → `Queue::before(function () {`, `:212` → `BrandRegistry::reset();`):

```php
Queue::before(function () {
    BrandRegistry::reset();
    BrandRegistry::clearCache();
});
```

`:213-216` ergänzt `BrandRegistry::clearCache()`, damit eine im vorherigen Job
geschriebene Brand-Settings-Overlay-Änderung im nächsten Job frisch gelesen wird.

Dieser Callback feuert **vor jedem** Queue-Job im Worker und setzt den Brand auf `null` zurück.
Jobs, die einen Brand benötigen (z. B. `InvoiceMail::build()`), müssen ihn daher explizit aus
persistierten Daten rekonstruieren (via `BrandRegistry::resolveFromOrder()`).

### 2.2 BrandRegistry::reset()-Methode

`backend/app/Support/BrandRegistry.php:282-285` — formale Reset-Methode
(`:282` → `public static function reset(): void`):

```php
public static function reset(): void
{
    self::set(null);
}
```

Erlaubt eine semantisch klare Alternative zu `BrandRegistry::set(null)` in Queue-Kontexten.

### 2.3 Selbstrekonstruktion in InvoiceMail

`backend/app/Mail/InvoiceMail.php:26-33` — `InvoiceMail::build()` setzt den Brand selbst
(`:28` → `$this->brand = BrandRegistry::resolveFromOrder($this->order);`):

```php
public function build()
{
    $this->brand = BrandRegistry::resolveFromOrder($this->order);

    // Temporarily set brand so SettingResolver reads the correct brand scope,
    // then restore to prevent leakage to the rest of the request/process.
    $previousBrand = BrandRegistry::current();
    BrandRegistry::set($this->brand);
```

Damit ist das PDF-Rendering unabhängig vom vorherigen Worker-State korrekt. Der
abschließende `finally`-Block (`:80-82`) stellt den vorherigen Brand wieder her.

## 3. Betroffene Code-Stellen

| Stelle | Mechanismus |
|--------|-------------|
| `AppServiceProvider::boot()` | `Queue::before()` → `BrandRegistry::reset()` + `clearCache()` |
| `BrandRegistry::reset()` | Setzt die Container-Bindung `brand.context` auf `null` |
| `InvoiceMail::build()` | Rekonstruiert Brand aus `$order->brand` |
| `BrandContextMiddleware` | Setzt Brand aus HTTP-Host (nur Request-Kontext) |

## 4. Tests

- `tests/Unit/BrandRegistryTest.php` — testet `reset()` und alle BrandRegistry-Methoden
- `tests/Feature/BrandLeakTest.php` — testet InvoiceMail-Brand-Rekonstruktion, Coupon-Brand-Isolation
- `tests/Feature/BrandQueueResetTest.php` — testet den Reset-Lebenszyklus zwischen Jobs

## 5. Verifikation

- `BrandRegistry::reset()` setzt die Container-Bindung `brand.context` auf `null` nachweisbar
- `BrandRegistry::currentOrDefault()` fällt bei `null` sicher auf `B2B` (`rp`) zurück
- `InvoiceMail::build()` rekonstruiert den Brand aus der persistierten Order, auch wenn
  `Queue::before()` den Brand zuvor auf `null` gesetzt hat

## 6. Production Queue-/Scheduler-Betrieb

- Die Queue-Verbindung muss in Produktion `database` sein und dieselbe
  Datenbankverbindung wie die Anwendung verwenden. Das sichert die
  transaktionale Grenze zwischen Brand-Queue-Jobs, Cleanup-Outbox und
  Invoice-Mail-Claims.
- `QUEUE_WORKER_TIMEOUT` muss strikt kleiner als `DB_QUEUE_RETRY_AFTER` sein.
  Der Produktions-Worker wird im bestehenden Backend-Container von
  `deployment/backend-supervisor.sh` überwacht und nach einem Exit neu gestartet;
  der Compose-Healthcheck prüft Supervisor-, Worker- und Scheduler-PID.
- Scheduler-Events mit `onOneServer()` benötigen einen geteilten Cache-Store.
  Der aktuelle Stack verwendet den vorhandenen MariaDB-Cache; `file`/`array`
  sind für diesen Produktionspfad ungültig. Die Policy erzwingt die
  Shared-Cache-Anforderung, ohne Redis oder eine andere nicht vorhandene
  Infrastruktur einzuführen.
- Die vollständige Betriebs- und Recovery-Matrix steht im
  [Production Operations Runbook](29-production-operations-runbook.md). Statische
  Policy- und Shell-Tests sind kein Nachweis für einen laufenden Worker, eine
  SMTP-Zustellung oder einen Scheduler-Lauf.
