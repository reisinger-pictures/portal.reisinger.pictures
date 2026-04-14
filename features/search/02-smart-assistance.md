---
domain: search
topic: smart-assistance
status: active
---

# Technical Concept: Smart Assistance & Document Generation

## 1. Ortssuche (Ranking)
- **Population First:** Suchergebnisse für Städte werden primär nach Einwohnerzahl (`population`) absteigend sortiert.
- **Deduplizierung:** Pro Stadtname wird nur der relevanteste Eintrag zurückgegeben, um PLZ-Dubletten in der Auswahl zu vermeiden.

## 2. Dokumenten-Logik (Rechnung vs. Angebot)
- **Rechnung:** Fokus auf Rechnungsdatum, Leistungszeitraum und Zahlungsziel. Zusatztexte stehen am Ende.
- **Angebot:** Fokus auf Angebotsdatum und Gültigkeit. Der Angebotstext (WYSIWYG) steht prominent VOR den Leistungsposten.
- **Seitenumbrüche:** Überschriften nutzen `page-break-after: avoid`, um nicht isoliert am Seitenende zu stehen.
