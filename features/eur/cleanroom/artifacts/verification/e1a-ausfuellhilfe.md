# Ausfüllhilfe Formular E1A — abgeleitetes Clean-Room-Artefakt

**Status:** abgeleitet aus der lokalen Clean-Room-Basis; öffentliche/externe
fachliche Information (FinanzOnline), daher zur Weitergabe an das Portal
freigegeben. **Gültig für Wirtschaftsjahr 2026.**

## 1. Herkunft und Nachweis

| Angabe | Wert |
|---|---|
| Quelle | lokale Clean-Room-Basis, Blatt `E1A`, Spalte D, Zeilen 11–40 (Zell-Annotationen) |
| Basiskennung | `/projects/ku-online/local/input.ods` |
| SHA-256 der Basis | `cf5060fb11aab96d692f44a1a0c6da1c583388322a110fbb15c943d817e90790` |
| Öffentlicher Hinweis in `E1A!C4` | „finanzonline.at > Eingaben > Erklärungen > Einkommensteuererklärung > Betriebliche Einkunftsarten > wählen > Einzelunternehmer/in (E1a)" |
| Annotationszahl im Blatt E1A | 26 (25 Kennzahlen-Zeilen + 2 Eingabefelder; 2 Kennzahlen ohne Annotation) |
| Extraktion | ODS `content.xml`, `<office:annotation>` der D-Zellen; Rohformeln/beitraege unter `analysis/E1A.txt` (intern, nicht exportieren) |

Die Hilfetexte sind **Zellkommentare der Clean-Room-Basis** und zugleich
öffentliche FinanzOnline-Information. Der Berechnungsalgorithmus in
Abschnitt 4 ist aus den Formeln desselben Blatts abgeleitet und als
Pseudocode dargestellt. Es wurde keine externe Datei als Wertquelle und kein
Engine-Code übernommen; Zelladressen sind Belege, keine Rechenvorschrift für
das Portal. Die Basis selbst bleibt lokal und gitignored; einziges
Exportformat ist dieses Dokument.

Reproduzierbare Extraktion (Auszug):

```python
import zipfile, xml.etree.ElementTree as ET
O = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0'
with zipfile.ZipFile('local/input.ods') as z:
    root = ET.fromstring(z.read('content.xml'))
tbl = next(t for t in root.iter('{urn:oasis:names:tc:opendocument:xmlns:table:1.0}table')
           if t.get('{urn:oasis:names:tc:opendocument:xmlns:table:1.0}name') == 'E1A')
# pro D-Zelle: annotation/text:p auslesen, Leerzeichen normalisieren
```

## 2. Blattstruktur (fachlich)

- `C4`: Navigationshinweis zu FinanzOnline (siehe oben); `E1A!C2` Titel
  „EINKOMMENSTEUERERKLÄRUNG E1A", `I2` „KENNZAHL 330 (FORMULAR E1)".
- `K4` betrieblicher Jahresgewinn = Einnahmen − Ausgaben; `K5` Grundfreibetrag;
  `K9` steuerpflichtiger Gewinn (KZ 330). `J6`/`J7` Grenze 33.000 € und Satz 15 %.
- Einnahmen `C11:C14` (9040/9050/9060/9090), Ausgaben `C17:C38`
  (9100…9230, inkl. 9225 und 9275). `E38` ist die Restgröße.
- `C39`/`C40` sind Formeln, die je nach Eingabebetrag 9216 bzw. 9215/9217
  liefern; die zugehörigen Hilfetexte stehen in `D39`/`D40`.
- E1B-Kennzahlen werden im Blatt E1B geführt und hier per `SUMIF("E1B*")`
  herausgerechnet (EUE-099/100).

## 3. Ausfüllhilfe je Kennzahl (Wortlaut der Basis)

Die Texte sind der Wortlaut der Zellannotationen. „Keine Annotation" bedeutet:
im Blatt ist nur die Bezeichnung, kein Hilfetext hinterlegt.

