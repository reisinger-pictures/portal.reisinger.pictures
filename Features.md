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
*   **Metadaten Bearbeiten:** Falls freigeschaltet, können PR-Agenturen oder Kunden die Titel und Bildbeschreibungen (IPTC) direkt im Browser anpassen, bevor sie die Bilder herunterladen.

---

## 📚 Quick-Links

Detaillierte Spezifikationen nach Kategorie:

### AI
- [01-ai-service-architecture.md](./features/ai/01-ai-service-architecture.md)
- [02-testing-strategy.md](./features/ai/02-testing-strategy.md)

### Auth
- [01-roles-and-access.md](./features/auth/01-roles-and-access.md)
- [02-magic-links.md](./features/auth/02-magic-links.md)
- [03-roles-and-rbac.md](./features/auth/03-roles-and-rbac.md)
- [04-permissions-hook.md](./features/auth/04-permissions-hook.md)

### Delivery
- [01-downloads-and-injection.md](./features/delivery/01-downloads-and-injection.md)
- [02-audit-logs.md](./features/delivery/02-audit-logs.md)
- [03-file-delivery-controller.md](./features/delivery/03-file-delivery-controller.md)

### E-Commerce
- [02-licensing-and-downloads.md](./features/ecommerce/02-licensing-and-downloads.md)
- [03-custom-quotes-and-stripe.md](./features/ecommerce/03-custom-quotes-and-stripe.md)
- [04-crm-and-contracts.md](./features/ecommerce/04-crm-and-contracts.md)
- [05-manual-invoices.md](./features/ecommerce/05-manual-invoices.md)
- [06-legal-evidence-and-disputes.md](./features/ecommerce/06-legal-evidence-and-disputes.md)
- [07-psychological-pricing.md](./features/ecommerce/07-psychological-pricing.md)
- [08-srp-coupon-system.md](./features/ecommerce/08-srp-coupon-system.md)
- [09-stripe-checkout-flow.md](./features/ecommerce/09-stripe-checkout-flow.md)

### Gallery
- [01-core-architecture.md](./features/gallery/01-core-architecture.md)

### Infrastructure
- [01-deployment.md](./features/infrastructure/01-deployment.md)
- [02-email-system.md](./features/infrastructure/02-email-system.md)
- [03-accounting-and-lifecycle.md](./features/infrastructure/03-accounting-and-lifecycle.md)
- [04-payout-system.md](./features/infrastructure/04-payout-system.md)
- [05-watermark-refactoring.md](./features/infrastructure/05-watermark-refactoring.md)
- [06-multi-domain-branding.md](./features/infrastructure/06-multi-domain-branding.md)
- [07-lightroom-multi-tenant-gap.md](./features/infrastructure/07-lightroom-multi-tenant-gap.md)
- [08-tenant-brand-concept.md](./features/infrastructure/08-tenant-brand-concept.md)
- [09-brand-context-queue-cli.md](./features/infrastructure/09-brand-context-queue-cli.md)
- [10-frontend-brand-tenant-isolation.md](./features/infrastructure/10-frontend-brand-tenant-isolation.md)
- [11-brand-settings-separation.md](./features/infrastructure/11-brand-settings-separation.md)
- [12-brand-registry-and-settings-fixes.md](./features/infrastructure/12-brand-registry-and-settings-fixes.md)
- [13-ftp-brand-isolation.md](./features/infrastructure/13-ftp-brand-isolation.md)
- [14-per-brand-catalog.md](./features/infrastructure/14-per-brand-catalog.md)
- [15-strict-user-brand-isolation.md](./features/infrastructure/15-strict-user-brand-isolation.md)
- [16-srp-volume-pricing.md](./features/infrastructure/16-srp-volume-pricing.md)
- [17-pricing-strategy-pattern.md](./features/infrastructure/17-pricing-strategy-pattern.md)
- [18-jwt-offer-tokens.md](./features/infrastructure/18-jwt-offer-tokens.md)
- [19-ftp-upload-pipeline.md](./features/infrastructure/19-ftp-upload-pipeline.md)
- [20-setting-resolver.md](./features/infrastructure/20-setting-resolver.md)

### Photos
- [01-upload-and-processing.md](./features/photos/01-upload-and-processing.md)
- [02-metadata-versioning.md](./features/photos/02-metadata-versioning.md)
- [03-ai-batch-edit.md](./features/photos/03-ai-batch-edit.md)
- [04-ai-server-side.md](./features/photos/04-ai-server-side.md)
- [05-photo-detail-swipe.md](./features/photos/05-photo-detail-swipe.md)

### Search
- [01-search-and-discovery.md](./features/search/01-search-and-discovery.md)
- [02-smart-assistance.md](./features/search/02-smart-assistance.md)
- [03-meilisearch-typo-tolerance.md](./features/search/03-meilisearch-typo-tolerance.md)

### Tech (Architecture & Guidelines)
- [01-database-schema.md](./features/tech/01-database-schema.md)
- [02-backend-architecture.md](./features/tech/02-backend-architecture.md)
- [03-frontend-architecture.md](./features/tech/03-frontend-architecture.md)
- [04-testing-guidelines.md](./features/tech/04-testing-guidelines.md)
- [05-security-and-perf-refinement.md](./features/tech/05-security-and-perf-refinement.md)
