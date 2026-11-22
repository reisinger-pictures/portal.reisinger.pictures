# Soll-Dokumentation der Quellarbeitsmappe (Blatt für Blatt)

**Zweck:** Sicherstellen, dass keine Seite der Quellarbeitsmappe übersehen wurde und dass der
Regelkatalog `business-rules/domain-rules.json` die Soll-Themen jedes Blatts vollständig abdeckt.
**Quelle:** `local/input.xlsx` (nicht im Repo), Clean-Room-Dumps in `analysis/<Blatt>.txt`,
Regelkatalog mit 131 Regeln.
**Prüfung:** `python3 tools/sheet_coverage.py --check` – schlägt fehl, wenn ein Blatt keine
Dokumentation, keine Regel oder keine eigene Regel hat oder eine Regel ein unbekanntes Blatt nennt.

Die Arbeitsmappe hat **16 Blätter**, alle sichtbar, keine versteckten Blätter. Jedes Blatt ist
unten mit Zweck, Soll-Themen, Regeln und dem Umsetzungsstand zum Zeitpunkt der Gap-Analyse
beschrieben.

> **Historischer Stand:** Die Abschnitte „Heute“ dokumentieren die Lücken vor der
> Umsetzung. Der aktuelle Stand steht in `verification/parity-report.md`
> (121 von 131 Regeln `covered`, 10 Arbeitsmappen-Artefakte `not-applicable`);
> #27, #28, #29, #31 und #32 sind abgeschlossen.

---

## Einstellungen

**Zweck:** Zentrale Stammdaten und alle Kataloge, aus denen die Journale ihre Auswahlfelder und
Standardwerte ziehen.

**Soll-Themen:** Wirtschaftsjahr und Kompatibilitätswarnung; Firmenname, Anschrift, PLZ/Ort, UID,
ÖNACE-Branchenkennzahl; Zahlarten mit Startsaldo; Projektliste; Ausgaben- und Einnahmen-Arten mit
E1A-Zuordnung und Betriebsanteil; USt-Arten; Transportmittel und Fahrzeugtypen; Feiertage;
Reise-Pauschalen je Land; SV-Parameter (Beitragsgrundlage, KV/PV/SVS/UV); Wertebereiche und
Duplikatwarnungen.

**Regeln:** `EUE-001`, `EUE-002`, `EUE-003`, `EUE-004`, `EUE-005`, `EUE-006`, `EUE-007`,
`EUE-008`, `EUE-009`, `EUE-010`, `EUE-011`, `EUE-012`, `EUE-013`, `EUE-014`, `EUE-015`,
`EUE-016`, `EUE-017`, `EUE-018`, `EUE-019`, `EUE-020`, `EUE-021`, `EUE-022`, `EUE-023`,
`EUE-024`, `EUE-025`, `EUE-026`, `EUE-027`, `EUE-028`, `EUE-029`, `EUE-030`, `EUE-033`,
`EUE-048`.

**Heute:** Es gibt keine Einstellungsseite. Kategorien sind Freitext, Betriebsanteil wird je
Buchung gewählt, Reise-Pauschalen liegen serverseitig in `rates.json`. Firmendaten, Zahlarten,
Kennzahlen-Zuordnung, USt-Arten, Fahrzeugtypen und Feiertage fehlen. Soll: #27.

---

## E

**Zweck:** Einnahmenjournal des Wirtschaftsjahres.

**Soll-Themen:** Belegnummer, Monat, Tag, UID, Kommentar, Zahlart, Projekt, Einnahmen-Art,
Bruttobetrag, E1A-Kennzahl, Betriebsanteil, betriebliche Einnahme; laufende Nummer und
Zeilenprüfung; Mieteinnahmen gehören in E1B.

**Regeln:** `EUE-031`, `EUE-032`, `EUE-034`, `EUE-035`, `EUE-036`, `EUE-037`, `EUE-127`.

**Heute:** Buchungen mit Einnahmen-Art, Betriebsanteil und Monatszuordnung existieren; es fehlen
Belegnummer, UID, Zahlart, Projekt, E1A-Kennzahl und die Zeilenprüfung. Soll: #27, #28.

---

## A

