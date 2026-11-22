# Clean-Room-Referenz: Einstellungen, Kataloge, Ausfüllhilfe, Versicherungen, Kunden

**Status:** Spezifikation für den Build (nicht implementiert)
**Basis:** `business-rules/domain-rules.json` (131 Regeln, Clean Room), `analysis/tables.json`,
`verification/parity-report.md`
**Regel-IDs:** EUE-004 … EUE-030, EUE-100 … EUE-115, EUE-121, EUE-131

Dieses Dokument beschreibt **eine Einstellungsseite**, die Referenzdaten, wo sie im Tool
vorkommen, die Ausfüllhilfe und die Berechnung von Sozialversicherung und weiteren
Versicherungen. Es erfindet keine fachlichen Werte: alle Werte stammen aus dem
Clean-Room-Katalog. Wo das Tool über die Basis hinausgeht (Kunden, Versicherungsübersicht),
ist das ausdrücklich als **Erweiterung** markiert.

---

## 1. Einstellungsmodell

### 1.1 Betriebsstammdaten (EUE-004)

| Feld | Typ | Validierung | Vorkommen |
|---|---|---|---|
| Firmenname | Text | Pflicht, ≤ 190 | Kopf von E1A, E1B, Monats-PDF, Anträgen |
| Anschrift | Text | optional | Kopf von E1A/E1B/PDF |
| PLZ/Ort | Text | optional | Kopf von E1A/E1B/PDF |
| UID | Text | Format `ATU########` (Warnung, kein Hard-Fail) | E1A/E1B/PDF |
| ÖNACE-Branchenkennzahl | Zahl | 11–990 | E1A-Kopf, Monatsübersicht |
| Wirtschaftsjahr | Zahl | 2024–2026; steuert alle Jahressätze | Überall, wo ein Jahressatz verwendet wird |

**Ausfüllhilfe:** „Die Branchenkennzahl steht in der ÖNACE-Systematik (11–990). Sie erscheint
im E1A-Kopf und in der Monatsübersicht; ohne Eintrag zeigt das Formular einen Hinweis.“

### 1.2 Zahlarten (EUE-005)

