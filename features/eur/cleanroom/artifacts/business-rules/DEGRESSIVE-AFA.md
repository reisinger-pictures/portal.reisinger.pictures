# Degressive AfA — abgeleitete Business Rules (DEG-001 … DEG-015)

**Quelle:** <https://kalkuel.at/unternehmensratgeber/abschreibung-oesterreich/>
(„Österreich: Abschreibung & Anlagenverzeichnis – Der vollständige Leitfaden",
abgerufen am 2026-11-22).

**Rolle dieser Regeln:** Die Clean-Room-Arbeitsmappe kennt ausschließlich die
lineare AfA. Die degressive AfA ist deshalb eine bewusste
**Produkterweiterung** mit eigenen Kennungen (`DEG-*`), keine `EUE-*`-Regel.
Der strukturierte Katalog steht in
[`degressive-afa-rules.json`](./degressive-afa-rules.json); die
Clean-Room-Parität (`tools/parity_report.py`) bleibt davon unberührt.

**Rechtsvorbehalt:** Die Quelle ist eine Sekundärquelle. Primärquelle (EStG,
USP, BMF-Information) und steuerliche Rechtsprüfung sind **offen**. Das Produkt
gibt keine Steuerberatung.

## Regeln

| ID | Regel | Typ |
|---|---|---|
| DEG-001 | Methodenwahl im ersten Wirtschaftsjahr; kein späterer Wechsel linear → degressiv | Wahlrecht |
| DEG-002 | Degressiver Satz 1–30 % des Restwerts, frei wählbar, konstant | Parameter |
| DEG-003 | AfA = Satz × Restbuchwert, ganzzahlige Cent | Berechnung |
| DEG-004 | Letztes Jahr schreibt den Rest ab; Produkt behält 0,01 € Erinnerungswert | Berechnung |
| DEG-005 | Halbjahresregel gilt auch degressiv (Quelle: Inbetriebnahme) | Berechnung |
| DEG-006 | Keine degressive AfA für gebrauchte Wirtschaftsgüter | Validierung |
| DEG-007 | Keine degressive AfA für immaterielle Güter, außer Digitalisierung/Ökologisierung/Gesundheit | Validierung |
| DEG-008 | Keine gesetzliche Sonder-AfA; Elektroautos sind zulässig | Validierung |
| DEG-009 | Einmaliger Wechsel degressiv → linear zum Jahresbeginn, kein Rückwechsel | Wahlrecht |
| DEG-010 | Wechselbasis ist der Restbuchwert, verteilt auf die Restjahre | Berechnung |
| DEG-011 | Wechselempfehlung, sobald linear ≥ degressiv | Berechnung |
| DEG-012 | Vergleichsrechner linear vs. degressiv, auch ohne gespeichertes Anlagegut | Abgleich |
| DEG-013 | Nicht blockierende Wechsel-Erinnerung in Übersicht und Detail | Abgleich |
| DEG-014 | Wechsel mit Pflicht-Begründung und Audit-Eintrag; gebuchte Jahre unverändert | Validierung |
| DEG-015 | EUE-056/EUE-057 prüfen gegen den tatsächlich gewählten Plan | Abgleich |

## Rechenbeispiele (Quelle, in Cent nachgerechnet)

Anschaffung 20.000,00 €, Nutzungsdauer 10 Jahre, Satz 30 %:

| Jahr | AfA degressiv | Restbuchwert |
|---:|---:|---:|
| 1 | 6.000,00 € | 14.000,00 € |
| 2 | 4.200,00 € | 9.800,00 € |
| 3 | 2.940,00 € | 6.860,00 € |
| … | … | … |
| 7 | 705,89 € | 1.647,09 € |
| 8 (Wechsel) | 549,03 € | 1.098,06 € |
| 9 | 549,03 € | 549,03 € |
| 10 | 549,02 € | 0,01 € |

Das Quellbeispiel endet bei 0,00 €; der Prototyp behält konsistent zur linearen
AfA 0,01 € (DEG-004). Die Summe aller AfA beträgt daher 19.999,99 €.

