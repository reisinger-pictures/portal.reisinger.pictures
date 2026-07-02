---
domain: infrastructure
topic: multi-domain-branding
status: active
---

# Technical Concept: Multi-Domain Branding & Dynamic Assets

## 1. Single Codebase, Dual Brand

Das Portal agiert als mandantenfähiges Multi-Domain-System für `story.reisinger.pictures` (B2C) und `reisinger.pictures` (
B2B/Premium).
Es gibt nur ein Deployment. Die Steuerung der Marke erfolgt zur Laufzeit über den Hostnamen (`window.location.hostname`
im Frontend, `$request->getHost()` im Backend).

## 2. Dynamic Frontend Assets (Favicons & Logos)

- Statische Assets wie `favicon.ico`, `manifest.json` und Apple-Touch-Icons liegen nicht mehr direkt im Root von
  `/public`, sondern isoliert unter `/public/brands/srp/` und `/public/brands/rp/`.
- **Pre-Boot Injection:** Ein synchrones Inline-Script in der `index.html` wertet vor dem Start von React den Hostnamen
  aus und injiziert die korrekten `<link rel="icon">`-Tags in den `<head>`. Dies verhindert das kurzzeitige Aufblitzen
  falscher Logos (FOUC).
- **React Components:** Der Hook `useBrand()` liefert Komponenten (wie der Sidebar) den korrekten Pfad zum jeweiligen
  Logo (`logoSrc`).

## 3. CSS Theming (Tailwind v4)

Das System nutzt Tailwind v4 `@theme` Plugins zur Laufzeit-Umschaltung:

- `srp-light` / `srp-dark`: Primärfarbe Violett (`#8940FA`), Sekundär Cyan (`#00C6FF`).
- `reisinger-light` / `reisinger-dark`: Primärfarbe Teal (`#2A9D8F`), Sekundär Dunkelblau (`#264653`).
  Der `applyTheme()` Bootstrapper verknüpft Hostname und System-Darkmode-Präferenz, um das `data-theme`-Attribut auf dem
  `<html>`-Tag zu setzen.

## 4. Admin Flexibility vs. Client Restrictions

- **Kundenansicht:** Kunden sehen immer strikt das Theme, das Logo und die PDF-Rechnungsköpfe der Domain, über die sie
  den Magic-Link geöffnet haben.
- **Admin-Werkzeuge:** Der Super-Admin wird durch den Hostnamen in seinen Werkzeugen nicht limitiert. So erlaubt
  beispielsweise der `ShootingCalculatorModal` die explizite Auswahl des Tarifmodells (B2C Flex vs. Studio Custom),
  unabhängig davon, über welche Domain das Dashboard gerade aufgerufen wurde.

## 5. Brand-Aware Email Templates

### 5.1 Config Values

| Key | RP | SRP |
|-----|----|-----|
| `config('app.frontend_url')` | `FRONTEND_URL` | — |
| `config('app.frontend_url_srp')` | — | `FRONTEND_URL_SRP` |
| `config('mail.from.address')` / `config('mail.from.name')` | `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | — |
| `config('mail.from_srp.address')` / `config('mail.from_srp.name')` | — | `MAIL_FROM_ADDRESS_SRP` / `MAIL_FROM_NAME_SRP` |
| `config('services.accounting_email_rp')` | `ACCOUNTING_EMAIL_RP` | — |
| `config('services.accounting_email_srp')` | — | `ACCOUNTING_EMAIL_SRP` |

### 5.2 Trait: `BrandAwareMail`

Alle 7 Mail-Klassen nutzen das `BrandAwareMail` Trait. Es bietet:
- `initializeBrand()` — fängt den aktuellen Brand im Konstruktor (für Queue-Serialisierung)
- `ensureBrandContext()` — stellt den Brand bei Queue-Deserialisierung wieder her
- `brandFrontendUrl()` — liefert brand-spezifische Frontend-URL
- `brandLogoUrl()` — liefert die korrekte Logo-URL (Frontend + `/android-chrome-192x192.png`)
- `applyBrandFrom()` — setzt den E-Mail-Absender pro Brand
- `brandBcc()` — liefert die BCC-Adresse pro Brand

Siehe `backend/app/Mail/BrandAwareMail.php`.

## 6. Hostname-Konvention (E2E Test Locators)

| Hostname | Brand | Beschreibung |
|----------|-------|-------------|
| `localhost:4321` | B2B/RP (`rp`) | Default |
| `portal.localhost:4321` | B2B/RP (`rp`) | Alias-Subdomain |
| `buy.localhost:4321` | SRP (`srp`) | SRP-Subdomain für Volume-Licensing |

**Regel:** URL-Assertions in E2E-Tests MÜSSEN `localhost:4321` (nicht `portal.localhost:4321`) matchen.