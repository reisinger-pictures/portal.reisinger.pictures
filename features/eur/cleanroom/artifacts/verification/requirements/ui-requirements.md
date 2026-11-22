# Anforderungsdokument Fachliche UI (abgeleitet)

**Status:** abgeleitetes Clean-Room-Artefakt, gültig für Wirtschaftsjahr 2026.
**Grundlage:** Fachfelder des Regelkatalogs, die abgeleiteten
Anforderungsdokumente dieses Verzeichnisses sowie die fachlichen Befunde des
UI-Reviews. Die
Befundliste allein ist keine Anforderungsspezifikation; dieses Dokument
fasst die fachliche Wirkung zusammen.
**Keine** Komponentennamen, kein CSS, keine Store-Logik, keine Testdateien,
keine Dateipfade.

## 1. Ansichten (fachlich nötig)

| Ansicht | Fachlicher Inhalt |
|---|---|
| Übersicht | Einnahmen, Ausgaben, Gewinn, Stunden, SV-Block, Hinweise |
| Buchungen | Einnahme-/Ausgabezeilen mit Art, Betrag, Anteil, Kennzahl, Beleg |
| Belege | Upload, Zuordnung, Prüfstatus, Verschlüsselung |
| Monat | Monatsjournal, Zeilenprüfung, Summen, Signaturstatus |
| Reisen | km, Tagesgeld, Nächtigung, Status, Pauschalen |
| Anlagen | Anlagegut, AfA-Verlauf, Halbjahresregel, GWG, Privatanteil, Vergleich |
| USt | Acht Kennzahlen, Zahllast, Quartale, zwei Erklärungsblöcke |
| ESt | Fünf Tarifstufen, Grundfreibetrag, E1A/E1B, Pauschalen, Modi |
| Warehouse/WEB | Wareneingang, Abgleich mit Ausgaben |
| Anträge | fachliche Antragsvorgänge |
| Signatur | extern signierten Beleg hochladen, Status, Testprovider gekennzeichnet |
| Einstellungen | Stammdaten, Kataloge, Referenzdaten, SV-Modus, Ausfüllhilfe |

## 2. Pflichtfelder und Leerzustände

- Pflichtfelder je Regel: Belegnummer, Monat/Tag, Art, Betrag, ggf. Land,
  Fahrzeug, Veranlassung, Anschaffungsdatum, Nutzungsdauer (EUE-036/037,
  EUE-047, EUE-052, EUE-059, EUE-075 … EUE-079).
- Leerzustände sind explizit zu benennen („Noch keine Buchungen", „Noch keine
  Belege"). Ohne Auswahl erscheint ein Platzhalter plus Hinweis, nicht die
  Zahl 0.
- Beträge bleiben eine Einheit: Zahl und Währungszeichen dürfen nicht
  umbrechen. Deutsche Zahlenformate; Datum in österreichischer Schreibweise.
- Kennzahlen werden einmal angezeigt, nicht doppelt.
- Zähler haben korrekten Singular/Plural.

## 3. Lösungsaktionen statt Fehlermeldungen

| Fehlerzustand | Fachliche Lösungsaktion |
|---|---|
| Unbekannte Ausgaben-/Einnahmen-Art | Fallback 9040/9230 anzeigen und Auswahl anbieten |
| Fehlende Belegnummer / Tag / Monat | Feld markieren, Beleg zuordnen, Datum korrigieren |
| Ungültiger Monat / ungültiges Datum | Monatsnamen bzw. gültigen Tag wählen |
| GWG-Hinweis | Sofortige Erfassung im Ausgabenjournal anbieten |
| Fehlende Entnahme bei Privatanteil | Entnahme-Buchung unter derselben Belegnummer anlegen |
| Fehlender WEB-Eintrag bei Wareneinkauf | Wareneingangszeile ergänzen |
| Vorjahreswerte fehlen/veraltet | AfA und Restbuchwert des Vorjahres prüfen |
| Abweichung Vorjahres-AfA > Toleranz | AfA-Plan gegen Kosten/Nutzungsdauer prüfen |
| Keine Auswahl bei Reise/Pauschale | „—" plus Hinweis „Reise auswählen" |
| Mock-Signatur | Eindeutig als Testprovider kennzeichnen; externen Ablauf nutzen |

## 4. Ausfüllhilfe

Zu jedem fachlichen Feld gehört eine kurze deutsche Hilfe: Betriebsanteil in
5-%-Schritten, ÖNACE 11–990, E1A/E1B-Kennzahl mit Text und Beispiel,
SV-Modus, USt-Art, Nutzungsdauer, Halbjahresregel, GWG, Privatanteil,
Zahlart/Startsaldo, Feiertage und Reise-Pauschalen.

## 5. Mobile

- Bedienelemente in einer Zeile, horizontal scrollbar; keine mehrzeiligen
  Leisten.
- Technische Paritätsdetails (Vektoren, Cent-/Minuten-Schreibweise im
  Datenmodell) gehören nicht in die Nutzeransicht; Anzeige erst an der
  Lesegrenze formatieren.

## 6. Prüfvorbehalt

Rechtsprüfung von AfA/Nutzungsdauer/GWG und echte QES bleiben offene,
dokumentierte Gates, keine UI-Befunde. Dieses Dokument ist eine Anforderung,
keine Implementierung.
