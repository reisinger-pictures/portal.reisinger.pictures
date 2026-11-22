# Anforderungsdokument Reise- und Fahrtkosten (EUE-062 … EUE-081)

**Status:** abgeleitetes Clean-Room-Artefakt, gültig für Wirtschaftsjahr 2026.
**Grundlage:** ausschließlich die Fachfelder des autoritativen Regelkatalogs.
Keine Zellbezüge, keine Engine-Klassen, keine Methodennamen.
**Einheiten:** Beträge ganze Cent, Entfernungen km, Zeiten ganze Minuten.
Raten sind exakte Bruchteile.

## 1. Kilometergeld (EUE-062 … EUE-065)

| Transportmittel | 2024 | 2025 H1 | 2025 H2 | 2026 | Mitfahrer |
|---|---:|---:|---:|---:|---:|
| PKW/Kombi | 0,42 €/km | 0,50 | 0,50 | 0,50 | +0,05 (2024) / +0,15 (2025+), max. 6 |
| Motorrad | 0,24 €/km | 0,50 | 0,25 | 0,25 | +0,05 (2024) / +0,15 (2025+), max. 1 |
| Fahrrad/E-Bike | 0,38 €/km | 0,50 | 0,25 | 0,25 | — |

**Kontingente (EUE-065):** PKW + Motorrad zusammen 30.000 km;
Fahrrad/E-Bike 1.500 km (2024) bzw. 3.000 km (2025, 2026).

**Mitfahrer (EUE-066):** PKW höchstens 6, Motorrad höchstens 1; bei anderen
Transportmitteln muss das Feld leer oder 0 sein.

**Entfernung (EUE-067):** entweder Anfangs- und Endkilometerstand *oder* die
Distanz angeben — beides gleichzeitig ist ein Fehler.

Rechtsgrundlage: § 16 Abs. 1 Z 2 EStG (lit. a/b/c).

## 2. Tagesgeld, Mahlzeiten, Nächtigung (EUE-068 … EUE-070)

- Tagesgeld wird anteilig für zwölf Monate berechnet (EUE-068).
- Mahlzeitenabzug: 1 Mahlzeit Inland = halbe Pauschale, Ausland = volle
  Pauschale; 2 Mahlzeiten Inland = kein Tagesgeld, Ausland = ein Drittel.
- Nächtigung ohne Beleg (EUE-069): Inland 4,50 € (ab 2024), Ausland 5,85 €;
  sonst gilt die Ländertabelle (174 Länder/Regionen).
- 5-/15-Tage-Regel (EUE-070): ab dem 5. Tag entfällt das Tagesgeld, ab dem
  15. Tag das Nächtigungsgeld; der Reisestatus weist dies aus.

Rechtsgrundlage: § 16 Abs. 1 Z 3 EStG.

## 3. Reisezeit und Betriebsanteil (EUE-071 … EUE-074, EUE-080)

- Hin-/Rückfahrt (EUE-071): Markierung, kein Tagesgeld; Veranlassung und
  Einsatzort bleiben leer. Zeitgrenze 3 Stunden; Mindestentfernung 50 km für
  eine Einzelzeile bzw. 25 km für verkettete Zeilen (EUE-071).
- Betriebsanteil (EUE-072): Verhältnis betriebliche / gesamte Reisezeit.
  Unter 10 % → 0 %, mindestens 90 % → 100 %.
- Private Veranlassung (EUE-073): betriebliche Ausgabe und alle anteiligen
  Kosten = 0; Status „Nein (VERANLASSUNG: Privat)".
- Werktage (EUE-074): Zählung unter Berücksichtigung der jahresabhängigen
  Feiertagsliste inkl. Osterformel (13 Feiertage).
- Arbeitsstunden (EUE-080): Summe aus dem Stundenbuch fließt in die
  3-Stunden-Regel ein.

## 4. Validierung (EUE-075 … EUE-079)

- Reisejahr muss dem Wirtschaftsjahr entsprechen; ungültige Daten werden
  gemeldet (EUE-075).
- Ende nicht vor/gleich Start; bei mehrteiligen Reisen Fortsetzung mit
  Toleranz 0–2 Minuten (EUE-076).
- Belegnummer verpflichtend, keine Doppelvergabe für verschiedene Reisen
  (EUE-077).
- Land/Region und Transportmittel Pflicht und im Stammdatensatz definiert
  (EUE-078).
- Veranlassung/Einsatzort nur in einer Zeile, bei Hin-/Rückfahrt leer
  (EUE-079).

## 5. Gesamtkosten (EUE-081)

```
betriebliche Reiseausgabe = Fahrtkosten + Tagesgeld + Nächtigung
Summe aller Zeilen → Reisekosten in Monats-/Jahresausgaben und Projektauswertung
```

## 6. Regel → Verhalten

| Regel | Verhalten |
|---|---|
| EUE-062/063/064 | km-Sätze je Fahrzeug und Jahr inkl. Mitfahrerzuschlag |
| EUE-065/066 | Kontingente und Mitfahrerbegrenzung |
| EUE-067 | Entfernung aus KM-Stand oder Distanz, nicht beides |
| EUE-068/069 | Tagesgeld mit Mahlzeitenabzug; Nächtigungspauschale/Ländertabelle |
| EUE-070/071/072/073 | 5-/15-Tage-Regel, Hin-/Rückfahrt, Betriebsanteil, Privat |
| EUE-074/080 | Werktage mit Feiertagen; Stunden aus Stundenbuch |
| EUE-075 … EUE-079 | Validierung von Jahr, Zeitraum, Beleg, Land, Veranlassung |
| EUE-081 | Summe und Weiterleitung in Auswertungen |

## 7. Prüfvorbehalt

Externe steuerliche Prüfung von Kilometergeld, Pauschalen, 5-/15-Tage-Regel
und Privatnutzung bleibt dokumentierter Vorbehalt, kein Blocker. Dieses Dokument
ist eine Anforderung, keine steuerliche Beratung.
