---
domain: ecommerce
topic: crm-and-contracts
status: planned
---

# Technical Concept: CRM, Contracts & Snippets

## 1. Customer Management (Lightweight CRM)
- **Tabelle `customers`:** Speichert Stammdaten für B2B-Kontakte.
- **E-Mail Nullable:** Erlaubt das Speichern von Kunden, die nicht als Nutzer im Portal registriert sind.
- **Searchable:** Indizierung via Meilisearch für blitzschnelles Autocomplete in den Dokumenten-Formularen.

## 2. Text Snippets (Bausteine)
- **Tabelle `text_snippets`:** Beinhaltet `title`, `shortcut` und `content_html`.
- **Verwaltung:** CRUD-Oberfläche für Super-Admins.

## 3. Tiptap Snippet Integration (Soll-Zustand)
- **Toolbar Button:** Der WYSIWYG-Editor erhält einen neuen Button "Textbaustein einfügen".
- **Interaktion:** Beim Klick öffnet sich ein Dropdown oder Modal mit der Liste aller Snippets (Suche inkludiert).
- **Injektion:** Bei Auswahl wird der `content_html` des Snippets direkt an der aktuellen Cursor-Position (`Selection`) eingefügt.

## 4. Custom Documents (Angebote & Verträge)
- Erweiterung des PDF-Generators um die Wahl des Dokumenttyps.
- Dynamische Anpassung des Titels ("ANGEBOT", "RECHNUNG", "VERTRAG") und der Spaltenbeschriftungen im PDF-Template.
