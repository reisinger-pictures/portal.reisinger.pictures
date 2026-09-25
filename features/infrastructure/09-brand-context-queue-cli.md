# Brand-Context in Queue-/CLI-Kontexten — Konzept & Implementierung

> **Status:** Beschreibt den **Ist-Stand** (Implementierung) und die zugrunde liegende Problemanalyse.
> Verknüpft: `AGENTS.todo.md` A-08, `features/infrastructure/06-multi-domain-branding.md`,
> `features/infrastructure/08-org-brand-concept.md`, `features/infrastructure/12-brand-registry-and-settings-fixes.md`.
> Erstellt 2026-06-29. Aktualisiert 2026-07-01 (A-08 Queue State-Resetter).

## 1. Kontext

Das Portal unterscheidet zur Laufzeit zwei White-Label-Brands über den HTTP-Host
(`BrandContextMiddleware`): `portal.reisinger.pictures` (B2B) und `buy.reisinger.pictures` (B2C/SRP). Der Brand
steuert Branding (Theme, Logo, Wasserzeichen) und insbesondere **markenspezifische Bank-/Firmendaten**
im Rechnungs-PDF (SRP = `srp_`-Präfix in den Settings, B2B = kein Präfix).

Queue-Worker (`php artisan queue:work`) sind langlebige Prozesse — sie starten nicht neu zwischen
Jobs. Da `BrandRegistry` den Brand über `config('app.brand')` (den process-globalen Config-Repository)
verwaltet, würde ein Job, der `BrandRegistry::set(Brand::SRP)` aufruft, diesen Wert für den nächsten
Job im selben Worker hinterlassen.

## 2. Implementierte Schutzmaßnahmen

### 2.1 Queue::before()-Reset (AppServiceProvider)

`backend/app/Providers/AppServiceProvider.php:96-102` registriert einen `Queue::before()`-Callback:

```php
Queue::before(function () {
    BrandRegistry::reset();
});
```

Dieser Callback feuert **vor jedem** Queue-Job im Worker und setzt den Brand auf `null` zurück.
Jobs, die einen Brand benötigen (z. B. `InvoiceMail::build()`), müssen ihn daher explizit aus
persistierten Daten rekonstruieren (via `BrandRegistry::resolveFromOrder()`).

### 2.2 BrandRegistry::reset()-Methode

`backend/app/Support/BrandRegistry.php:80-88` — formale Reset-Methode:

```php
public static function reset(): void
{
    self::set(null);
}
```

Erlaubt eine semantisch klare Alternative zu `BrandRegistry::set(null)` in Queue-Kontexten.

### 2.3 Selbstrekonstruktion in InvoiceMail

`backend/app/Mail/InvoiceMail.php:32` — `InvoiceMail::build()` setzt den Brand selbst:

```php
BrandRegistry::set(BrandRegistry::resolveFromOrder($this->order));
```

Damit ist das PDF-Rendering unabhängig vom vorherigen Worker-State korrekt.

## 3. Betroffene Code-Stellen

| Stelle | Mechanismus |
|--------|-------------|
| `AppServiceProvider::boot()` | `Queue::before()` → `BrandRegistry::reset()` |
| `BrandRegistry::reset()` | Setzt `config('app.brand')` auf `null` |
| `InvoiceMail::build()` | Rekonstruiert Brand aus `$order->brand` |
| `BrandContextMiddleware` | Setzt Brand aus HTTP-Host (nur Request-Kontext) |

## 4. Tests

- `tests/Unit/BrandRegistryTest.php` — testet `reset()` und alle BrandRegistry-Methoden
- `tests/Feature/BrandLeakTest.php` — testet InvoiceMail-Brand-Rekonstruktion, Coupon-Brand-Isolation
- `tests/Feature/BrandQueueResetTest.php` — testet den Reset-Lebenszyklus zwischen Jobs

## 5. Verifikation

- `BrandRegistry::reset()` setzt `config('app.brand')` auf `null` nachweisbar
- `BrandRegistry::currentOrDefault()` fällt bei `null` sicher auf `B2B` zurück
- `InvoiceMail::build()` rekonstruiert SRP-Brand aus persistierter Order, auch wenn
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
