# Anforderungsdokument Anlagenverzeichnis und AfA (EUE-049 … EUE-061)

**Status:** abgeleitetes Clean-Room-Artefakt, gültig für Wirtschaftsjahr 2026.
**Grundlage:** ausschließlich die Fachfelder des autoritativen Regelkatalogs.
Keine Zellbezüge, keine Engine-Klassen, keine Methodennamen.
**Einheiten:** Beträge ganze Cent; Nutzungsdauer in Jahren; Anteile in
5-%-Schritten.

## 1. Lineare AfA und Halbjahresregel (EUE-049)

```
AfA/Jahr = Anschaffungskosten / Nutzungsjahre × Faktor
Faktor = 1 (Anschaffung in erster Jahreshälfte)
       = 0,5 (Anschaffung in zweiter Jahreshälfte)
Nutzungsjahre = 0 → keine AfA
```

Rechtsgrundlage § 7 Abs. 1 EStG. Kennzahlen 9130, 9134, 9135.

## 2. Restbuchwert (EUE-050)

```
Restbuchwert = max(0,01 €, Anschaffungskosten − bisherige AfA)
```

Bei 0 Nutzungsjahren bleibt der Restbuchwert in Höhe der Kosten. Mindestwert
0,01 €. Rechtsgrundlage § 7 EStG.

## 3. Geringwertige Wirtschaftsgüter (EUE-051)

Hinweis auf sofortige Erfassung im Ausgabenjournal statt im Anlagenverzeichnis,
wenn die Anschaffungskosten unter 1.000 € liegen. Genau 1.000 € löst keinen
Hinweis aus. Rechtsgrundlage § 13 EStG.

## 4. Stammdaten und Zuordnung (EUE-052 … EUE-054, EUE-059 … EUE-061)

- Nutzungsjahre Pflicht, 2–99; Wert 1 → Hinweis (nicht abnutzbar = 0 oder
  mindestens 2 Jahre). 0 = nicht abnutzbar (EUE-052).
- AfA-Kennzahl erforderlich: 9130, 9134, 9135 oder 9500 (EUE-053).
- Mindestbetriebsanteil 50 %; darunter gelten die Güter als Privatvermögen
  (EUE-054). Rechtsgrundlage § 6 Abs. 2 EStG.
- Privatanteil (EUE-055): muss als „Entnahme" im Einnahmenjournal unter
  derselben Belegnummer gebucht sein; sonst Hinweis mit Betrag. Bereich:
  mehr als 50 % und weniger als 100 %.
- Pflichtfelder (EUE-059): Belegnummer, Jahr, Monat, Tag, Bezeichnung.
  Anschaffungsjahr nicht in der Zukunft (EUE-058).
- Laufende Nummer nur für tatsächliche Einträge (EUE-060).
- Kennzahl aus der Ausgabenart-Zuordnung; ohne Zuordnung 9130 (EUE-061).

## 5. Plausibilität (EUE-056, EUE-057)

- Vorjahres-AfA gegen erwartete AfA prüfen; Toleranz 0,01 € (EUE-056).
- Fehlende/veraltete Vorjahreswerte AfA/Restbuchwert als Warnung; Restbuchwert
  gegen die erwartete Formel prüfen (EUE-057).

## 6. Regel → Verhalten

| Regel | Verhalten |
|---|---|
| EUE-049 | Lineare AfA, Halbjahresfaktor 0,5/1 |
| EUE-050 | Restbuchwert, Mindestwert 0,01 € |
| EUE-051 | GWG-Hinweis unter 1.000 € |
| EUE-052/053 | Nutzungsdauer 0 oder 2–99; AfA-Kennzahl Pflicht |
| EUE-054/055 | Mindestbetriebsanteil 50 %; Privatanteil als Entnahme |
| EUE-056/057 | Plausibilität AfA und Vorjahreskonsistenz |
| EUE-058 … EUE-061 | Jahr, Pflichtfelder, laufende Nummer, Kennzahlzuordnung |

## 7. Prüfvorbehalt

Nutzungsdauer, Halbjahresregel, GWG-Grenze und Behandlung der Privatnutzung
sind extern steuerlich zu bestätigen. dokumentierter Vorbehalt, kein Blocker.
Dieses Dokument ist eine Anforderung, keine steuerliche Beratung.
