---
domain: ecommerce
topic: licensing-and-downloads
status: active
---

# Feature: Licensing, ZIP-Downloads & Pricing UI

## 1. Übersicht
Dieses Feature definiert die Geschäftslogik für den Bild-Download (Einzelbilder vs. ZIP-Archive) sowie das Redesign des Lizenz-Auswahlprozesses. Das Ziel ist eine transparente Preisgestaltung, eine flüssigere UX ohne Modals und klare Restriktionen basierend auf dem Kundenstatus.

## 2. ZIP-Download Logik & Berechtigungen
* **Berechtigung:** ZIP-Downloads (Alle Bilder herunterladen) sind systemweit **ausschließlich** für zwei Szenarien freigeschaltet:
    * Der Kunde hat einen aktiven "Flatrate"-Status.
    * Die Galerie ist explizit als "Gratis Download Galerie" markiert (`is_free_download`).
* **Restriktion:** In allen anderen Fällen ist der ZIP-Download deaktiviert oder ausgeblendet. Kunden müssen für jedes Bild einzeln eine Lizenz erwerben.
* **Tracking & Statistik:**
    * Wird ein ZIP heruntergeladen, erhöht sich der generelle Download-Counter der Galerie um die *Anzahl der im ZIP enthaltenen Bilder* (x).
    * Im Audit-Log (`download_logs`) wird der Download jedoch als *ein einziger* Eintrag protokolliert (`item_type = full_zip`), welcher als Metainformation (`payload`) die Anzahl der Bilder (x) enthält.
* **Technisches Verhalten:** Der Download-Trigger erfolgt via `<a target="_blank">` in einem neuen Tab, um die E2E-Testbarkeit (Playwright) zu verbessern und den Main-Thread nicht zu blockieren.

## 3. UI/UX Refactoring: Lizenzwahl
* **Kein Modal mehr:** Der Dialog ("Lizenz wählen") wird entfernt. Die Auswahl der Lizenzen wird direkt in die Detailansicht des jeweiligen Bildes integriert.
* **Dynamische Sichtbarkeit:** Nicht verfügbare (gesperrte) Auflösungen oder Lizenzarten werden im Frontend komplett ausgeblendet, anstatt sie ausgegraut/gesperrt darzustellen.
* **Echtzeit-Preisberechnung:** Die Benutzeroberfläche berechnet den finalen Preis dynamisch und zeigt ihn direkt an. Die Berechnung basiert auf den ausgewählten Faktoren (Basispreis * Nutzungsart * Nutzungsdauer * Nutzungshäufigkeit).

## 4. Erweiterte Lizenzoptionen (Migration V004 Update)
Die Lizenzmatrix wird um folgende Parameter erweitert:
* **Verwendungshäufigkeit:** Differenzierung zwischen *Einmaliger Verwendung* und *Mehrmaliger Verwendung* (neuer Preismultiplikator in `license_options`).
* **Custom Quote (Angebot anfordern):** Integration eines "Quote-Workflows". User können für spezielle Anforderungen ein individuelles Angebot (Quote) anfragen, statt sofort zu kaufen (`is_quote_request` in `orders`).
