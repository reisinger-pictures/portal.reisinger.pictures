# Anforderungsdokument Einkommensteuer (EUE-089 … EUE-106)

**Status:** abgeleitetes Clean-Room-Artefakt, gültig für Wirtschaftsjahr 2026.
**Grundlage:** ausschließlich die Fachfelder des autoritativen Regelkatalogs
(`titel`, `wirkung`, `parameter`, `gueltigkeit`, `kennzahlen`, `rechtsgrundlage`).
Keine Zellbezüge, keine Engine-Klassen, keine Methodennamen, keine
Formelstrings aus dem technischen Belegfeld.
**Einheiten:** Ergebniswerte ganze Cent; EUR-Werte der Regeln werden an der
Lesegrenze in Cent überführt. Raten sind exakte Bruchteile.

## 1. Tarifstufen (EUE-089 … EUE-093)

Der Tarif ist stufenweise progressiv. Grenzen sind jahresabhängig; für 2026
gültig, 2024/2025 nur als Vergleichszweig.

| Stufe | Satz | Grenze 2024 | Grenze 2025 | Grenze 2026 | Grenze 2026 (Cent) |
|---:|---:|---:|---:|---:|---:|
| 1 | 20 % | 12.816 | 13.308 | 13.539 | 1.353.900 |
| 2 | 30 % | 20.818 | 21.617 | 21.992 | 2.199.200 |
| 3 | 40 % | 34.513 | 35.836 | 36.458 | 3.645.800 |
| 4 | 48 % | 66.612 | 69.166 | 70.365 | 7.036.500 |
| 5 | 50 % | 99.266 | 103.072 | 104.859 | 10.485.900 |

Rechtsgrundlage: § 33 Abs. 1 EStG.

**Progressionsregel (EUE-094), steuerrechtlich formuliert:**

```
G₀ = 0, G₅ = unbegrenzt
Steuer(E) = Σᵢ₌₁⁵ sᵢ × max(0, min(E, Gᵢ) − Gᵢ₋₁)
```

Jeder Einkommensanteil wird nur in seiner eigenen Stufe besteuert; die Summe
der fünf Stufen ergibt die Einkommensteuer. Grenzwerte sind in Cent zu
vergleichen; ein Einkommen genau auf einer Grenze gehört zur unteren Stufe.

## 2. Grundfreibetrag (EUE-096 … EUE-098)

- Höchstgrenze: 33.000 € in allen unterstützten Jahren (EUE-096; vor 2024
  würde die Quelle 30.000 € nennen, das liegt außerhalb des Geltungsbereichs).
- Satz: 15 % in allen unterstützten Jahren (EUE-097).
- Bemessung (EUE-098):

```
GFB = max(0, min(0,15 × Gewinn, 0,15 × 33.000 €))
    = max(0, min(0,15 × Gewinn, 4.950 €))      // 495.000 Cent
zu versteuernder Gewinn = Gewinn − GFB
```

Der Grundfreibetrag wird vom Gewinn abgezogen, nicht von der Steuer.

## 3. Gesamteinkommen und Aufteilung (EUE-095, EUE-103 … EUE-106)

- Rechenmodi: `Gesamt`, `Nur Betrieb`, `Nur Vermietung` (EUE-106 nennt diese
  Auswahl). Je Modus fließen betriebliche Einkünfte, Vermietungsergebnis,
  nichtselbständige Einkünfte und Prognosewerte unterschiedlich zusammen
  (EUE-095).
- Zusätzliche interne Varianten: `Mit Prognose`, `Nur Bruttolohn`,
  `Nur andere Gewinne`. Sie steuern Sichtbarkeit von Zeilen und die
  Behandlung der Sozialversicherung (EUE-105, EUE-106).
- Die Steuer wird anteilig auf nichtselbständige Einkünfte, Vermietung und
  Betrieb aufgeteilt; Einkommensteuer-Vorauszahlung und bezahlte Lohnsteuer
  werden abgezogen (EUE-103, KZ 245/KZ 260).
- Prognose hochgerechnete betriebliche/Vermietungsgewinne auf das Jahr
  (EUE-104).

## 4. E1A und E1B (EUE-099, EUE-100)

- E1A: Beträge je Kennzahl aus den Journalen; 9100 zusätzlich aus dem
  Wareneingang, 9160 aus Reisekosten, 9130/9134/9135 aus dem
  Anlagenverzeichnis; die Restsumme in 9230. Kennzahlen u. a. 9040, 9050,
  9060, 9090, 9100, 9130, 9134, 9135, 9160, 9225, 9230, E1B 9460.
- E1B: Mieteinnahmen und Werbungskosten je Kennzahl aus Einnahmen-,
  Ausgaben- und Anlagenjournalen; Überschuss Einnahmen − Werbungskosten =
  Vermietungsergebnis. Kennzahlen u. a. 9414, 9460, 9500, 9135, 9510, 9520,
  9521, 9530.

## 5. Pauschalen (EUE-101, EUE-102)

| Tatbestand | Kennzahl | Bedingung |
|---|---|---|
| Kleine Arbeitsplatzpauschale | 9215 | bis 300 € |
| Große Arbeitsplatzpauschale | 9217 | über 300 € bis 1.200 € |
| Arbeitszimmer zugesagt | 9275 | Wert löschen, Pauschale entfällt |
| Ergonomisches Mobiliar | 9216 | ausgewiesen; unterdrückt nur bei 9275 oder leerem Betrag; große Pauschale 9217 erzeugt nur Warnhinweis |

Eingabe-Minimum 25 € (EUE-101), Eingabe-Maximum 300 € (EUE-102).

## 6. Regel → Verhalten

| Regel | Verhalten |
|---|---|
| EUE-089 … EUE-093 | Fünf Tarifstufen mit jahresabhängigen Grenzen und festen Sätzen |
| EUE-094 | Stufenweise Progressionsrechnung, Summe der Stufen |
| EUE-095 | Gesamteinkommen je nach Modus |
| EUE-096/097 | Grundfreibetrag Grenze 33.000 €, Satz 15 % |
| EUE-098 | GFB = kleinerer Wert aus 15 % Gewinn / 15 % Grenze, mindestens 0 |
| EUE-099/100 | Aggregation E1A/E1B je Kennzahl |
| EUE-101/102 | Arbeitsplatz- und Ergonomie-Pauschalen inkl. Unterdrückung |
| EUE-103 | Aufteilung und Abzug von Vorauszahlungen/Lohnsteuer |
| EUE-104 … EUE-106 | Prognose und Rechenmodi inkl. interner Varianten |

## 7. Prüfvorbehalt

Externe steuerliche Prüfung des Tarifs, der Grenzen und der Pauschalen bleibt
ein dokumentierter Vorbehalt und kein Blocker. Dieses Dokument ist eine
Anforderung, keine steuerliche Beratung.