| Feld | Typ | Vorkommen |
|---|---|---|
| Bezeichnung | Text (Default „Bankkonto“, „Bar“) | Buchungsdialog, Zahlartenabgleich, PDF |
| Startsaldo | Cent (nach #26) | Zahlartenabgleich (ZA), Übersicht |

**Ausfüllhilfe:** „Der Startsaldo ist der Kontostand am 1. Jänner des Wirtschaftsjahres.
Er wird nur im Zahlartenabgleich verwendet und verändert den Gewinn nicht.“

### 1.3 Kunden und Projekte (EUE-006, EUE-121; Kunden = Erweiterung)

Basis kennt nur eine flache Projektliste (`Hauptprojekt`, `Nebenprojekt`, `Vermietung`).
Gewünscht ist **Kundenbasis**:

```
customers(id, tenant_id, name, external_id, active)
projects(id, tenant_id, customer_id, name, external_id, active)
```

| Feld | Typ | Vorkommen |
|---|---|---|
| Kunde | Auswahl (aktiv/inaktiv) | Buchung, Beleg, Reise, Übersicht, PDF, Integration |
| Projekt | Auswahl, abhängig vom Kunden | Buchung, Beleg, Reise, Auswertung |
| Projektstatus | aktiv/archiviert | Auswahlfilter |

**Ausfüllhilfe:** „Ordne jede Buchung einem Kunden und optional einem Projekt zu. Die
Übersicht zeigt Umsatz, Kosten und Deckungsbeitrag je Kunde; Sozialversicherung bleibt
eine Betriebsausgabe und wird nicht künstlich auf Kunden verteilt (optional proportional
zum Umsatz, klar als Schätzung gekennzeichnet).“

**Regeln:** Jede Buchung/Reise kann genau einen Kunden und ein Projekt referenzieren.
Löschen ist nur Archivieren; bestehende Buchungen behalten die Referenz. Mandantentrennung
gilt für Kunden und Projekte.

### 1.4 Einnahmen- und Ausgaben-Arten (EUE-007, EUE-008, EUE-009)

Die Arten sind Referenzdaten, kein Freitext. Jede Art hat:

| Feld | Typ | Beispiel |
|---|---|---|
| Bezeichnung | Text | „Wareneinkäufe“ |
| Richtung | `income`/`expense` | `expense` |
| E1A/E1B-Kennzahl | Auswahl aus Katalog | `9100 - Waren` |
| Standard-Betriebsanteil | 0–100 in 5-%-Schritten (#30) | `60` bei „Laptop 40 % privat“ |
| Aktiv | bool | archivierte Arten bleiben in Altbuchungen sichtbar |

**Einnahmen-Arten (9, EUE-024):**

| Art | Kennzahl |
|---|---|
| Dienstleistung, Warenverkauf, Private Entnahme | 9040 – Betriebseinnahmen |
| Förderungen | 9090 – Übrige Betriebseinnahmen |
| Verkauf Anlagegüter, Private Entnahme Anlagegüter | 9060 – Erlös abgeg. Anlagegüter |
| Vermietung Einnahmen | E1B 9460 – Mieteinnahmen |
| Private Einnahme, Private Einzahlung | keine Kennzahl, Betriebsanteil 0 |

**Ausgaben-Arten (27 Kennzahlen, EUE-023):**

| Kennzahl | Arten (Beispiele) | Standard-BA |
|---|---|---|
| 9100 – Waren | Wareneinkäufe, Rohstoffe, Fertig- und Einzelteile | 100 |
| 9110 – Fremdpersonal | Materialveredelungen, Freelancer | 100 |
| 9130 – Anlagegüter | Laptop 40 % privat, Drucker 40 % privat | 60 |
| 9130 – Anlagegüter | Firmen-Drucker, Firmenwebsite, GWG, andere Anlagegüter | 100 |
| 9160 – Reisen, Fahrten und Diäten | Einzelne Zugfahrten | 100 |
| 9165 – 50 % Pauschale Massenbeförderung | Klimaticket | 50 |
| 9190 – Provisionen und Lizenzen | Provisionen, Software, Apps | 100 |
| 9200 – Werbung und Repräsentation | Geschäftsessen | 50 |
| 9200 – Werbung und Repräsentation | Visitenkarten, Online-Werbung, Trinkgelder, Spenden | 100 |
| 9210 – Buchwert abgeg. Anlagegüter | Restbuchwert verkaufter Anlagegüter | 100 |
| 9225 – Pflichtversicherungen | SV-Beiträge | 100 |
| 9230 – Übrige Betriebsausgaben | Internetzugang 50 % privat | 50 |
| 9230 – Übrige Betriebsausgaben | Handyvertrag 60 % privat | 40 |
| 9230 – Übrige Betriebsausgaben | Büromaterial, Beratung, Fachliteratur, PayPal-Gebühren, USt-Zahlungen, WKO | 100 |
| E1B 9530 – Sonstige Kosten Vermietung | Vermietung Ausgaben | 100 |
| keine Kennzahl | Unternehmer-Lohn, Private Ausgaben | 0 |

**Fallback:** unbekannte Ausgaben-Art → `9230 – Übrige Betriebsausgaben` (EUE-007),
unbekannte Einnahmen-Art → `9040 – Betriebseinnahmen` (EUE-008).

**Ausfüllhilfe:** „Die Art bestimmt die E1A-Kennzahl und den vorgeschlagenen Betriebsanteil.
Der Vorschlag ist änderbar; die Buchung speichert den tatsächlich verwendeten Anteil.“

### 1.5 Umsatzsteuer-Arten (EUE-010)

`Reverse Charge 20 %`, `Reverse Charge 13 %`, `Reverse Charge 10 %`,
`IG-Erwerb 20 %`, `IG-Erwerb 10 %`, `IG-Erwerb 13 %`, `IG-Erwerb 4,9 %`.

**Ausfüllhilfe:** „Nur für Eingangsrechnungen mit Reverse Charge oder innergemeinschaftlichem
Erwerb. Die Auswahl steuert Vorsteuer und USt-Zeile; private Ausgaben haben keine USt-Art.“

### 1.6 Transportmittel und Fahrzeugtypen (EUE-011)

| Fahrzeug | Typ |
|---|---|
| Privatauto | PKW oder Kombi |
| Motorrad | Motorrad |
| Fahrrad, E-Bike | Fahrrad oder E-Bike |
| Flug, Flug (Inland), Bus, U-Bahn, Zug, Gemischte Öffis, E-Scooter, Taxi, Carsharing | Andere |

Unbekanntes Transportmittel → `Andere`; fehlender Typ wird in den Einstellungen als Warnung
angezeigt. Der Fahrzeugtyp bestimmt den Kilometergeldsatz (EUE-062 … EUE-064).

### 1.7 Feiertage (EUE-012)

13 gesetzliche Feiertage je Wirtschaftsjahr, berechnet inkl. Osterformel (Gauß/Meeus):
1.1., 6.1., Ostersonntag, Christi Himmelfahrt (+39), Pfingstmontag (+50),
Fronleichnam (+60), 15.8., 26.10., 1.11., 8.12., 25.12., 26.12.
Verwendung: Werktage bei mehrtägigen Reisen (EUE-074).

### 1.8 Reise-Pauschalen (EUE-013)

174 Länder/Regionen, je Tagessatz und Nächtigungssatz. Österreich 2024: 26,40/15,00;
ab 2025: 30,00/17,00. Nach #26 in Cent (`tag_cents`, `nacht_cents`).

**Ausfüllhilfe:** „Der Satz wird serverseitig aus dem Land und dem Reisejahr aufgelöst.
Die Pauschale ist ein Vorschlag; die tatsächliche Hotelrechnung kann als Beleg verknüpft
werden und ersetzt dann den Nächtigungssatz.“

### 1.9 Sozialversicherung (EUE-014 … EUE-022, EUE-107 … EUE-115, EUE-131)

**Einstellungen je Mandant und Jahr:**

| Feld | Werte | Regel |
|---|---|---|
| SV-Modus | `Gewerbetreibende (GSVG)`, `Neue Selbständige (GSVG)`, `Freiberufliche (FSVG)`, `Nur Unfallversicherung` | EUE-107 |
| Jungunternehmer | `Ja`/`Nein` | EUE-108 |
| Beitragsgrundlage | automatisch aus E1A-Gewinn + SV-Beiträgen (9225), gedeckelt | EUE-109 |
| weitere Versicherungen | Liste aus Buchungen der Kategorie „Versicherung“ (Erweiterung) | — |

**Jahreswerte (Cent):**

| Konstante | 2024 | 2025 | 2026 |
|---|---:|---:|---:|
| Max. Beitragsgrundlage/Monat | 707.000 | 752.500 | 808.500 |
| KV-Satz | 6,8 % | 6,8 % | 6,8 % |
| Mindest-KV/Monat | 3.545 | 3.747 | 3.747 |
| Mindest-GSVG-PV/Monat | 9.591 | 10.195 | 10.195 |
| Mindest-FSVG-PV/Monat | 10.369 | 11.022 | 11.022 |
| GSVG-PV-Satz | 18,5 % | 18,5 % | 18,5 % |
| FSVG-PV-Satz | 20 % | 20 % | 20 % |
| SVS-Satz | 1,53 % | 1,53 % | 1,53 % |
| UV-Pauschale/Jahr | 1.135 | 1.207 | 1.295 |

**Berechnung (Jahreswerte, Cent, kaufmännisch gerundet):**

```
bmg = min(E1A-Gewinn + SV-Beiträge(9225), max_bgl * 12)   # EUE-109
kv  = 0, wenn Modus = UV oder Jungunternehmer = Ja
      sonst max(bmg * kv_satz, min_kv * 12)               # EUE-110
pv  = 0, wenn Modus = UV
      sonst max(bmg * pv_satz, min_pv * 12)               # EUE-111
svs = 0, wenn Modus = FSVG oder UV
      sonst max(bmg * svs_satz, min_kv / kv_satz * 12 * svs_satz)   # EUE-112
uv  = uv_pauschale * 12                                   # EUE-112
sv_gesamt = kv + pv + svs + uv                            # EUE-114
sv_monat  = sv_gesamt / 12
```

**Mindestbeiträge ohne Einkünfte (EUE-113):**
`UV` im Modus „Nur Unfallversicherung“, sonst `UV + SVS + Mindest-KV + Mindest-PV`.

**EUE-115 – keine Doppelzählung:** Werden SV-Beiträge als Ausgabe mit Kennzahl
`9225 – Pflichtversicherungen` gebucht, dürfen sie nicht zusätzlich den Gewinn mindern,
wenn die Übersicht die SV berechnet. Die Übersicht zeigt beide Wege getrennt:
„SV berechnet“ und „SV laut Buchungen (9225)“, und verwendet genau einen davon
(Einstellung `sv_source = computed | booked`).

**EUE-131 – Konsistenz:** Der Katalog enthält historisch die String-Variante
`Freiberufliche (FSVG)` und an einer Stelle `Freiberufler (FSVG)`. Das Tool verwendet
**einen** Enum-Wert und normalisiert Altdaten; ein Test prüft, dass jede Modusvariante
genau einem Zweig zugeordnet ist.

### 1.10 Weitere Versicherungen (Erweiterung)

Nicht in der Clean-Room-Basis als Rechenregel enthalten. Die Übersicht soll:

| Position | Quelle |
|---|---|
| Sozialversicherung (KV, PV, SVS, UV) | Berechnung nach 1.9 |
| Andere Versicherungen | Buchungen der Kategorie „Versicherung“ (z. B. Haftpflicht, Rechtsschutz, Sachversicherung), Kennzahl `9230` |
| Versicherungen gesamt | SV + andere Versicherungen, ohne Doppelzählung (9225) |

Jede Position zeigt Betrag, Zeitraum (Monat/Jahr) und Herkunft (berechnet/gebucht).

---

## 2. Wo die Einstellungen überall vorkommen

| Einstellung | Buchung | Reise/Eigenbeleg | Beleg | Monats-PDF | Übersicht | E1A/E1B | Integration |
|---|---|---|---|---|---|---|---|
| Firmenstammdaten | — | — | — | Kopf | Kopf | Kopf | — |
| Wirtschaftsjahr | Datum | Reisejahr | — | Kopf | alle Sätze | alle Sätze | `report_period` |
| Zahlarten | Zahlart | — | — | Zahlart | ZA | — | optional |
| Kunden | Auswahl | Auswahl | Auswahl | Kunde | Kundenauswertung | — | `customer_external_id` |
| Projekte | Auswahl | Auswahl | Auswahl | Projekt | Projektauswertung | — | `project_external_id` |
| Einnahmen-/Ausgaben-Arten | Kategorie | — | OCR-Vorschlag | Kategorie | Gruppierung | Kennzahl | `category` |
| Standard-Betriebsanteil | Vorschlag | — | — | Anteil | Anteil | — | `business_share` |
| USt-Arten | USt-Art | — | OCR-Vorschlag | USt | USt-Zeilen | USt | `vat_rate` |
| Fahrzeugtypen | — | Fahrzeug | — | Fahrzeug | — | — | — |
| Feiertage | — | Werktage | — | — | — | — | — |
| Reise-Pauschalen | — | Land/Tag/Nacht | — | Pauschale | — | — | — |
| SV-Modus/Jungunternehmer | — | — | — | — | SV | 9225 | — |
| Weitere Versicherungen | Kategorie | — | — | — | Versicherungsblock | 9230 | Kategorie |

---

## 3. Ausfüllhilfe

Die Ausfüllhilfe ist kontextbezogen und erscheint als Hilfetext am Feld sowie als
„?“-Popover mit Beispiel. Sie ist **kein** Steuerberatungsersatz.

Pflichtinhalte je Feld:

| Feld | Hilfetext (Kurz) | Beispiel |
|---|---|---|
| Betriebsanteil | „Nur der betriebliche Anteil zählt für EÜR und PDF; 0 % = privat.“ | „Laptop 60 % betrieblich“ |
| Kategorie | „Bestimmt die E1A-Kennzahl und den Standardanteil.“ | „Wareneinkauf → 9100“ |
| USt-Art | „Nur bei Reverse Charge / IG-Erwerb auswählen.“ | „Reverse Charge 20 %“ |
| Land/Region | „Bestimmt Taggeld und Nächtigungssatz.“ | „Österreich 2025: 30/17“ |
| Tage/Nächte | „Tage = Reisedauer inkl. An- und Abreise; Nächte = Übernachtungen.“ | „12.–14.05. → 3 Tage, 2 Nächte“ |
| Std./Tag | „Maximal 12 Stunden pro Reisetag.“ | „8“ |
| KM-Satz | „Fahrzeugtyp bestimmt den Satz; der Wert ist prüfbar.“ | „PKW 0,42“ |
| SV-Modus | „Bestimmt, welche Beiträge berechnet werden; ‚Nur Unfallversicherung‘ setzt KV/PV/SVS auf 0.“ | „GSVG“ |
| Jungunternehmer | „KV entfällt; Mindestbeiträge beachten.“ | „Ja“ |
| Kunde/Projekt | „Ordnet Umsatz und Kosten zu; beeinflusst die EÜR nicht.“ | „Kunde A / Website“ |

**E1A-Kennzahlen-Ausfüllhilfe:** Jede Kennzahl erhält einen Ein-Satz-Text und ein Beispiel.
Quelle ist die Regel `statement`/`evidence` der jeweiligen EUE-ID; keine neuen fachlichen
Aussagen.

---

## 4. Übersichtsseite (Soll)

Die Übersicht zeigt je Monat und je Jahr:

1. **Einnahmen** (betrieblich), **Ausgaben** (betrieblich), **Gewinn**.
2. **Sozialversicherung**: KV, PV, SVS, UV, gesamt, monatlich; Modus und Quelle
   (berechnet/gebucht) sichtbar.
3. **Weitere Versicherungen** aus Buchungen, gruppiert nach Art.
4. **Versicherungen gesamt** ohne Doppelzählung.
5. **Kunden/Projekte**: Umsatz, Kosten, Deckungsbeitrag; SV nicht künstlich verteilt
   (optional proportional, als Schätzung markiert).
6. **Offene Belege** und **nicht signierte Monate**.
7. **Ausfüllhilfe** und Kennzahlen-Referenz direkt verlinkt.

Alle Beträge in Cent (nach #26), Anzeige über den gemeinsamen Formatter.

---

## 5. Abgleich mit dem heutigen Stand

| Baustein | Status | Lücke |
|---|---|---|
| Stammdaten | fehlt | keine Einstellungsseite, keine Firma/UID/ÖNACE |
| Zahlarten | fehlt | kein ZA, kein Startsaldo |
| Kunden/Projekte | fehlt | keine Kunden; Projekte nur als Reisefreitext |
| Arten/Kennzahlen | teilweise | Kategorien sind Freitext, keine E1A-Zuordnung |
| Standard-Betriebsanteil | teilweise | 5-%-Schritte ja, Standard je Art nein |
| USt-Arten | fehlt | keine USt-Art an Buchungen |
| Fahrzeugtypen | fehlt | KM-Satz frei eingegeben |
| Feiertage | fehlt | keine Werktagsberechnung |
| Reise-Pauschalen | vorhanden | Werte und 174 Länder stimmen (Parität) |
| SV | fehlt | keine Berechnung, kein Modus, keine UV-only-Variante |
| Weitere Versicherungen | teilweise | nur als Ausgabe, keine Übersicht |
| Ausfüllhilfe | fehlt | keine Feldhilfe, keine Kennzahlen-Referenz |
| Übersicht | teilweise | Monats-KPIs, kein Jahr/SV/Versicherungen/Kunden |

Die vollständige Regel-für-Regel-Abdeckung steht in `verification/parity-report.md`
(aktuell: 7 covered, 15 partial, 99 missing, 10 not applicable).

---

## 6. Build-Reihenfolge

1. **#26 Cent-Umstellung** (Voraussetzung für SV- und Versicherungsbeträge).
2. **Einstellungsseite** mit Stammdaten, Zahlarten, Arten/Kennzahlen, USt-Arten,
   Fahrzeugtypen, SV-Modus; Persistenz tenant-scoped; Audit.
3. **Kunden/Projekte** inkl. Zuordnung in Buchung/Beleg/Reise und Filter.
4. **SV-Engine** nach 1.9 mit Unit-Tests je Modus (GSVG, NS-GSVG, FSVG, UV-only,
   Jungunternehmer) und E2E in der Übersicht.
5. **Versicherungsblock** in der Übersicht (SV + andere, ohne Doppelzählung).
6. **Ausfüllhilfe** für Felder und Kennzahlen, mit Tests auf Vorhandensein und Inhalt.
7. **Parität nachziehen**: `rule-coverage.json` und `parity-report.md` aktualisieren;
   `--check` muss grün bleiben.

Jeder Schritt braucht Smoke- und Playwright-Abdeckung; keine Regel gilt als „covered“,
solange nicht beide Ebenen belegt sind.
