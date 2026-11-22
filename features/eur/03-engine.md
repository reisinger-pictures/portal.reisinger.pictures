# EAR-Rechenkern: Verträge je Fachbereich

**Status:** gültig für Wirtschaftsjahr 2026

## Zweck und Grenze

Die fachlichen Rechenwege liegen in `backend/app/Eur/` und sind framework-frei
prüfbar. Die Anforderungen stammen ausschließlich aus den gepinnten
Anforderungsdokumenten unter
[`cleanroom/artifacts/verification/requirements/`](cleanroom/artifacts/verification/requirements/)
sowie dem Regelkatalog. Fachliche Bezüge laufen über `EUE-*`- bzw. `DEG-*`-IDs;
Regeltexte und Zellbezüge stehen nicht im Code.

Für alle Bereiche gilt:

- Geld ist ganzzahlige Cent (`*_cents`), Zeit ganzzahlige Minuten
  (`*_minutes`), Anteile sind ganze Prozent in 5-%-Schritten.
- Keine Float-Geldwerte im Rechenpfad. Faktoren und Raten laufen über BCMath;
  die Umrechnung von Artefakt-Dezimalwerten passiert genau einmal an der
  Lesegrenze ([`ReferenceTables`](../../backend/app/Eur/Reference/ReferenceTables.php)).
- Nur 2026 ist gepinnt. Ein anderes Jahr wird abgewiesen, nie still auf einen
  Default abgebildet.
- Raten werden als Basispunkte (1/10000) geführt, nicht als Prozent-Float.

## Referenzdaten (EUE-004 … EUE-022)

[`ReferenceTables`](../../backend/app/Eur/Reference/ReferenceTables.php) ist die
einzige Lesegrenze für
[`analysis/tables.json`](cleanroom/artifacts/analysis/tables.json): 34
Ausgabenarten, 9 Einnahmenarten, 7 USt-Arten, 13 Fahrzeugtypen, 2 Zahlarten,
3 Projekte, 174 Reiseziele, 13 Feiertage, SV-Werte, ESt-Tarif und
Grundfreibetrag. Fehlt eine Tabelle, bricht das Laden ab.

- [`Holidays`](../../backend/app/Eur/Reference/Holidays.php): Osterformel nach
  Meeus/Jones/Butcher; bewegliche Feiertage werden daraus abgeleitet.
- [`SocialInsurance`](../../backend/app/Eur/Reference/SocialInsurance.php):
  Monats-/Jahreswerte, Mindestbeiträge je Modus, Sätze in Basispunkten.
- [`IncomeTaxTariff`](../../backend/app/Eur/Reference/IncomeTaxTariff.php):
  fünf Stufen, steuerfreie Schwelle, Grundfreibetrag.
- [`TravelAllowances`](../../backend/app/Eur/Reference/TravelAllowances.php):
  Tag-/Nächtigungssatz je Land, Lookup case-insensitiv.

## Einkommensteuer (EUE-089 … EUE-106)

- Stufenweise Progression (EUE-094): jede Stufe besteuert nur den Anteil
  zwischen ihrer unteren und oberen Grenze. Ein Einkommen genau auf einer
  Grenze gehört zur unteren Stufe. Gerundet wird einmal am Ende kaufmännisch.
- Grundfreibetrag (EUE-096 … EUE-098):
  `min(15 % × Gewinn, 15 % × 33.000 €)`, mindestens 0; Abzug vom Gewinn, nicht
  von der Steuer.
- Die Stufen und der Grundfreibetrag kommen aus dem Referenzartefakt, nicht aus
  Code-Konstanten.
- E1A/E1B-Aggregation, Aufteilung und Rechenmodi (EUE-095, EUE-099 … EUE-106)
  sind als Anforderung dokumentiert; die Zuordnung der Kennzahlen stammt aus
  dem Katalog.

## Umsatzsteuer (EUE-082 … EUE-088)

[`VatCalculator`](../../backend/app/Eur/Domain/VatCalculator.php) bildet die
acht Kennzahlen und die Zahllast:

