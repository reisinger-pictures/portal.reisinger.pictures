# Anforderungsdokument Datenmodell (abgeleitet)

**Status:** abgeleitetes Clean-Room-Artefakt, gültig für Wirtschaftsjahr 2026.
**Grundlage:** Fachfelder des Regelkatalogs sowie die abgeleiteten
Anforderungsdokumente dieses Verzeichnisses („Einstellungen, Kataloge,
Ausfüllhilfe" und „Anlagen/AfA").
**Kein** Schema-Dump, kein Datenbank-Code, keine Trigger, keine
Implementierungsvorgabe. Datenbankspezifika sind ausdrücklich als
*Anforderung* zu verstehen, nicht als Zielschema.

## 1. Einheiten und Querschnitt

- Geldbeträge: ganzzahlige Cent (`*_cents`). Keine Float-Spalten.
- Zeitmengen: ganze Minuten (`*_minutes`); Zeitpunkte unverändert.
- Betriebs-/Privatanteile: 0–100 in 5-%-Schritten; Katalog nennt 0.0–1.0.
- Mandantentrennung: jede Entität trägt eine Mandanten-Zuordnung; fremde IDs
  sind nicht zugreifbar.
- Aufbewahrung und Sperren sind betriebliche Entscheidungen und nicht aus der
  Clean-Room-Basis ableitbar — hier keine Festlegung.

## 2. Entitäten und Pflichtfelder

| Entität | Wesentliche Felder (Typ/Einheit) | Pflicht/Bezug |
|---|---|---|
| Monat | Wirtschaftsjahr, Monat, Status | Jahr 2026; ein Jahr idempotent anlegbar |
| Buchung (E/A) | Belegnummer, Datum, Art, Betrag (`_cents`), USt-Art, Kennzahl, Betriebsanteil, Kunde/Projekt, Zahlart | Belegnummer und Datum laut EUE-036/037/046/047; Fallback 9040/9230 |
| Beleg | Referenz auf Buchung, Datei, Prüfsumme, Verschlüsselung | nie öffentlich ausgeliefert |
| Reise | Zeitraum, Land, Fahrzeug, Belegnummer, Veranlassung, Stunden | EUE-075 … EUE-079; km/Pauschalen in Cent |
| Anlagegut | Belegnummer, Anschaffungsdatum, Kosten (`_cents`), Nutzungsjahre, Betriebsanteil, Kennzahl, Status | EUE-052 … EUE-061; Restbuchwert mind. 0,01 € |
| AfA- und Privatnutzungsplan je Jahr | Jahr, AfA (`_cents`), Restbuchwert (`_cents`), Privatanteil | Fortschreibung je Jahr; Vorjahreskonsistenz EUE-057 |
| Stundenbuch | Datum, Minuten, Belegbezug | EUE-080 (3-Stunden-Regel) |
| Kunde/Projekt | Name, externer Schlüssel, aktiv/archiviert | Löschen nur Archivieren; Referenz bleibt erhalten |
| Einstellungen | Stammdaten, Zahlarten, Kennzahlen, USt-Arten, Fahrzeuge, Feiertage, Referenzwerte | eine Quelle; keine zweite Firmendatenquelle |
| Signatur | Dokumentenbezug, extern signierter Beleg, Status | Testprovider eindeutig gekennzeichnet (UI-Review) |
| Audit | Aktion, Akteur, Objekt | keine Dateiinhalte/Secrets |

## 3. Beziehungen

- Buchung → genau ein Kunde und optional ein Projekt; Reise analog.
- Anlagegut → Ursprungsbuchung, Beleg, Projekt; AfA-Plan je Jahr → Anlagegut.
- Beleg → Buchung/Reise/Anlagegut; Signatur → Dokument.
- Privatanteil > 50 % und < 100 % verlangt eine „Entnahme"-Buchung unter
  derselben Belegnummer (EUE-055).

## 4. Prüfvorbehalt

Externe steuerliche Prüfung von AfA, Nutzungsdauer, GWG und Privatnutzung
bleibt dokumentierter Vorbehalt, kein Blocker. Dieses Dokument beschreibt
Anforderungen, keine Implementierung.
