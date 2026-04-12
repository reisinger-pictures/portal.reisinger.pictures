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
- **Architectural Note:** The calculation logic is intentionally duplicated between Frontend (React) and Backend (Laravel). The frontend provides a real-time UX preview, while the backend strictly recalculates everything for security reasons before PDF generation.

## 3. Compliance & Branding
- **Kleinunternehmer-Regelung:** Automatischer Verzicht auf USt.-Ausweis und "Netto"-Begriffe im Layout.
- **Bedingter Rechnungsempfänger:** Wenn keine Adressdaten eingegeben werden, wird der Block "Rechnungsempfänger" im PDF komplett ausgeblendet (für Kleinbetragsrechnungen).
- **Dynamisches Header-Layout:** Verwendet das Wasserzeichen-SVG als zentriertes Logo und lädt Stammdaten (Name, Adresse, IBAN) aus den Systemeinstellungen.

## 4. WYSIWYG Sonderkonditionen
- Ein integrierter Tiptap-Editor erlaubt das Verfassen formatierter Texte (Fett, Listen, H1-H3) am Ende des Dokuments.