- KZ 016 und KZ 000 tragen den Kleinunternehmer-Umsatz (EUE-083).
- KZ 057 trägt Reverse Charge; er fließt nicht in KZ 000 (EUE-084).
- KZ 072/073/008/125 tragen die IG-Erwerbe zu 20/10/13/4,9 %; KZ 070 ist ihre
  Summe (EUE-085).
- Zahllast = Reverse Charge + Satz × Bemessungsgrundlage je Steuersatz
  (EUE-086). Steuerbeträge werden an der Cent-Grenze gegen null abgerundet.
- Leere Monatswerte bleiben leer; sie werden nicht als 0 ausgegeben. Das gilt
  auch für Quartals- und Jahresgruppen (EUE-087).
- Beide Erklärungsblöcke (EUE-088) nutzen dieselbe Kennzahlenstruktur.

## Reisen (EUE-062 … EUE-081)

[`TravelCalculator`](../../backend/app/Eur/Domain/TravelCalculator.php):

- Kilometergeld, Mitfahrerzuschlag, Höchstzahl Mitfahrer und Kontingente kommen
  aus dem Regelkatalog (EUE-062 … EUE-066). PKW 50 ct/km, Motorrad und Fahrrad
  25 ct/km im Jahr 2026; Mitfahrerzuschlag 15 ct/km, PKW höchstens 6,
  Motorrad höchstens 1.
- Entfernung entweder aus Anfangs-/Endkilometerstand oder als Distanz, nie
  beides (EUE-067).
- Mahlzeitenabzug (EUE-068): Inland halbiert eine Mahlzeit das Tagesgeld und
  streicht bei zwei Mahlzeiten; Ausland streicht eine Mahlzeit und lässt bei
  zwei Mahlzeiten ein Drittel.
- Nächtigung ohne Beleg nutzt die Pauschale, sonst den Ländersatz (EUE-069).
- 5-/15-Tage-Regel (EUE-070): ab Tag 5 entfällt das Tagesgeld, ab Tag 15 die
  Nächtigung.
- Betriebsanteil aus der Reisezeit (EUE-072): unter 10 % → 0 %, ab 90 % → 100 %.
- Hin-/Rückfahrt (EUE-071): Zeitgrenze 3 Stunden, Mindestentfernung 50 km
  (Einzelzeile) bzw. 25 km (verkettet), kein Tagesgeld.
- Validierung (EUE-075 … EUE-079) liefert deutsche Hinweise statt Ausnahmen:
  Jahr, Zeitraum, Belegnummer, Land, Transportmittel und Veranlassung.

## Anlagen und AfA (EUE-049 … EUE-061, DEG-001 … DEG-015)

[`DepreciationCalculator`](../../backend/app/Eur/Domain/DepreciationCalculator.php):

- Lineare AfA mit Halbjahresfaktor 1 bzw. 0,5 (EUE-049); Restbuchwert mit
  Erinnerungswert 0,01 € (EUE-050); GWG-Hinweis unter 1.000 €, genau 1.000 €
  löst keinen Hinweis aus (EUE-051).
- Nutzungsdauer 0 oder 2–99, AfA-Kennzahl und Mindestbetriebsanteil 50 %
  (EUE-052 … EUE-054).
- Degressive AfA (DEG-001 … DEG-015): Satz 1–30 %, konstant, halbe Jahres-AfA
  im Anschaffungsjahr, einmaliger begründeter Wechsel zur linearen Methode ab
  dem zweiten Jahr, Vergleich beider Methoden. Die DEG-Regeln sind eine
  Produkterweiterung außerhalb des 131er-Floors
  ([D-67](../owner-decisions.md#d-67-degressive-afa-is-an-in-scope-product-extension-outside-the-131-rule-floor)).
- Der Betriebsanteil von Anlagegütern liegt in 5-%-Schritten zwischen 50 % und
  100 % (P5.4). Die Jahresabschlussbuchung bucht ausschließlich die bereits im
  AfA-Plan enthaltenen `business_use_cents` und ist über
  `eur_asset_years.eur_entry_id` idempotent (P5.5).

## Vorbehalt

Die externe steuerliche Prüfung von Tarif, Pauschalen, AfA/Nutzungsdauer/GWG
und Privatnutzung bleibt ein dokumentierter Vorbehalt und eine Abnahmebedingung
für „vollständig", kein Blocker für die Implementierung.
