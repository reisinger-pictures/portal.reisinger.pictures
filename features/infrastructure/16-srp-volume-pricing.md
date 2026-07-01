# SRP Volume Pricing — Konzept (Soll-Zustand)

> **Status:** Soll-Zustand. Beschreibt das mengenbasierte Volumen-Preismodell für das SRP-Portal.
> Verknüpft: `AGENTS.todo.md` T-20.
> Erstellt 2026-07-01.

## 1. Kontext

Das SRP-Portal (`story.reisinger.pictures`) ist ein reduziertes B2C-Portal. Im Gegensatz zum B2B-Portal
gibt es kein Lizenz-Katalog-System (`license_use_cases`, `license_modifiers`). Stattdessen gilt ein
einfaches, mengenbasiertes Volumen-Preismodell:

- **30 € pro Bild** (Tier 1, 1–9 Bilder)
- **25 € pro Bild** (Tier 2, 10–19 Bilder)
- **20 € pro Bild** (Tier 3, ab 20 Bilder)

Der Preis gilt **retroaktiv** für alle Bilder im Warenkorb, d.h. alle Bilder einer Bestellung werden
zum selben Einzelpreis abgerechnet (ermittelt anhand der Gesamtmenge).

## 2. Soll-Zustand

### 2.1 Preismodell

| Menge | Preis pro Bild | Betrag 10 Bilder | Betrag 20 Bilder |
|-------|---------------|-----------------|-----------------|
| 1–9   | 30,00 €       | —               | —               |
| 10–19 | 25,00 €       | 250,00 €        | —               |
| ≥20   | 20,00 €       | —               | 400,00 €        |

Die Preisermittlung erfolgt **retroaktiv** über die Gesamtanzahl der Nicht-Quote-Items im Warenkorb:

- count < threshold1 (default 10) → Tier-1-Preis pro Bild
- count ≥ threshold1 und < threshold2 (default 20) → Tier-2-Preis pro Bild
- count ≥ threshold2 → Tier-3-Preis pro Bild

### 2.2 Konfiguration via Settings-Tabelle

Die Preise und Schwellenwerte sind konfigurierbar über die `settings`-Tabelle mit SRP-Brand:

| Key                              | Typ   | Default | Beschreibung                    |
|----------------------------------|-------|---------|---------------------------------|
| `srp_price_per_image_tier1`      | int   | 3000    | Preis pro Bild Tier 1 (Cent)    |
| `srp_price_per_image_tier2`      | int   | 2500    | Preis pro Bild Tier 2 (Cent)    |
| `srp_price_per_image_tier3`      | int   | 2000    | Preis pro Bild Tier 3 (Cent)    |
| `srp_tier_threshold1`            | int   | 10      | Schwellenwert Tier 1→2          |
| `srp_tier_threshold2`            | int   | 20      | Schwellenwert Tier 2→3          |

### 2.3 Keine Lizenz-Modifier / Use Cases für SRP

- SRP verwendet **keine** `license_use_cases`, `license_modifiers` oder commercial/editorial-Prüfungen.
- Das Preismodell ist rein mengenbasiert und unabhängig vom Verwendungszweck.
- Die `guardBrand()`-Prüfung wird für SRP nicht aktiv (Brand-Mismatch kann bei SRP nicht auftreten,
  da SRP keine Lizenz-DB-Records laden kann).

### 2.4 Quote-Items

Quote-Items werden wie bisher behandelt → 0 €. Sie zählen **nicht** für die Mengen-Ermittlung des
Volumen-Tiers.

### 2.5 Fallback

Wenn die Settings nicht gesetzt sind, werden die Hardcoded-Defaults verwendet:
- Tier-1-Preis: 3000 Cents
- Tier-2-Preis: 2500 Cents
- Tier-3-Preis: 2000 Cents
- Threshold 1: 10
- Threshold 2: 20

## 3. Abgrenzung

- Dieses Dokument beschreibt nur das **Backend**-Preismodell.
- Das Frontend (Warenkorb-Anzeige, Preis-Berechnung clientseitig) ist nicht Teil dieses Dokuments.
- Die Architektur (Strategy-Pattern) wird in `features/infrastructure/17-pricing-strategy-pattern.md` beschrieben.