**Zweck:** Ausgabenjournal des Wirtschaftsjahres.

**Soll-Themen:** Belegnummer, Monat, Tag, Kommentar, Zahlart, Projekt, Ausgaben-Art,
Bruttobetrag, USt-Art, E1A-Kennzahl, Betriebsanteil, betriebliche Ausgabe, Vorsteuer aus
IG-Erwerb, USt aus Reverse Charge, Belegpflicht, Wareneinkauf-Verweis ins WEB.

**Regeln:** `EUE-038`, `EUE-039`, `EUE-040`, `EUE-041`, `EUE-042`, `EUE-043`, `EUE-044`,
`EUE-130`.

**Heute:** Ausgaben mit Kategorie, Betriebsanteil und optionalem Beleg existieren; USt-Art,
Vorsteuer, Reverse Charge, Belegnummer, Zahlart, Projekt und die Pflicht-Belegprüfung fehlen.
Soll: #27, #28.

---

## Ü

**Zweck:** Übersichtsblatt mit drei Rechnern: Gewinn, Einkommensteuer, Sozialversicherung,
zusätzlich Stunden und monatliche Kennzahlen.

**Soll-Themen:** Einnahmen, Ausgaben (mit/ohne SV), Gewinn; Einkommensteuertarif in fünf Stufen
inkl. Progressionsrechnung, Prognose- und Sonder modi, Vorauszahlungen; SV-Modus,
Jungunternehmer, Beitragsgrundlage und Deckelung, KV/PV/SVS/UV, Mindestbeiträge, Monatsanteile,
Reingewinn; Stunden; farbliche Hervorhebung.

**Regeln:** `EUE-089`, `EUE-090`, `EUE-091`, `EUE-092`, `EUE-093`, `EUE-094`, `EUE-095`,
`EUE-103`, `EUE-104`, `EUE-105`, `EUE-106`, `EUE-107`, `EUE-108`, `EUE-109`, `EUE-110`,
`EUE-111`, `EUE-112`, `EUE-113`, `EUE-114`, `EUE-115`, `EUE-125`, `EUE-126`, `EUE-128`,
`EUE-131`.

**Heute:** Die Übersicht zeigt Monats-KPIs (Einnahmen, Ausgaben, Gewinn, Stunden). Es gibt keine
Einkommensteuer- und keine SV-Berechnung. Soll: #27 (SV, Übersicht), #28 (ESt).

---

## AV

**Zweck:** Anlagenverzeichnis mit AfA und Restbuchwerten.

**Soll-Themen:** Belegnummer, Jahr/Monat/Tag, Lieferant, Bezeichnung, Kommentar, Projekt,
Ausgaben-Art, Kosten brutto, Nutzungsjahre, Abschreibung und Restbuchwert des Vorjahres,
Abschreibung und Restbuchwert des laufenden Jahres, E1A-Kennzahl; Halbjahresregel, GWG,
Mindestbetriebsanteil, Privatanteil/Entnahme, Vorjahreskonsistenz, Zukunftsjahr.

**Regeln:** `EUE-049`, `EUE-050`, `EUE-051`, `EUE-052`, `EUE-053`, `EUE-054`, `EUE-055`,
`EUE-056`, `EUE-057`, `EUE-058`, `EUE-059`, `EUE-060`, `EUE-061`.

**Heute:** Kein Anlagenverzeichnis. Nur die manuelle Jahresbuchung „Private Nutzung“ mit selbst
eingetippten Jahreskosten. Soll: #29, Spezifikation `verification/asset-register.md`.

---

## FR

**Zweck:** Fahrten- und Reisekostenblatt.

**Soll-Themen:** Reiseinformationen (Start/Ende Datum und Zeit, Projekt, Veranlassung, Route,
Transportmittel, Mitfahrer), Kilometerstand oder Distanz, Kilometergeld je Fahrzeugtyp,
Kilometerkontingente, Mitfahrerbegrenzung, Land/Region und Einsatzort, Nächtigungskosten,
Verpflegungsmehraufwand mit Mahlzeitenabzug und 5-/15-Tage-Regel, Hin-/Rückfahrt,
Werktage/Feiertage, Reisejahr, zeitliche Konsistenz, Belegnummer, BT/PT-Aufteilung,
Weiterleitung in die Auswertungen.

