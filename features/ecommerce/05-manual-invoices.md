---
domain: ecommerce
topic: manual-invoices
status: active
---

# Feature: Manual Invoices (Stateless PDF Tool)

Dieses Modul ermöglicht es Super-Admins, individuelle PDF-Dokumente für B2B-Sonderfälle zu generieren, ohne das System-Audit (Bestellungen/Statistiken) zu belasten.

## 1. Stateless PDF Generation
- Dokumente werden "on-the-fly" im RAM des Backends generiert und direkt als Stream-Download an den Browser gesendet.
- Es findet **keine Speicherung** in der Datenbank statt (`orders` oder `invoice_snapshots` bleiben unberührt).

## 2. Flexible Positionen & Sorting
- **Positionstypen:** Unterstützung für Leistungen (Menge * Preis), fixe Rabatte (€) und prozentuale Rabatte (%).
- **Reihenfolge-Interaktivität:** Positionen können über Pfeil-Buttons in der UI verschoben werden.
- **Hierarchische Berechnung:** Prozentuale Rabatte beziehen sich immer auf die zum jeweiligen Zeitpunkt aktuelle Zwischensumme aller darüberliegenden Leistungen.
- **Katalog & Batch-Edit:** Häufig genutzte Leistungen und Rabatte werden zur Autovervollständigung in einem zentralen Katalog verwaltet. Die UI trennt dabei strikt nach Typ. Ein responsiver Batch-Edit-Modus ermöglicht die schnelle, gleichzeitige Anpassung von Preisen und Beschreibungen mehrerer Einträge.
- **Architectural Note:** The calculation logic is intentionally duplicated between Frontend (React) and Backend (Laravel). The frontend provides a real-time UX preview, while the backend strictly recalculates everything for security reasons before PDF generation.

## 3. Compliance & Branding
- **Kleinunternehmer-Regelung:** Automatischer Verzicht auf USt.-Ausweis und "Netto"-Begriffe im Layout.
- **Bedingter Rechnungsempfänger:** Wenn keine Adressdaten eingegeben werden, wird der Block "Rechnungsempfänger" im PDF komplett ausgeblendet (für Kleinbetragsrechnungen).
- **Dynamisches Header-Layout:** Verwendet das Wasserzeichen-SVG als zentriertes Logo und lädt Stammdaten (Name, Adresse, IBAN) aus den Systemeinstellungen.

## 4. WYSIWYG Sonderkonditionen
- Ein integrierter Tiptap-Editor erlaubt das Verfassen formatierter Texte (Fett, Listen, H1-H3) am Ende des Dokuments.

## 5. Smart Documents (Polyglot PDFs)
- **Konzept:** Um aus einem gesendeten Angebot später eine Rechnung zu generieren, ohne einen zustandsbehafteten Entwurf in der Datenbank zu speichern, bettet das System die Formulardaten unsichtbar in das PDF ein.
- **Implementierung:** Beim Generieren eines Angebots wird ein JSON-Payload erstellt (Kunde, Leistungen, Rabatte). Dieser wird Base64-kodiert und mittels `hash_hmac` manipulationssicher mit dem `APP_KEY` signiert. Der String (z.B. `%SMART_DOC:payload.signature%`) wird hinter dem `%%EOF` Marker in das Raw-PDF gestreamt.
- **Wiederherstellung:** Über die UI kann das Angebots-PDF hochgeladen werden. Der neue Endpoint `extractOffer` liest den Token aus, verifiziert die Signatur und befüllt das Rechnungsformular im Frontend exakt mit dem Zustand des Angebots.

## 6. Frontend-Validierung & Sicherheit
- **Echtzeit-Sperre:** Der Export-Button ist deaktiviert, solange Titel fehlen, Mengen auf 0 stehen oder der Gesamtbetrag negativ ist.
- **API-Error-Mapping:** Validierungsfehler des Backends (z.B. fehlende Pflichtfelder bei Rabatten) werden von technischen Keys in nutzerfreundliche Texte transformiert.
- **Payload-Integrität:** E2E-Tests validieren die physische Präsenz des signierten Payloads am Dateiende (EOF) mittels Byte-Stream-Analyse.