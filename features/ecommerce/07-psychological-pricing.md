---
domain: ecommerce
topic: psychological-pricing
status: active
---

# Psychological Pricing & Shooting Calculator (Invariant)

> **Status: GEWÜNSCHTES Verhalten.** Diese Invariante ist absichtlich und darf **nicht** „korrigiert" werden.
> Der SOLL-Zustand des Systems steht in [`features/`](../README.md) — das ist die
> Stelle, an der „gewünschtes Verhalten" nachzuschlagen ist.

## Kern-Invariante

Der Shooting-Paket-Kalkulator (`ShootingCalculatorModal` → reine Logik in
`src/logic/shootingCalculator.ts`) rundet Preise **psychologisch** (`roundToPsychologicalValue`).

**Daraus folgt (gewollt):** Die in der UI *angezeigten* Rabatte (`-50%`, `-⅓`) sind **bewusst
mathematisch ungenau**. Der absolute Euro-Abzug passt nicht exakt zum prozentualen Rabatt, weil der
Endpreis nach dem Rabatt nochmals auf einen psychologischen Wert (z. B. …9-Endung) gerundet wird.
Das ist kein Bug, sondern der Zweck der psychologischen Preisrundung.

> Beim Testen (FE-04) wird das **echte** Verhalten eingefroren und per Kommentar auf dieses Doc
> verwiesen — **kein** Bug-REVIEW für die Rundungs-Ungenauigkeit.

## Einheit: der Kalkulator rechnet in Cent

> **Regel:** [`../tech/02-backend-architecture.md` § 4](../tech/02-backend-architecture.md)
> Nummer 1 — jeder Geldbetrag ist Cent, im Speicher und in der API. Der
> Shooting-Kalkulator ist keine Ausnahme. `calculateCustomStudioPrice()` in
> `frontend/src/logic/shootingCalculator.ts:64-96` (Stand `82e8d17`) addiert
> `basePrice`, `timePrice` und `imagesPrice` in **Euro** und rundet das Ergebnis
> in Euro. Die Umstellung auf Cent ist **in Arbeit** (Code-Seite, nicht in
> diesem Commit). Was hier festgeschrieben wird, ist das **Verhalten**, das
> danach unverändert gilt — nicht die heutige Implementierung.

**Die Invariante ist skalenunabhängig formuliert:** Die gerundete Ausgabe muss
in Euro **dieselbe Zahl** ergeben wie heute. Eine preispsychologische Rundung,
die nach der Umstellung `4499` statt `449` liefert, wäre kein Fix, sondern
derselbe Fehler in Cent.

### Dieselbe Regel in beiden Skalen

| | **Euro-Skala** (aktuell) | **Cent-Skala** (Ziel) |
|---|---|---|
| Schwelle „kleiner Betrag" | `value < 12` | `value < 1200` |
| Minimum | `max(1, round(value))` | `max(100, round(value))` |
| Schwelle „großer Betrag" | `value >= 1000` | `value >= 100000` |
| Raster klein | `round(value/5) * 5` | `round(value/500) * 500` |
| Raster groß | `round(value/50) * 50` | `round(value/5000) * 5000` |
| …0-Endung → …9 | `rounded % 10 === 0` | `rounded % 1000 === 0` |
| …00-Endung → …99 (nur im großen Bereich) | `rounded % 50 === 0` | `rounded % 5000 === 0` |
| Abschlag | `rounded -= 1` | `rounded -= 100` |

**Jede** Konstante skaliert mit Faktor 100 — aber aus zwei verschiedenen
Gründen, und nur einer davon sieht danach aus:

- **Schwellen und Raster sind Magnituden.** `12` und `1000` vergleichen gegen
  den Wert, `5` und `50` teilen ihn. Sie skalieren, weil der Betrag 100× so
  groß ist. Das ist der erwartete Fall.
- **Modulus und Abschlag sind Nachkommastellen des Geldes.** `% 10` bedeutet
  nicht „modulo 10 Cent", es bedeutet „endet auf 0 Euro"; in der feineren Skala
  ist das derselbe Sachverhalt, ausgedrückt als `% 1000`. Der Abschlag `1`
  bedeutet nicht „1 Cent", sondern „1 Euro". **Diese beiden skalieren nicht,
  weil der Wert gewachsen ist, sondern weil die Haupteinheit feiner geworden
  ist** — und sie sehen in der Quelltextzeile aus wie zwei kleine Konstanten,
  die man beim Skalieren leicht übersieht.