**Regeln:** `EUE-062`, `EUE-063`, `EUE-064`, `EUE-065`, `EUE-066`, `EUE-067`, `EUE-068`,
`EUE-069`, `EUE-070`, `EUE-071`, `EUE-072`, `EUE-073`, `EUE-074`, `EUE-075`, `EUE-076`,
`EUE-077`, `EUE-078`, `EUE-079`, `EUE-080`, `EUE-081`.

**Heute:** Reisen mit Zeitraum, Tagen, Nächten, Stunden, Land, Pauschalen und Beleg existieren;
Kilometergeld wird mit manuellem Satz gerechnet, Mitfahrer-, Kontingent-, Mahlzeiten-,
Werktage- und Hin-/Rückfahrtregeln fehlen. Soll: #23 (erledigt), #28 (Rest).

---

## WEB

**Zweck:** Wareneingangsbuch.

**Soll-Themen:** Belegnummer, Monat, Tag, Lieferant, Sammelbezeichnung, Kommentar, Zahlart,
Projekt, Ausgaben-Art, Preis brutto, Betriebsanteil, betriebliche Ausgabe; Abgleich mit dem
Ausgabenjournal und Plausibilität.

**Regeln:** `EUE-045`, `EUE-046`, `EUE-047`.

**Heute:** Nicht vorhanden; Wareneinkäufe laufen als normale Ausgabe. Soll: #28.

---

## E1A

**Zweck:** Einkommensteuererklärung E1A (Einzelunternehmer/in).

**Soll-Themen:** Einnahmen und Ausgaben je Kennzahl, betrieblicher Jahresgewinn,
Grundfreibetrag (Höchstgrenze, Prozentsatz, Berechnung), Arbeitsplatzpauschale (9215/9217),
ergonomisches Mobiliar (9216), Kennzahl 330.

**Regeln:** `EUE-096`, `EUE-097`, `EUE-098`, `EUE-099`, `EUE-101`, `EUE-102`.

**Heute:** Nicht vorhanden; nur Kategorien ohne Kennzahlen. Soll: #28 (Auswertung E1A).

---

## E1B

**Zweck:** Einkommensteuererklärung E1B (Vermietung und Verpachtung).

**Soll-Themen:** Mieteinnahmen (9460), Werbungskosten der Vermietung (9530), getrennte
Auswertung von E1A.

**Regeln:** `EUE-100`.

**Heute:** Vermietungskategorien existieren als Buchungskategorien, aber keine E1B-Auswertung.
Soll: #28.

---

## UST

**Zweck:** Umsatzsteuervoranmeldung und Umsatzsteuererklärung.

**Soll-Themen:** Kennzahlen 000, 016 (Kleinunternehmer), 057 (Reverse Charge), 070/072/073/008
(IG-Erwerb), 125, 20/10/13/4,9 %, Zahllast, Quartalsaggregation, erste und zweite Erklärung.

**Regeln:** `EUE-082`, `EUE-083`, `EUE-084`, `EUE-085`, `EUE-086`, `EUE-087`, `EUE-088`.

**Heute:** Nicht vorhanden; USt-Art an Buchungen fehlt. Soll: #28.

---

## BE-ART

**Zweck:** Betriebseinnahmen nach Art, Monatsspalten und Jahressumme, Anteil in Prozent.

**Soll-Themen:** Aggregation der betrieblichen Einnahmen je Einnahmen-Art und Monat;
Vollständigkeitsabgleich gegen das E-Journal.

**Regeln:** `EUE-119`, `EUE-129`.

**Heute:** Nicht vorhanden (nur Gesamt-KPIs). Soll: #28.

---

## BA-ART

**Zweck:** Betriebsausgaben nach Art, Monatsspalten und Jahressumme, Anteil in Prozent.

**Soll-Themen:** Aggregation der betrieblichen Ausgaben je Ausgaben-Art und Monat über A, AV und
WEB; Vollständigkeitsabgleich.

**Regeln:** `EUE-120`.

**Heute:** Nicht vorhanden. Soll: #28.

---

## Projekte

