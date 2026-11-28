# Paritätsvektoren 2026

**Status:** gültig für Wirtschaftsjahr 2026

## Zweck

Die 45 Vektoren der gepinnten Paritäts-Fixture sind die einzige Paritätsreferenz des Portals. Sie liefern die Expected Values (`expected[].canonical`) und die Inputs in Basiseinheiten. Der Test `backend/tests/Unit/CleanRoomParityTest.php` liest die Fixture zur Laufzeit, leitet jeden Ist-Wert über die portal-eigene Wertekette ab und vergleicht ihn mit dem Fixture-Wert. Die Fixture deckt alle elf Fachbereiche B01–B11 und alle 26 jahresabhängigen Regeln mit 2026-Wert ab; das Orakel weist `mismatch: 0` aus.

Parität entsteht ausschließlich aus dieser Fixture und den abgeleiteten Dokumenten der Clean-Room-Basis. Es gibt keinen Zugriff auf eine andere Implementierung, keinen Direktvergleich, keine zweite Regelquelle und keine Kopie von Formeln oder Code. Weicht ein hergeleiteter Wert ab, ist immer der Fixture-Wert maßgeblich: Umrechnung und Logik im Portal sind zu korrigieren, nicht die Fixture.

## Szenarien

| Szenario | verify-Keys | Einheiten |
|---|---|---|
| `sv-constants-2026` | `sv.maxBglMonthCents`, `sv.kvNum`, `sv.minKvMonthCents`, `sv.minPvGsvgMonthCents` (2 Zellen), `sv.minPvFsvgMonthCents`, `sv.pvGsvgNum`, `sv.pvFsvgNum`, `sv.svsNum`, `sv.uvCents` | cents, per-mille, per-10000 |
| `est-constants-2026` | `est.grundfreibetragLimitCents`, `est.grundfreibetragNum` | cents, percent |
| `business-share-100` | `money.businessShare100Cents` | cents |
| `expense-totals-2026` | `totals.expensesCents`, `totals.profitCents`, `e1a.resultCents`, `analytics.expenseCents`, `analytics.paymentCents` | cents |
| `hours-to-minutes` | `time.threeHoursMinutes` | minutes |
| `business-year-2026` | `derived.eue.001.*`, `derived.eue.002.*`, `derived.eue.003.*` | count |
| `per-diem-2026` | `derived.eue.013.*` | cents |
| `private-income-2026` | `derived.eue.033.*` | cents |
| `private-expense-2026` | `derived.eue.048.*` | cents |
| `asset-constants-2026` | `derived.eue.051.*`, `derived.eue.052.*` | cents, count |
| `travel-rates-2026` | `derived.eue.062.*`, `derived.eue.063.*`, `derived.eue.064.*`, `derived.eue.065.*`, `derived.eue.069.*` | cents |
| `vat-2026` | `vat.zahllastCents` | cents |
| `est-tariff-2026` | `derived.eue.089.*` … `derived.eue.093.*` | cents |
| `sv-2026` | `derived.eue.108.*` … `derived.eue.112.*` | cents, per-mille |
| `analytics-2026` | `derived.eue.125.*` | cents |
| `validation-2026` | `derived.eue.129.*` | count |

Die Konstanten-Szenarien verweisen über Regel-IDs und Parameternamen auf den Katalog; die Szenarien ohne Regelkonstante (`business-share-100`, `expense-totals-2026`, `hours-to-minutes`) prüfen zusammengesetzte Werte. `vat-2026` ist aus dem ersten Teilsatz des gepinnten EUE-086-Parameters (`USt-Sätze`) und dem 10000-Cent-Eingabewert abgeleitet; die Ist-Wert-Kette ist `VatCalculator`.

## Wertekette und Einheiten