Empfehlung: Wechsel in Jahr 8, weil linear 549,03 € ≥ degressiv 494,13 €.
Jahresvorteil 54,90 €; maximaler kumulierter Vorteil bis Laufzeitende
258,04 € (`advantage_cents = 25804`).

## Nachweise

Jede Regel braucht einen Unit- **und** einen E2E-Beleg.

| Regel | Unit (Smoke) | E2E (Playwright) |
|---|---|---|
| DEG-001 | `DEG-001 stores the method and the rate` | `Degressive AfA: Vergleich, Erinnerung und einmaliger Wechsel` |
| DEG-002 | `DEG-002 rejects a degressive rate of 31 %/0 %` | `… Satzgrenzen und Ausschlüsse …` (Options 1–30, API 422) |
| DEG-003 | `DEG-003 year 1 takes 30 % …` | Vergleichstabelle `6.000,00 €` vs. `2.000,00 €` |
| DEG-004 | `DEG-004 the last year writes off the remainder …` | Vergleichstabelle / Restbuchwertspalte |
| DEG-005 | `DEG-005 a July acquisition takes half …` | `… Satzgrenzen und Ausschlüsse …` (Juli-Anschaffung → 300.000 ct) |
| DEG-006 | `DEG-006 rejects degressive AfA for used goods` | `… Ausschlüsse …` (422) |
| DEG-007 | `DEG-007 rejects immaterial goods without an exception` / allows `digitalisation` | `… Ausschlüsse …` (422) |
| DEG-008 | `DEG-008 rejects goods with a statutory special depreciation` / allows electric cars | `… Ausschlüsse …` (422 + Checkbox) |
| DEG-009 | `DEG-009 a second switch is rejected`, acquisition year, linear → degressive | Wechsel-Button verschwindet nach dem Wechsel |
| DEG-010 | `DEG-010 switching in year 8 spreads 1.647,09 € …` | `asset-year-method-<Jahr>` wird `linear` |
| DEG-011 | `DEG-011 recommends the first year …` + annual/peak advantage | `asset-switch-hint` mit Jahr und 549,03 € |
| DEG-012 | `DEG-012 the comparison endpoint returns both plans` | `asset-comparison-table` |
| DEG-013 | `DEG-013 the asset carries …` / reminder disappears | `asset-switch-reminder` erscheint und verschwindet |
| DEG-014 | `DEG-014 a switch without a reason is rejected` | Fehlermeldung „Begründung", danach `asset-switch-done` |
| DEG-015 | `DEG-015 a correct degressive plan raises no EUE-056 false alarm` | Warnungsbereich bleibt leer |

## Offene Punkte (blockieren die Rechtsfreigabe, nicht die Umsetzung)

1. **Gesetzesstelle und Anwendungszeitraum:** Welche Wirtschaftsjahre und
   Fahrzeugtypen sind begünstigt? Die Quelle nennt keinen Paragraphen.
2. **Satzwahl:** Ist „bis zu 30 %" eine freie Wahl oder nur der Höchstsatz?
   Muss der Satz während der Laufzeit konstant bleiben?
3. **Halbjahresregel:** Gilt sie für die degressive AfA gleich? Die Quelle
   stellt auf die **Inbetriebnahme** ab; der Prototyp speichert nur das
   Anschaffungsdatum und verwendet es als Näherung.
4. **Wechsel:** Erfordert der Wechsel eine Erklärung/Aufzeichnung? Ist ein
   Wechsel nur am Jahresbeginn oder auch unterjährig zulässig?
5. **Ausscheiden:** Die Quelle beschreibt die Halbjahresregel auch beim
   Ausscheiden; der Prototyp bucht den Verkauf separat und prüft dies noch
   nicht.
6. **Elektroautos:** Zeitliche Befristung und Höchstbetrag sind zu prüfen.
7. **Immaterielle Ausnahmen:** Definition von Digitalisierung, Ökologisierung
   und Gesundheit/Life-Science ist zu präzisieren.
8. **GWG/Privatnutzung:** Zusammenspiel von Sofortabschreibung,
   Privatanteil und degressiver Methode ist steuerlich zu bestätigen.
