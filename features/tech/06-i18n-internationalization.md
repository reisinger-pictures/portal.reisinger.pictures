# Internationalisierung (i18n) — Konzept

> **Status:** `active`
> Domain: `tech`
> Erstellt: 2026-07-05

## 1. Framework-Entscheidung

**LinguiJS v6** (`@lingui/react` + `@lingui/core` + `@lingui/vite-plugin` + `@lingui/cli` + `@lingui/babel-plugin-lingui-macro`)

Begründung:
- **Vite-native** — offizielles `@lingui/vite-plugin`, perfekte Integration in bestehendes Vite 8 + Rolldown Build-Setup.
- **TypeScript-First** — Macro-basierte API (`t`-Tagged-Template von `@lingui/core/macro`, `<Trans>`-JSX-Komponente von `@lingui/react/macro`), volle Typensicherheit, kein `any`.
- **Extraction als Kernfeature** — `lingui extract` findet **alle** `t()`/`Trans`-Aufrufe und generiert `.po`-Kataloge. Erzwingt Konsistenz, weil kein String durchrutscht.
- **Single-Locale tauglich** — mit nur `de` als Locale kompilieren die Macros zu reinem String-Replacement, kein Overhead für andere Sprachen.

Verworfen: `react-i18next` (keine Vite-Integration, Extraction nur via Drittanbieter), `react-intl` (schwergewichtig, weniger typsicher), Self-made `t()` (kein Extract, Inkonsistenz vorprogrammiert).

## 2. Locale-Strategie

| Aspekt | Festlegung |
|--------|------------|
| Source-Locale | `de` (= die Sprache, in der Strings im Code geschrieben werden) |
| Weitere Locales | vorerst keine — bei Bedarf später ergänzbar |
| Catalog-Format | `.po` (gettext, maschinenlesbar + diffbar in Git) |
| Fallback-Verhalten | Da `de` = Source-Locale, ist `msgid === msgstr` im deutschen Catalog. Kein Fallback nötig. |

## 3. Architektur

### 3.1 Dateistruktur

```
frontend/
├── locale/
│   └── de/
│       └── messages.po        # Extrahierte + übersetzte Katalog-Datei (DE)
├── lingui.config.ts            # LinguiJS Konfiguration
└── src/
    ├── logic/
    │   └── I18nProvider.tsx     # Initialisiert Lingui mit DE-Catalog + wrappt App
    └── ...
```

### 3.2 Provider-Integration

`I18nProvider.tsx` wird in der React-Component-Hierarchy oberhalb von `UIProvider` in `App.tsx` platziert und initialisiert `@lingui/core` mit dem deutschen Catalog.

### 3.3 Build-Pipeline

```
[Source-Code mit t{...}/<Trans>]
         │
         ▼
  lingui extract    ← CLI-Befehl: scannt Source nach t/Trans-Aufrufen
         │
         ▼
  locale/de/messages.po    ← msgid = deutscher String, msgstr = deutscher String
         │
         ▼
  @lingui/vite-plugin    ← kompiliert .po → JS bei Vite-Build
         │
         ▼
  [Runtime-Catalog im Bundle]
```

## 4. Naming Conventions & Regeln (STRICT)

### 4.1 Syntax-Wahl

| Kontext | Syntax | Import |
|---------|--------|--------|
| JSX-Text (`<span>...</span>`) | `<Trans>...</Trans>` | `import { Trans } from "@lingui/react/macro"` |
| JS-Funktionsargument (`toast("...")`) | `` t`...` `` | `import { t } from "@lingui/core/macro"` |
| String-Interpolation | `` t`Hallo ${name}` `` | `import { t } from "@lingui/core/macro"` |
| Plurale (selten) | `plural(value, { one: "...", other: "..." })` | `import { plural } from "@lingui/core/macro"` |
| Attribute (`title`, `aria-label`, `placeholder`) | `` t`...` `` | `import { t } from "@lingui/core/macro"` |
| Hook (scope-gebundenes `t`) | `const { t } = useLingui()` | `import { useLingui } from "@lingui/react/macro"` |

### 4.2 String-Konventionen

- **Keys = Deutsche Sätze** — Es gibt keinen separaten Key-Namespace. Der deutsche Satz IST der Key (`msgid`).
- **Keine dynamischen Keys** — Keys müssen statisch sein. Dynamische Konkatenation wie `` t`Status_${code}` `` ist **verboten**.
- **Keine Interpolation in Tags** — `<Trans>` per Tag ist OK, aber Variablen innerhalb von `<Trans>` via `<Trans><strong>{name}</strong> Hallo</Trans>`.
- **Kein `useEffect` für i18n** — Der Catalog wird einmalig beim App-Start geladen. Kein dynamisches Nachladen von Locales per `useEffect`.
- **Vollständige Sätze** — Strings sollen wenn möglich vollständige Sätze sein, keine Fragmente. Das vermeidet Kontext-Probleme bei späterer Übersetzung.

### 4.3 Validierung & Tests

- **Extract-Check** — Vor jedem Build muss `lingui extract` laufen. Der CI prüft, ob alle Strings extrahiert sind.
- **Kein Hardcoded-String im JSX** — ESLint-Regel (optional, zukunft): `eslint-plugin-lingui` verhindert hartcodierte Strings in JSX.

## 5. Migration: Schritt-für-Schritt

### 5.1 Initial-Setup (07/2026)
1. `pnpm add @lingui/react @lingui/core @lingui/vite-plugin @lingui/babel-plugin-lingui-macro`
2. `pnpm add -D @lingui/cli @lingui/format-po @babel/core @rolldown/plugin-babel`
3. `lingui.config.ts` anlegen (mit `format: formatter({lineNumbers: false})` aus `@lingui/format-po`)
4. `vite.config.ts` um `lingui()` + `babel({presets: [linguiTransformerBabelPreset()]})` ergänzen
5. `I18nProvider.tsx` erstellen + in `App.tsx` einbinden
6. `src/lingui-env.d.ts` für `.po`-Import-Typen anlegen
7. `pnpm lingui extract` → generiert `locale/de/messages.po`

### 5.2 String-Migration (pro Datei)
1. `import { t } from "@lingui/core/macro"` bzw. `import { Trans } from "@lingui/react/macro"` hinzufügen
2. Hartcodierte deutsche Strings durch `t`...`` / `<Trans>...</Trans>` ersetzen
3. Keine semantische Änderung — die Texte bleiben gleich, werden nur über i18n geroutet
4. **Nicht ersetzen:** Zod-Schemata, `toLocaleString('de-DE')`, CSS-Klassen, `data-testid`, leere Strings

### 5.3 Post-Migration (07/2026 — Erstmigration)
1. `pnpm lingui extract` — **969 Strings** aus ~130 Dateien extrahiert
2. `pnpm lingui compile` — `.po` → JS (automatisch durch Vite-Plugin)
3. `pnpm lint:fix && pnpm build` — fehlerfrei ✅

## 6. Verifikation (DoD)

- `pnpm lint:fix && pnpm build` läuft fehlerfrei ✅
- `lingui extract` produziert keine "Missing" oder "Unused" Warnings ✅
- `locale/de/messages.po` enthält **969 Einträge** (msgid === msgstr) ✅
- Alle UI-Texte sind unverändert auf Deutsch sichtbar ✅
- Kein `any`, `@ts-ignore` oder `eslint-disable` ✅