| Zelle | KZ | Bezeichnung | Ausfüllhilfe (Annotation) |
|---|---|---|---|
| D11 | 9040 | Betriebseinnahmen | Standardkennzahl und Sammelbecken. Erträge/Betriebseinnahmen (Waren-/Leistungserlöse und Eigenverbrauch) |
| D12 | 9050 | Freie Dienstverträge | Einnahmen aus selbständig erbrachten Leistungen im Rahmen von freien Dienstverträgen, Vorträgen, unterrichtender oder lehrender Tätigkeit, Funktionsgebühren – die gemäß §109a vom Dienstgeber gemeldet werden müssen |
| D13 | 9060 | Erlös abgeg. Anlagegüter | Erlös (Betriebseinnahme) abgegangenes (Verkauf oder Entnahme) Anlagegut aus dem Anlagevermögen |
| D14 | 9090 | Übrige Betriebseinnahmen | Sonstige Einnahmen, die nicht 9040, 9050 oder 9060 zuordenbar sind (z. B. Gutschriften der Sozialversicherung, Versicherungsentschädigungen, Förderungen, Subventionen etc.) auch gelegentliche und in geringem Umfang anfallende Erlöse. |
| D17 | 9100 | Waren | // Waren, Rohstoffe, Hilfsstoffe Ausgaben für den Einkauf von Waren, Rohstoffe, Hilfsstoffe und Fertig- bzw. Einzelteile. Keine Büromaterialen. |
| D18 | 9110 | Fremdpersonal | // Beigestelltes Personal (Fremdpersonal) und Fremdleistungen Ausgaben für Fremdpersonal (z.B. Freelancer). Nicht einzutragen sind jene Ausgaben für Fremdpersonal, die als Herstellungskosten von Anlagegütern zu „aktivieren" (Anlageverzeichnis) sind |
| D19 | 9120 | Eigenpersonal | Personalaufwand ("eigenes Personal"). Löhne, Gehälter und Lohnnebenkosten. |
| D20 | 9130 | Anlagegüter | Abschreibungen auf das Anlagevermögen (Dinge die länger als 1 Jahr dienen: Einrichtung, Computer, Drucker, Fahrzeuge, Maschine) und geringwertige Wirtschaftsgüter. Die Abschreibungen aus dem Anlagenverzeichnis werden unabhängig von der Ausgaben-Art hier zugeordnet |
| D21 | 9134 | Degressive AfA | Keine Annotation im Blatt. |
| D22 | 9135 | Beschleunigte Gebäude-AfA | Keine Annotation im Blatt. |
| D23 | 9150 | Gebäudeinstandhaltung | Instandhaltungen (Erhaltungsaufwand) für Gebäude. Reinigung durch Dritte, Entsorgung und Beleuchtung sind nicht hier einzutragen. |
| D24 | 9160 | Reisen, Fahrten und Diäten | Fahrten Wohnsitz - Arbeitsstätte: Reise- und Fahrtspesen (z.B Zugtickets) inkl. Kilometergeld und Diäten (ohne tatsächliche Kfz-Kosten) Alle Betrieblichen Ausgaben aus dem Fahrtenbuch werden automatisch hier zugeordnet |
| D25 | 9165 | 50% Pauschale Massenbeförderung | Wenn glaubhaft gemacht werden kann, dass die Karte im Wirtschaftsjahr auch für betriebliche Fahrten verwendet wurde, können 50% der Ausgaben für eine nicht übertragbare Wochen-, Monats- oder Jahreskarte für Massenbeförderungsmittel als Betriebsausgabe berücksichtigt werden |
| D26 | 9170 | Tatsächliche KFZ-Kosten | "Tatsächliche Kfz-Kosten wenn nicht bereits Kilometergeld geltend gemacht wird. Instandhaltungsaufwendungen betreffend Kfz, Betriebsstoffe, Reparatur- und Servicekosten, Versicherungsprämien, Steuern" |
| D27 | 9180 | Miete, Pacht und Leasing | "Miet- und Pachtaufwand, Leasing. Nicht hier einzutragen sind Aufwendungen/Ausgaben für Beheizung, Beleuchtung, für die Reinigung gemieteter Räume sowie Betriebskosten" |
| D28 | 9190 | Provisionen und Lizenzen | Provisionen an Dritte, Lizenzgebühren |
| D29 | 9200 | Werbung und Repräsentation | Werbe- und Repräsentationsaufwendungen, Bewirtungsspesen, nicht in den Kennzahlen 9243 bis 9246 zu erfassenden Spenden, Trinkgelder |
| D30 | 9210 | Buchwert abgeg. Anlagegüter | Buchwerte von im Wirtschaftsjahr abgegangenen (Verkauf oder Entnahme) Anlagen. Zerstörte AG sind 9130 |
| D31 | 9220 | Zinsen | Zinsen und ähnliche Aufwendungen |
| D32 | 9275 | Arbeitszimmer | Abzugsfähige Ausgaben liegen nur dann vor, wenn das Arbeitszimmer (nahezu) ausschließlich betrieblich genutzt wird und den Mittelpunkt der gesamten betrieblichen Tätigkeit bildet. Das ist z. B. bei Schriftstellern, Malern, Komponisten oder Gutachtern der Fall. Als Betriebsausgaben durch ein Arbeitszimmer kommen insbesondere anteilige Mietkosten, Betriebskosten (Beheizung, Beleuchtung, Versicherung etc.), AfA, Finanzierungskosten in Betracht. Bitte tragen Sie die Aufwendungen für das Arbeitszimmer nur in Kennzahl 9275 ein, ohne Erfassung in anderen Kennzahlen. Werden Aufwendungen für ein Arbeitszimmer berücksichtigt, stehen keine Werbungskosten für ergonomisch geeignetes Mobiliar (Kennzahl 158 im Formular E 1) zu und es können in der Veranlagung auch keine Werbungskosten für ein Homeoffice-Pauschale berücksichtigt werden. |
| D33 | 9225 | Pflichtversicherungen | SVA-Beträge, Eigene Pflichtversicherungsbeiträge, Beiträge zu Versorgungs- und Unterstützungseinrichtungen und Beiträge zur Selbständigenvorsorge |
| D34 | 9243 | Spenden Lehreinrichtungen | Betriebliche Spenden an begünstigte Forschungs- und Lehreinrichtungen, Museen, das Bundesdenkmalamt, Behindertensport-Dachverbände, die Internationale Anti-Korruptions-Akademie |
| D35 | 9244 | Spenden Hilfsorganisationen | Betriebliche Spenden an mildtätige Organisationen, begünstigte Sendensammelvereine |
| D36 | 9245 | Spenden Umweltschutz | Betriebliche Spenden an Umweltschutzorganisationen und Tierheime |
| D37 | 9246 | Spenden Feuerwehr | Betriebliche Spenden an freiwillige Feuerwehren und Landesfeuerwehrverbände |
| D38 | 9230 | Übrige Betriebsausgaben | Standardkennzahl. Alle anderen nicht bereits erfassen Ausgaben. z.B Fachliteratur, Büromaterial,Telefon, Porto |
| D39 | 9216 (via `C39`) | Ergonomisches Mobiliar | Ausgaben für ergonomisch geeignetes Mobiliar eines in der Wohnung eingerichteten Arbeitsplatzes können bis höchstens 300 Euro als Betriebsausgaben berücksichtigt werden. Es darf keine Eintragung in Kennzahl 9275, 9217 oder 159 (Formular E 1) erfolgen. Hier sind nur Ausgaben/Aufwendungen des Veranlagungsjahres und ein allfälliger Überhang aus einem Vorjahr anzugeben. |
| D40 | 9215 / 9217 (via `C40`) | Arbeitsplatzpauschale | Das Arbeitsplatzpauschale steht zu, wenn zur Ausübung der betrieblichen Tätigkeit kein anderer Raum zur Verfügung steht (E2 2024 Zahl 42, EStR 2000 RZ 1299). Es müssen Ihnen tatsächliche Kosten entstehen, es muss aber nicht der Hauptwohnsitz sein (EStR 2000 RZ 1298). Die Pauschale deckt wohnraumbezogene Aufwendungen wie Strom, Heizung, Beleuchtung oder die AfA ab. Arbeitsmittel wie Computer und Drucker dürfen jedoch getrennt als Betriebsausgaben geltend gemacht werden (EStR 2000 RZ 1298). Die Berücksichtigung eines Arbeitszimmers (Kennzahl 9275) schließt die Arbeitsplatzpauschale aus (siehe EStR 2000 RZ 1300). ▶ GROSSE ODER KLEINE? Große wenn, sonst die Kleine: a.) Werden keine anderen Einkünfte aus einer aktiven Erwerbstätigkeit (Vermietung und Vermöglich zählen hier nicht) erzielt, für die außerhalb der Wohnung ein anderer Raum zur Verfügung steht, oder b.) betragen diese höchstens 12.816 Euro (ändert sich jedes Jahr) (E2 2024 Zahl 44, EStR 2000 RZ 1301) ▶ PRO MONAT Kleine Pauschale: 25€ pro Monat = 300€ für das ganze Jahr Große Pauschale: 100€ pro Monat = 1200€ für das ganze Jahr Für jeden (vollen oder angefangenen) Monat, in dem die Tätigkeit ausgeübt wird, steht als Pauschale der jeweilige Zwölftelbetrag zu (EStR 2000 RZ 1300). ▶ EXTRA DER KLEINEN PAUSCHALE Neben dieser Pauschale sind Ausgaben für ergonomisch geeignetes Mobiliar (insbesondere Schreibtisch, Drehstuhl, Beleuchtung) in der Kennzahl 9216 nach Maßgabe des § 16 Abs. 1 Z 7a lit. a zweiter und dritter Satz EStG 1988 abzugsfähig. Dementsprechend ist für diese Betriebsausgaben die Deckelung von 300 Euro maßgeblich; die Berücksichtigung einer AfA kommt nicht in Betracht. (EStR 2000 RZ 1301) |

