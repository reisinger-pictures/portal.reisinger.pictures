# Reisinger Foto Portal – Feature Übersicht

Das Reisinger Foto Portal ist eine moderne SaaS-Lösung für Fotografen und Bildagenturen. Es vereinfacht den gesamten Prozess von der Bildauswahl über die sichere Auslieferung bis hin zum Verkauf von Lizenzen an B2B- und B2C-Kunden.

---

## 📸 1. Für Fotografen & Administratoren (Verwaltung)

Das Portal bietet ein leistungsstarkes Dashboard zur Verwaltung von Medien, Kunden und Rechnungen.

* **Flexible Galerien & Ordner-Struktur:** Bilder können in verschachtelten Meta-Galerien (Ordnern) organisiert werden. Es gibt zwei strikt getrennte Galerie-Typen:
  * **Auswahl-Galerien (Selection):** Streng privat. Dienen rein der Bewertung und Kommentierung durch den Kunden.
  * **Delivery-Galerien:** Öffentlich oder privat. Für die finale Auslieferung in voller Auflösung.

* **Nahtloser Upload & Lightroom Integration:**
  * **Lightroom Plugin:** Lade Bilder, Sammlungen und Metadaten direkt aus Adobe Lightroom Classic in das Portal hoch – inklusive Synchronisation von Kundenbewertungen zurück in deinen Lightroom-Katalog.
  * **FTP-Inbox:** Große Datenmengen können per FTP hochgeladen und über das Web-Dashboard mit einem Klick in die Zielgalerie importiert werden.
  * **Smart Assistance:** Intelligente Auto-Vervollständigung (GeoNames) für Orts-Metadaten (IPTC) direkt beim Upload.

* **E-Commerce & Lizenzen:**
  * **Dynamische Preisfindung:** Definiere Basispreise und Multiplikatoren für Nutzungsart (Redaktionell/Kommerziell), Auflösung (Web/Print/Original) und Dauer.
  * **Flatrates & Upgrades:** Weise Kunden "Flatrates" zu (z.B. Print inkludiert). Möchte der Kunde eine höhere Auflösung (z.B. Original), zahlt er automatisch nur den Aufpreis (Delta-Pricing).
  * **Individuelle Angebote:** Kunden können spezielle Rechte anfragen. Der Fotograf kalkuliert den Preis und sendet einen magischen Checkout-Link zurück.

* **B2B CRM & Abrechnung:**
  * **Mandanten-Fähigkeit:** Erstelle "Tenants" für Firmenkunden. E-Mails von bestimmten Domains (z.B. `@firma.de`) werden automatisch diesem Mandanten zugeordnet.
  * **Sammelrechnungen:** Generiere am Monats- oder Quartalsende automatisch eine gebündelte PDF-Sammelrechnung für alle Lieferscheine eines Firmenkunden.
  * **Manuelle PDF-Dokumente:** Erstelle formfreie Angebote und Rechnungen direkt im System mithilfe von gespeicherten Textbausteinen.

* **Sicherheit & Urheberrecht:**
  * **Wasserzeichen:** Das System legt on-the-fly ein Kachel-Wasserzeichen über die Bilder, um Leaks zu verhindern.
  * **IPTC-Injection:** Beim Download wird der Name des Nutzers unsichtbar in die Metadaten der Bilddatei gestempelt, um unautorisierte Weitergabe nachverfolgen zu können.
  * **Audit-Logs:** Detaillierte Statistiken und manipulationssichere Logs zeigen, wer wann welches Bild heruntergeladen hat.

---

## 👥 2. Für Kunden & Gäste (Nutzung)

Die Kundenansicht ist auf eine extrem reibungslose und schnelle User Experience (UX) optimiert.

* **Passwortloser Zugang (Magic Links):**
  Keine nervigen Registrierungen: Kunden erhalten einen Link, klicken darauf und sind sofort sicher authentifiziert. Bei Bedarf lassen sich Links auch mit einem zusätzlichen Passwort schützen.

* **Einfache Bildauswahl:**
  * **PhotoSwipe Lightbox:** Schnelle Vollbildansicht für Desktop und Mobile.
  * **Bewertungen & Kommentare:** Kunden können Bilder mit 1 bis 5 Sternen bewerten und Regieanweisungen als Kommentar hinterlassen.
  * **Filter:** Nur unbewertete Bilder oder Favoriten anzeigen lassen.

* **Downloads & Checkout:**
  * **Sofort-Downloads:** Freigegebene Bilder können einzeln oder bequem als komplettes ZIP-Archiv heruntergeladen werden.
  * **Integrierter Warenkorb:** Fehlt eine Lizenz, kann das Bild direkt im Warenkorb konfiguriert und per Kreditkarte (Stripe) oder auf Rechnung gekauft werden.
  * **Metadaten Bearbeiten:** Falls freigeschaltet, können PR-Agenturen oder Kunden die Titel und Bildbeschreibungen (IPTC) direkt im Browser anpassen, bevor sie die Bilder herunterladen.