- **EUR → Cent:** `Money::eurosToCents` rechnet mit BCMath (`× 100`) in ganze Cent, negative Beträge eingeschlossen.
- **Stunden → Minuten:** `Money::hoursToMinutes` rechnet mit BCMath (`× 60`) in ganze Minuten.
- **Betriebsanteil:** `Money::applyShare` multipliziert ganze Cent mit dem Anteil; der Default von `1.0` entspricht 100 %.
- **Prozentangaben:** `percent` bleibt ein ganzer Prozentwert, `per-mille` skaliert mit 10, `per-10000` mit 100. Alle Skalierungen laufen über BCMath und enden als Integer.
- **Speicherung und Übergabe:** Geld wird ausschließlich als ganzzahlige Cent, Zeit ausschließlich als ganze Minuten gespeichert und übergeben. Float-Werte aus der Fixture werden nur an der Lesegrenze umgerechnet; danach gibt es keine Float-Geldwerte mehr.

`BookkeepingTotals` bildet daraus die Summen- und Auswertungswerte: Ausgaben, Gewinn/Verlust (Einnahmen − Ausgaben) und die daraus abgeleiteten Ergebnisse. Alle Rückgaben sind Integer-Cent, die Klasse ist framework- und datenbankfrei.

## Konstanten

`RuleValues` liest den gepinnten Katalog `cleanroom/domain-rules.json` zur Laufzeit und wählt pro Regel-ID den Parameter mit dem Wirtschaftsjahr 2026. `canonicalFor` verlangt identische Werte bei mehreren Parametern für dasselbe Jahr; `parameterFor` wählt einen benannten Parameter und wird für Regeln mit mehreren unterschiedlichen Werten verwendet. Es werden nur die strukturierten Felder ausgelesen; Regeltexte und Formelstrings wandern nicht in den Code. Andere Jahre und unbekannte Einheiten werden abgewiesen. Es gibt keine zweite, redaktionell gepflegte Regelquelle.

Die Einheit `count` bleibt ein ganzer Zählwert (z. B. Wirtschaftsjahr 2026, Nutzungsjahre 2, Abgleich-Rundung 1); sie wird nicht in Cent skaliert.

## Ausführen

```sh
cd backend && php artisan test --filter=CleanRoomParityTest
```

Erwartet: 48 grüne Tests — 45 benannte Vektoren, den Zähler für 16 Szenarien / 45 Zellen und zwei Wirksamkeitsnachweise.

## Wirksamkeitsnachweis

`test_changed_expected_value_is_rejected` prüft den Vektor `money.businessShare100Cents`, `test_changed_derived_rule_value_is_rejected` den abgeleiteten Regelvektor `EUE-062`. Beide rufen `assertVector` anschließend mit `canonical + 1` auf und erwarten eine `ExpectationFailedException`. Ein veränderter Erwartungswert lässt die Tests damit nachweislich rot werden; eine neue oder geänderte Fixture wird nicht still übergangen.

## Abgrenzung

Die Fixture ist die Paritätsreferenz der gepinnten Vektoren. Die fachlichen Domain-Services liegen in `backend/app/Ea/`; die Parität prüft ihre Ergebnisse gegen die Fixture. Unit-Referenz ist `CleanRoomParityTest`, Smoke-Referenz ist die `#[Group('smoke')]`-Markierung desselben Tests. Eine Playwright-Referenz existiert nicht: es gibt im Repository kein E2E-Muster für Fixture-Parität, und Frontend-E2E-Änderungen sind ausdrücklich einem gesonderten, CI-gesteuerten Change vorbehalten. Die externe steuerliche Prüfung wird laut D-94 nicht durchgeführt und ist kein Abnahmegate mehr; das Portal erhebt keine steuerliche Richtigkeitsaussage.

## Verweise

- [01-cleanroom-handoff.md](01-cleanroom-handoff.md) — Fixture-Version und Update-Erkennung.
- [Paritäts-Fixture 2026](../eur/cleanroom/cleanroom-parity-2026.json) — einzige Grundlage der 45 Vektoren.
- [Gepinnter Regelkatalog](../eur/cleanroom/domain-rules.json) — einzige Quelle der Jahreskonstanten.