## 4. Berechnungsalgorithmus

Abgeleitet aus den Formeln des Blatts `E1A`. Darstellung als Pseudocode in
Fachsprache; die Zelladressen in Klammern sind **Belege in der Basis**, keine
Excel-Formeln und kein Portal-Code. Alle Geldbeträge sind im Rechenpfad
ganzzahlige Cent; die EUR-Werte der Basis werden an der Lesegrenze genau
einmal kaufmännisch auf Cent gerundet. Ein leerer Wert bleibt leer (UI:
Platzhalter), er wird nicht als 0 angezeigt.

### 4.1 Jahreswerte und Grundfreibetrag

```
grenze   = 33000 €  wenn Jahr >= 2024, sonst 30000 €        # Beleg J6
satz     = 15 %     wenn Jahr >= 2022, sonst 13 %           # Beleg J7
gewinn   = summe(betriebseinnahmen) - summe(betriebsausgaben)   # K4
gfb      = maximum(0, minimum(gewinn * satz, grenze * satz))    # K5
steuerpflichtig = gewinn - gfb                              # K9
```

Für 2026 gilt durchgehend `grenze = 33.000 €`, `satz = 15 %`. Leere oder
nicht-numerische Zwischenwerte zählen als 0; ein negativer Gewinn bleibt
negativ (K9 verwendet keine Nullgrenze). Der Grundfreibetrag wird vom
Gewinn abgezogen, nicht von der Steuer (EUE-096 … EUE-098).