**Zweck:** Auswertung je Projekt (Allgemein, Hauptprojekt, Nebenprojekt, Vermietung, …).

**Soll-Themen:** Einnahmen, Ausgaben und Ergebnis je Projekt und Monat; Projektzuordnung in den
Journalen; Kundenbezug als Erweiterung (#27).

**Regeln:** `EUE-121`.

**Heute:** Projekte gibt es nur als Freitext im Reise-Eigenbeleg; keine Auswertung, keine
Kunden. Soll: #27 (Kunden/Projekte), #28 (Auswertung).

---

## ZA

**Zweck:** Zahlartenabgleich (Kontostände je Zahlart und Monat).

**Soll-Themen:** Startsaldo je Zahlart, Eingänge, Ausgänge, laufender Saldo je Monat;
Abgleich gegen E/A/WEB.

**Regeln:** `EUE-122`.

**Heute:** Zahlart ist an Buchungen nicht vorhanden. Soll: #27.

---

## Monat

**Zweck:** Monatsübersicht als PDF (EA-Rechnung).

**Soll-Themen:** Firmenname, Monat, Gewinn, Einnahmen, Ausgaben, Stunden, USt-Zeilen,
Zeilenfilter, PDF-Erzeugung.

**Regeln:** `EUE-116`, `EUE-117`, `EUE-118`, `EUE-124`.

**Heute:** Monats-PDF mit Buchungen, Belegen, Summen und Signatur existiert; USt-Zeilen,
Zeilenfilter und der Arbeitsmappen-Schalter fehlen. Soll: #28.

---

## Stunden

**Zweck:** Arbeitsstundenbuch.

**Soll-Themen:** Tag, Monat, Kommentar, Projekt, Stunden; Zwölfteleinheiten für die
Monatsverteilung; Verknüpfung mit Reisen.

**Regeln:** `EUE-123`.

**Heute:** Stunden werden je Reise erfasst und im PDF ausgewiesen; ein eigenständiges
Stundenbuch mit Zwölfteleinheiten fehlt. Soll: #28.

---

## Vollständigkeitsnachweis

| Prüfung | Ergebnis |
|---|---|
| Blätter in der Arbeitsmappe | **16**, alle sichtbar, keine versteckten |
| Clean-Room-Dumps | 16/16 in `analysis/` |
| Regeln im Katalog | **131**, jede mit mindestens einer Quellzelle |
| Blätter ohne Regel | **0** |
| Regeln ohne Blatt | **0** |
| Blätter ohne Abschnitt in diesem Dokument | **0** |
| Primärregeln je Blatt | 131/131 genau einem Blatt zugeordnet |
| Definierte Namen | 158; 62 nicht wörtlich in Regeln genannt |

Die 62 nicht wörtlich genannten definierten Namen sind **keine Lücke**: Es handelt sich um
Spaltenaliasse (`a_kommentare`, `av_kosten`, `fr_bt`, `web_tage`, …), die nur Zellbereiche
benennen, sowie um Werte, die über Zellregeln abgedeckt sind (`set_fahrzeuge_typen` → `EUE-011`,
`ue_est_*`/`ue_est_modus` → `EUE-089`…`EUE-105`, `ue_bruttolohn`/`ue_prgn_gw` → `EUE-104`/`105`).
Der Prüfskript meldet sie als Hinweis, nicht als Fehler.

**Bekannte Soll-Lücken (bewusst, mit Ticket):**

- **#26** Cent-Umstellung (Voraussetzung für alle Beträge).
- **#30** Betriebsanteil in 5-%-Schritten statt 10 % (Nutzerentscheidung 2026-11-21).
- **#27** Einstellungen, Kunden/Projekte, SV, Ausfüllhilfe, Übersicht.
- **#29** Anlageverzeichnis (B05) inkl. jährlicher Privatnutzung.
- **#28** Restliche Parität (B01–B11), inkl. E1A/E1B/UST/AfA-fremde Bereiche.

**Wiederholbare Prüfung:**

```bash
python3 tools/sheet_coverage.py --check
python3 tools/validate_domain_rules.py
python3 tools/parity_report.py --check
```

Damit ist „keine Seite übersehen“ nicht nur eine Aussage, sondern bei jeder Änderung erzwingbar.
