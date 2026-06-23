---
domain: infrastructure
topic: multi-domain-branding
status: active
---

# Technical Concept: Multi-Domain Branding & Dynamic Assets

## 1. Single Codebase, Dual Brand

Das Portal agiert als mandantenfähiges Multi-Domain-System für `all-the.rest` (B2C) und `reisinger.pictures` (
B2B/Premium).
Es gibt nur ein Deployment. Die Steuerung der Marke erfolgt zur Laufzeit über den Hostnamen (`window.location.hostname`
im Frontend, `$request->getHost()` im Backend).

## 2. Dynamic Frontend Assets (Favicons & Logos)

- Statische Assets wie `favicon.ico`, `manifest.json` und Apple-Touch-Icons liegen nicht mehr direkt im Root von
  `/public`, sondern isoliert unter `/public/brands/atr/` und `/public/brands/rp/`.
- **Pre-Boot Injection:** Ein synchrones Inline-Script in der `index.html` wertet vor dem Start von React den Hostnamen
  aus und injiziert die korrekten `<link rel="icon">`-Tags in den `<head>`. Dies verhindert das kurzzeitige Aufblitzen
  falscher Logos (FOUC).
- **React Components:** Der Hook `useBrand()` liefert Komponenten (wie der Sidebar) den korrekten Pfad zum jeweiligen
  Logo (`logoSrc`).

## 3. CSS Theming (Tailwind v4)

Das System nutzt Tailwind v4 `@theme` Plugins zur Laufzeit-Umschaltung:

- `atr-light` / `atr-dark`: Primärfarbe Violett (`#8940FA`), Sekundär Cyan (`#00C6FF`).
- `reisinger-light` / `reisinger-dark`: Primärfarbe Teal (`#2A9D8F`), Sekundär Dunkelblau (`#264653`).
  Der `applyTheme()` Bootstrapper verknüpft Hostname und System-Darkmode-Präferenz, um das `data-theme`-Attribut auf dem
  `<html>`-Tag zu setzen.

## 4. Admin Flexibility vs. Client Restrictions

- **Kundenansicht:** Kunden sehen immer strikt das Theme, das Logo und die PDF-Rechnungsköpfe der Domain, über die sie
  den Magic-Link geöffnet haben.
- **Admin-Werkzeuge:** Der Super-Admin wird durch den Hostnamen in seinen Werkzeugen nicht limitiert. So erlaubt
  beispielsweise der `ShootingCalculatorModal` die explizite Auswahl des Tarifmodells (B2C Flex vs. Studio Custom),
  unabhängig davon, über welche Domain das Dashboard gerade aufgerufen wurde.