### 4.2 Einnahmen je Kennzahl (E11–E14)

```
wenn summe(e_betriebseinnahmen) = 0: alle Einnahmen-Zeilen leer
sonst:
  9050 = summe(e_betriebseinnahmen mit Kennzahl beginnend "9050")
  9060 = summe(e_betriebseinnahmen mit Kennzahl beginnend "9060")
  9090 = summe(e_betriebseinnahmen mit Kennzahl beginnend "9090")
  9040 = summe(e_betriebseinnahmen) - 9050 - 9060 - 9090
         - summe(e_betriebseinnahmen mit Kennzahl beginnend "E1B")
```

`9040` ist damit die Sammelkennzahl; `E1B`-Einnahmen werden herausgerechnet
und im Blatt E1B ausgewiesen (EUE-099/100). Belege: `E11`, `E12:E14`.

### 4.3 Ausgaben je Kennzahl (E17–E38)

Jede Zeile ist die Summe der betrieblichen Ausgaben der angegebenen Quelle,
deren E1A-Kennzahl mit der Kennzahl beginnt; bei Summe 0 bleibt die Zeile
leer. `A` = Ausgabenjournal, `AV` = Anlagenverzeichnis, `WEB` = Wareneingang,
`FR` = Reiseblatt.

| KZ | Quelle | Beleg |
|---|---|---|
| 9100 Waren | A + WEB | `E17` |
| 9110 Fremdpersonal | A | `E18` |
| 9120 Eigenpersonal | A | `E19` |
| 9130 Anlagegüter | A + AV | `E20` |
| 9134 Degressive AfA | AV | `E21` |
| 9135 Beschleunigte Gebäude-AfA | AV | `E22` |
| 9150 Gebäudeinstandhaltung | A | `E23` |
| 9160 Reisen, Fahrten und Diäten | A + FR | `E24` |
| 9165 50 % Pauschale Massenbeförderung | A | `E25` |
| 9170 Tatsächliche KFZ-Kosten | A | `E26` |
| 9180 Miete, Pacht und Leasing | A | `E27` |
| 9190 Provisionen und Lizenzen | A | `E28` |
| 9200 Werbung und Repräsentation | A | `E29` |
| 9210 Buchwert abgeg. Anlagegüter | A | `E30` |
| 9220 Zinsen | A | `E31` |
| 9275 Arbeitszimmer | A | `E32` |
| 9225 Pflichtversicherungen | A | `E33` |
| 9243 … 9246 Spenden | A | `E34:E37` |

