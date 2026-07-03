---
domain: ecommerce
topic: psychological-pricing
status: active
---

# Psychological Pricing & Shooting Calculator (Invariant)

> **Status: GEWÜNSCHTES Verhalten.** Diese Invariante ist absichtlich und darf **nicht** „korrigiert" werden.
> Siehe auch `CLAUDE.md` (Regel: *Gewünschtes Verhalten nachschlagen*).

## Kern-Invariante

Der Shooting-Paket-Kalkulator (`ShootingCalculatorModal` → reine Logik in
`src/logic/shootingCalculator.ts`) rundet Preise **psychologisch** (`roundToPsychologicalValue`).

**Daraus folgt (gewollt):** Die in der UI *angezeigten* Rabatte (`-50%`, `-⅓`) sind **bewusst
mathematisch ungenau**. Der absolute Euro-Abzug passt nicht exakt zum prozentualen Rabatt, weil der
Endpreis nach dem Rabatt nochmals auf einen psychologischen Wert (z. B. …9-Endung) gerundet wird.
Das ist kein Bug, sondern der Zweck der psychologischen Preisrundung.

> Beim Testen (FE-04) wird das **echte** Verhalten eingefroren und per Kommentar auf dieses Doc
> verwiesen — **kein** Bug-REVIEW für die Rundungs-Ungenauigkeit.

## `roundToPsychologicalValue(value)` — Regelwerk (verifiziert)

- `value < 12` → `max(1, round(value))` (Minimum 1 €).
- `value >= 1000` → `round(value/50) * 50`, sonst `round(value/5) * 5`.
- Anschließend `-1`, falls `rounded !== 0 && (rounded % 10 === 0 || (value >= 1000 && rounded % 50 === 0))`
  (…0-Endungen werden zu …9).

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

## `calculateShootingPrice` — Beispielrechnungen (Defaults: base 50 €, rate 100 €, 6 Img/h, 90 min, 15 Bilder)

| Konfiguration         | package | final | discountAbsolute | Bemerkung |
|-----------------------|--------:|------:|-----------------:|-----------|
| kein Flatrate/kein Rabatt | 449 | 449 | 0 | `rawTotal=450` → 449 |
| Flatrate (+20 %)      | 539     | 539   | 0                | `rawTotal=540` → 539 |
| 33 % Rabatt           | 449     | 299   | 150              | final=round(299,33→300→299); eff. ≈ 33,4 % |
| 50 % Rabatt           | 449     | 225   | 224              | **eff. ≈ 49,9 %** (224 statt 224,5) — *gewollt ungenau* |
