# Paritätsvektoren 2026

**Status:** gültig für Wirtschaftsjahr 2026

## Zweck

Die 19 Vektoren der gepinnten Paritäts-Fixture sind die einzige Paritätsreferenz des Portals für den nächsten Schritt von Issue #10. Sie liefern die Expected Values (`expected[].canonical`) und die Inputs in Basiseinheiten. Der Test `backend/tests/Unit/CleanRoomParityTest.php` liest die Fixture zur Laufzeit, leitet jeden Ist-Wert über die portal-eigene Wertekette ab und vergleicht ihn mit dem Fixture-Wert.

Parität entsteht ausschließlich aus dieser Fixture und den abgeleiteten Dokumenten der Clean-Room-Basis. Es gibt keinen Zugriff auf eine andere Implementierung, keinen Direktvergleich, keine zweite Regelquelle und keine Kopie von Formeln oder Code. Weicht ein hergeleiteter Wert ab, ist immer der Fixture-Wert maßgeblich: Umrechnung und Logik im Portal sind zu korrigieren, nicht die Fixture.

## Szenarien

| Szenario | verify-Keys | Einheiten |
|---|---|---|
| `sv-constants-2026` | `sv.maxBglMonthCents`, `sv.kvNum`, `sv.minKvMonthCents`, `sv.minPvGsvgMonthCents` (2 Zellen), `sv.minPvFsvgMonthCents`, `sv.pvGsvgNum`, `sv.pvFsvgNum`, `sv.svsNum`, `sv.uvCents` | cents, per-mille, per-10000 |
| `est-constants-2026` | `est.grundfreibetragLimitCents`, `est.grundfreibetragNum` | cents, percent |
| `business-share-100` | `money.businessShare100Cents` | cents |
| `expense-totals-2026` | `totals.expensesCents`, `totals.profitCents`, `e1a.resultCents`, `analytics.expenseCents`, `analytics.paymentCents` | cents |
| `hours-to-minutes` | `time.threeHoursMinutes` | minutes |

Die Konstanten-Szenarien verweisen über Regel-IDs auf den Katalog (EUE-014 bis EUE-022 sowie EUE-096 und EUE-097); die drei Szenarien ohne Regelkonstante sind Szenariowerte der Fixture.

## Wertekette und Einheiten

- **EUR → Cent:** `Money::eurosToCents` rechnet mit BCMath (`× 100`) in ganze Cent, negative Beträge eingeschlossen.
- **Stunden → Minuten:** `Money::hoursToMinutes` rechnet mit BCMath (`× 60`) in ganze Minuten.
- **Betriebsanteil:** `Money::applyShare` multipliziert ganze Cent mit dem Anteil; der Default von `1.0` entspricht 100 %.
- **Prozentangaben:** `percent` bleibt ein ganzer Prozentwert, `per-mille` skaliert mit 10, `per-10000` mit 100. Alle Skalierungen laufen über BCMath und enden als Integer.
- **Speicherung und Übergabe:** Geld wird ausschließlich als ganzzahlige Cent, Zeit ausschließlich als ganze Minuten gespeichert und übergeben. Float-Werte aus der Fixture werden nur an der Lesegrenze umgerechnet; danach gibt es keine Float-Geldwerte mehr.

`BookkeepingTotals` bildet daraus die Summen- und Auswertungswerte: Ausgaben, Gewinn/Verlust (Einnahmen − Ausgaben) und die daraus abgeleiteten Ergebnisse. Alle Rückgaben sind Integer-Cent, die Klasse ist framework- und datenbankfrei.

## Konstanten

`RuleValues` liest den gepinnten Katalog `cleanroom/domain-rules.json` zur Laufzeit und wählt pro Regel-ID den Parameter mit dem Wirtschaftsjahr 2026. Es werden nur die strukturierten Felder ausgelesen; Regeltexte und Formelstrings wandern nicht in den Code. Bei mehreren Parametern für dasselbe Jahr muss deren Wert identisch sein, sonst wird die Anfrage mit einer Ausnahme abgebrochen — es wird nie still einer gewählt. Andere Jahre und unbekannte Einheiten werden abgewiesen. Es gibt keine zweite, redaktionell gepflegte Regelquelle.

## Ausführen

```sh
cd backend && php artisan test --filter=CleanRoomParityTest
```

Erwartet: 21 grüne Tests — 19 benannte Vektoren, der Zähler für 5 Szenarien / 19 Zellen und der Wirksamkeitsnachweis.

## Wirksamkeitsnachweis

`test_changed_expected_value_is_rejected` prüft zuerst den korrekten Canonical-Wert des Vektors `money.businessShare100Cents` und ruft dann `assertVector` mit `canonical + 1` auf. Der Test erwartet dafür eine `ExpectationFailedException`. Ein veränderter Erwartungswert lässt den Test damit nachweislich rot werden; eine neue oder geänderte Fixture wird nicht still übergangen.

## Abgrenzung

Dieses Modul ist das Paritätsziel für Issue #10. Die vollständige Buchhaltungs-Engine entsteht in den Folgetickets; hier liegt nur die minimale portal-eigene Wertekette vor. Parität gilt als `covered` erst mit Smoke- und Playwright-Referenz. Die externe steuerliche Prüfung bleibt ein dokumentierter Vorbehalt, kein Blocker.

## Verweise

- [01-cleanroom-handoff.md](01-cleanroom-handoff.md) — Fixture-Version und Update-Erkennung.
- [Paritäts-Fixture 2026](cleanroom/cleanroom-parity-2026.json) — einzige Grundlage der 19 Vektoren.
- [Gepinnter Regelkatalog](cleanroom/domain-rules.json) — einzige Quelle der Jahreskonstanten.
