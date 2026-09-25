---
domain: ecommerce
topic: manual-invoices
status: active
---

# Feature: Manual Invoices (Stateless PDF Tool)

This module enables Super-Admins to generate individual PDF documents for B2B special cases without affecting the system audit (orders/statistics).

## 1. Stateless PDF Generation
- Dokumente werden "on-the-fly" im RAM des Backends generiert und direkt als Stream-Download an den Browser gesendet.
- Es findet **keine Speicherung** in der Datenbank statt (`orders` oder `invoice_snapshots` bleiben unberührt).

## 2. Flexible Positionen & Sorting
- **Positionstypen:** Unterstützung für Leistungen (Menge * Preis), fixe Rabatte (€) und prozentuale Rabatte (%).
- **Kanonischer Wire-Vertrag:** Neue API-Positionen verwenden ganzzahlige Cents für Leistungen und fixe Rabatte (`10,00 € = 1000`) sowie Basispunkte für Prozentrabatte (`10 % = 1000`). Fraktionale Mengen bleiben erhalten, werden aber als Hundertstel übertragen: `quantity_scale: 100`, `0,25 → qty: 25`. Preis und Mengeneinheit dürfen höchstens JavaScripts `Number.MAX_SAFE_INTEGER` (`9007199254740991`) sein. Die gemeinsame Rechen-Engine arbeitet mit geprüften PHP-Integer- und TypeScript-`BigInt`-Intermediaten; eine Gleitkomma-Multiplikation ist kein gültiger Rechenweg. Editor-Major-Units akzeptieren höchstens zwei Nachkommastellen und konvertieren erst nach expliziter Präzisionsprüfung mit begrenztem `Math.round`; dadurch bleibt der Max-Safe-Roundtrip der Wire-Cents erhalten.
- **Legacy-Normalisierung:** Direkte API-Aufrufe ohne `quantity_scale` bleiben kompatibel: Positive numerische Dezimalmengen mit höchstens zwei exakten Nachkommastellen (`0,25`) werden vor der Berechnung in Hundertstel normalisiert. Contract-Snapshots verwenden dagegen weiterhin ausschließlich positive ganzzahlige Stückzahlen. Nicht darstellbare, zu große oder mehr als zwei Dezimalstellen enthaltende Mengen werden abgewiesen. `quantity_scale` wird auf jeder manuellen Wire-Position akzeptiert; bei Rabattpositionen werden `qty` und `quantity_scale` für die Preisberechnung ignoriert.
- **UI-Modus:** `InvoiceItemsTable` verwendet im Manual-Invoice-Modus ausdrücklich `step=0.25` und `min=0.25`; der Contract-Modus verwendet `step=1` und `min=1`. Dadurch bleiben Manual-Invoice-Mengen fraktional, ohne den ganzzahligen Contract-Snapshot-Vertrag aufzuweichen.
- **Reihenfolge-Interaktivität:** Positionen können über Pfeil-Buttons in der UI verschoben werden.
- **Hierarchische Berechnung:** Prozentuale Rabatte beziehen sich immer auf die zum jeweiligen Zeitpunkt aktuelle Zwischensumme aller darüberliegenden Leistungen. Backend und Frontend berechnen Positionssummen und Rabatte als geprüfte Ganzzahlen mit `round-half-up`; das PDF leitet `calculated_percentage` in Anzeigeprozenten (`1000 → 10 %`) ab.
- **Katalog & Batch-Edit:** Häufig genutzte Leistungen und Rabatte werden zur Autovervollständigung in einem zentralen Katalog verwaltet. Die UI trennt dabei strikt nach Typ. Ein responsiver Batch-Edit-Modus ermöglicht die schnelle, gleichzeitige Anpassung von Preisen und Beschreibungen mehrerer Einträge.
- **Architectural Note:** Frontend und Backend implementieren die Parität unabhängig voneinander und sichern sie durch fokussierte PHPUnit-/Vitest-Regressionen ab. Das Frontend liefert die Echtzeitvorschau; das Backend berechnet vor PDF und Stream autoritativ neu.

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