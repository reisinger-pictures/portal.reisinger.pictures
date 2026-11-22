# Anforderungsdokument Umsatzsteuer (EUE-082 … EUE-088)

**Status:** abgeleitetes Clean-Room-Artefakt, gültig für Wirtschaftsjahr 2026.
**Grundlage:** ausschließlich die Fachfelder des autoritativen Regelkatalogs.
Keine Zellbezüge, keine Engine-Klassen, keine Methodennamen.
**Einheiten:** alle Beträge ganze Cent; Sätze exakte Bruchteile
(20 % = 1/5, 10 % = 1/10, 13 % = 13/100, 4,9 % = 49/1000).

## 1. Kennzahlen (EUE-082)

| Kennzahl | Bedeutung |
|---|---|
| KZ 000 | Gesamtbetrag |
| KZ 016 | Kleinunternehmer-Umsatz |
| KZ 057 | Reverse Charge |
| KZ 070 | Innergemeinschaftliche Erwerbe gesamt |
| KZ 072 | IG-Erwerb 20 % |
| KZ 073 | IG-Erwerb 10 % |
| KZ 008 | IG-Erwerb 13 % |
| KZ 125 | IG-Erwerb 4,9 % |

Rechtsgrundlage: § 18 Abs. 1 UStG.

## 2. Monatliche Ermittlung

**Kleinunternehmer (EUE-083):** die Betriebseinnahmen des Monats werden als
Kleinunternehmer-Umsatz ausgewiesen und erscheinen sowohl in KZ 016 als auch
in KZ 000. Rechtsgrundlage § 6 Abs. 1 Z 27 UStG.

**Reverse Charge (EUE-084):** die Reverse-Charge-Steuer aus dem
Ausgabenjournal wird auf zwei Nachkommastellen **abgerundet** nach KZ 057
übernommen. Sie fließt nicht in KZ 000. Rechtsgrundlage § 19 UStG.

**Innergemeinschaftliche Erwerbe (EUE-085):** die Brutto-Bemessungsgrundlagen
werden je Steuersatz auf KZ 072 (20 %), KZ 073 (10 %), KZ 008 (13 %) und
KZ 125 (4,9 %) verteilt und auf zwei Nachkommastellen abgerundet. KZ 070 ist
die Summe dieser vier Bemessungsgrundlagen. Rechtsgrundlage § 1 Abs. 1 Z 1 UStG.

**Zahllast (EUE-086):**

```
zahllast = reverse_charge
         + basis_20 × 20/100
         + basis_10 × 10/100
         + basis_13 × 13/100
         + basis_49 × 49/1000
```

Alle Zwischenschritte in Cent; Rundung an der Cent-Grenze gegen null
(Abrunden auf zwei Nachkommastellen). Sind Reverse Charge und alle vier
Bemessungsgrundlagen leer, bleibt die Zahllast leer. Rechtsgrundlage § 18 UStG.

## 3. Quartale und Erklärungen

- **Quartalsaggregation (EUE-087):** Monatswerte werden zu drei Quartalsgruppen
  und einer Jahresgruppe summiert. Eine Gruppe bleibt leer, wenn alle ihre
  Monatswerte leer sind. Rechtsgrundlage § 18 Abs. 1 UStG.
- **Zwei Erklärungsblöcke (EUE-088):** „1. Umsatzsteuervoranmeldung" und
  „2. Umsatzsteuererklärung" mit identischer Kennzahlenstruktur.

## 4. Regel → Verhalten

| Regel | Verhalten |
|---|---|
| EUE-082 | Feste Kennzahlenzuordnung der Voranmeldungszeilen |
| EUE-083 | Betriebseinnahmen → KZ 016 und KZ 000 |
| EUE-084 | Reverse Charge → KZ 057, abgerundet, nicht in KZ 000 |
| EUE-085 | IG-Erwerbe nach vier Sätzen auf KZ 072/073/008/125, Summe → KZ 070 |
| EUE-086 | Zahllast = Reverse Charge + Satz × Bemessungsgrundlage je Steuersatz |
| EUE-087 | Quartals- und Jahressummen, leere Gruppen bleiben leer |
| EUE-088 | Zwei Erklärungsblöcke gleicher Struktur |

## 5. Prüfvorbehalt

Externe steuerliche Prüfung der Kennzahlenzuordnung, der Rundung und der
Satzverwendung bleibt dokumentierter Vorbehalt, kein Blocker. Dieses Dokument
ist eine Anforderung, keine steuerliche Beratung.