Die Folge dieses Übersehens ist der eigentliche Grund, warum die Skala hier
ausgeschrieben steht: Lässt man `% 10` und `rounded -= 1` stehen, ist das
Ergebnis **nicht** sichtbar falsch gerundet. Ein 450-€-Preis wird 44999 statt
44900 — ein Fehler von 99 Cent, einmal pro …0-Preis, ohne Rundungsartefakt.
`% 10` trifft in Cent zudem fast nie zu, die Korrektur greift also gar nicht
erst. Ein Test, der nur prüft „gerundet, kein Fraction-Anteil übrig", ist grün.

### `roundToPsychologicalValue(value)` — Regelwerk (Euro-Skala, verifiziert)

- `value < 12` → `max(1, round(value))` (Minimum 1 €).
- `value >= 1000` → `round(value/50) * 50`, sonst `round(value/5) * 5`.
- Anschließend `-1`, falls `rounded !== 0 && (rounded % 10 === 0 || (value >= 1000 && rounded % 50 === 0))`
  (…0-Endungen werden zu …9).

Implementierung: `frontend/src/logic/shootingCalculator.ts:46-60` bei `82e8d17`
(der letzte Commit vor der Umstellung; die Zeilennummern des neuen Stands
stehen in der Commit-Differenz). Die Eingabeeinheit dieses Aufrufs ist **Euro**
(`:65-87` rechnet in Euro) und **Cent** nach der Umstellung; das Ergebnis in
Euro ändert sich nicht.

| Eingabe | Ausgabe |
|--------:|--------:|
| 0       | 1       |
| 5.5     | 6       |
| 12      | 9       |
| 13      | 15      |
| 20      | 19      |
| 100     | 99      |
| 1000    | 999     |
| 1026    | 1049    |
| 1075    | 1099    |

## `calculateShootingPrice` — Beispielrechnungen

> **Korrektur 2026-09-28:** der Stundensatz stand hier als `rate 100 €`, der
> Code sagt `DEFAULT_HOURLY_RATE = 80`
> (`frontend/src/logic/shootingCalculator.ts:5` bei `82e8d17`). Der Fehler war **schon vor der
> Cent-Entscheidung** da und hatte nichts mit ihr zu tun. Die alte Überschrift
> nannte 100 €, die Tabelle darunter war ebenfalls mit 100 € gerechnet — beides
> ist jetzt auf 80 € umgerechnet. Wer die Zahl 100 im Kopf hat: sie ist falsch,
> und sie war falsch.

**Defaults:** base **50 €**, rate **80 €**, 6 Img/h, 90 min, 15 Bilder, keine
Outdoor-Variante (`shootingCalculator.ts:4-7` bei `82e8d17`; `calc_base_price` geseedet als
`'50'`, `calc_hourly_rate` als `'80'`, `backend/database/seeders/DatabaseSeeder.php:159-160`).

Zwischenrechnung: `timePrice = 1,5 h × 80 = 120`, `imagesPrice = (80/6) × 15 = 200`,
`basePrice = 50` → `rawTotal = 370` ohne Flatrate, `444` mit `× 1,2`.

| Konfiguration         | package | final | discountAbsolute | Bemerkung |
|-----------------------|--------:|------:|-----------------:|-----------|
| kein Flatrate/kein Rabatt | 369 | 369 | 0 | `rawTotal=370` → 370 endet auf 0 → 369 |
| Flatrate (+20 %)      | 445     | 445   | 0                | `rawTotal=444` → 445; 445 endet nicht auf 0, bleibt stehen |
| 33 % Rabatt           | 369     | 245   | 124              | `369 − 123 = 246` → 246 endet nicht auf 0 → 245; eff. ≈ 33,6 % |
| 50 % Rabatt           | 369     | 185   | 184              | **eff. ≈ 49,9 %** (184 statt 184,5) — *gewollt ungenau* |

`discountAbsolute` ist die Differenz der beiden gerundeten Preise, nicht das
Ergebnis einer Multiplikation — deshalb ist der 50-%-Abschlag um einen Euro
kleiner als die Hälfte. Das ist die Invariante dieses Dokuments, und sie
überlebt die Umstellung auf Cent nur, wenn die Skala vollständig skaliert wird.