```
wenn summe(A, AV, WEB, FR) = 0: 9230 leer
sonst:
  9230 = summe(A, AV, WEB, FR) - summe(E17:E37)
         - summe(Ausgaben mit Kennzahl beginnend "E1B")
         - summe(AV-Ausgaben mit Kennzahl beginnend "E1B")     # E38
```

`9230` ist die Restgröße: sie darf nicht zusätzlich zu den Einzelkennzahlen
gezählt werden. Unbekannte Ausgaben-Arten fallen auf 9230, unbekannte
Einnahmen-Arten auf 9040 (EUE-007/008).

### 4.4 Pauschalen-Klassifikation (E39/E40)

```
# Ergonomisches Mobiliar, KZ 9216                        # C39
wenn 9275 gesetzt und <> 0: keine Kennzahl
sonst wenn E39 leer:        keine Kennzahl
sonst:                      Kennzahl 9216

# Arbeitsplatzpauschale                                  # C40
wenn 9275 gesetzt und <> 0: keine Kennzahl
sonst wenn E40 leer:        keine Kennzahl
sonst wenn 300 € < E40 <= 1200 €: Kennzahl 9217 (groß)
sonst wenn E40 <= 300 €:          Kennzahl 9215 (klein)
sonst:                      keine Kennzahl (Warnhinweis)
```

Eingabebereiche: `E39` 0–300 €, `E40` 25–1200 €. `9275` unterdrückt beide
Pauschalen; `9217` unterdrückt 9216 nicht, erzeugt aber einen Warnhinweis
(EUE-030/101/102).

### 4.5 Reihenfolge und Abgleich

1. Kennzahlen je Buchung zuordnen (Fallback 9040/9230); `E1B*` ausschließen.
2. Einnahmen `E11:E14` und Ausgaben `E17:E38` bilden.
3. Gesamtgewinn `K4`, Grundfreibetrag `K5`, steuerpflichtigen Gewinn `K9`.
4. Vollständigkeitsabgleich: Summe der Journal-Beträge muss der Summe der
   E1A-Zeilen zuzüglich E1B entsprechen; Abweichung ist ein Fehler (EUE-129).
5. Keine Doppelzählung von 9225, wenn die SV berechnet wird (EUE-115).

### 4.6 Belegzellen im Blatt E1A

`J6`, `J7`, `K4`, `K5`, `K9`, `E11`, `E12:E14`, `E17:E37`, `E38`, `C39`,
`C40` — zusammen mit den Kennzahl-Spalten `C11:C14` und `C17:C38`.

## 5. Fachliche Zuordnung und Grenzen

- **Keine neue Regelquelle.** Die Texte sind Ausfüllhilfe (UI), keine
  fachlichen Regeln. Der Regelkatalog `domain-rules.json` bleibt einzige
  Regelquelle (131 Regeln, Floor unverändert); die Paritäts-Fixture bleibt
  einzige Paritätsreferenz.
- **Jahreswerte.** Der Hilfetext in `D40` nennt 12.816 € aus E2 2024 und weist
  selbst auf „ändert sich jedes Jahr" hin. Maßgeblich für 2026 sind die Werte
  aus `domain-rules.json` / `est-requirements.md` (Tarifgrenze 13.539 €,
  Grundfreibetrag 33.000 €, Pauschalen 300 €/1.200 €). Bei Abweichung gilt
  der Regelkatalog; der Hilfetext ist wörtlich aus der Basis übernommen und
  wird nicht redaktionell „korrigiert".
- **Pflichtversicherungen (9225).** Der Hilfetext deckt SVA, eigene
  Pflichtversicherungsbeiträge, Versorgungs-/Unterstützungseinrichtungen und
  Selbstständigenvorsorge ab. EUE-023 führt 9225 als Sonderfall, EUE-115
  regelt die Beitragsgrundlage und verhindert die Doppelzählung bei
  berechneter SV. Die Ausfüllhilfe ersetzt diese Regeln nicht.
- **Keine externen Werte.** Die öffentlich verfügbare FinanzOnline-Hilfe
  wurde nicht als Ersatz für Basiswerte herangezogen; sie diente ausschließlich
  der Einordnung als öffentliche Information.
- **Prüfvorbehalt.** Die steuerliche Richtigkeit der Hilfetexte und der
  genannten EStR-Stellen bleibt extern zu prüfen; dieses Dokument ist eine
  Anforderung, keine Steuerberatung.
