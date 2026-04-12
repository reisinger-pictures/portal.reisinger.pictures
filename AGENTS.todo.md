# 📝 Projekt-Master-Backlog (Kumulativ)

### Epic: CRM, Dokumente & Tiptap Snippets
- [ ] **Plan/Maker (Testing/PDF):** E2E- und PHPUnit-Tests für die manuelle PDF-Rechnungserstellung implementieren (Sicherstellen, dass die Kalkulation strikt im Backend validiert wird; Code ggf. für bessere Testbarkeit refactoren).
- [ ] **Plan/Maker (Backend API):** CRUD-Controller für Customers und Text Snippets (Super-Admin only). *(DoD-Hinweis: PHPUnit Feature-Tests für RBAC/403 und Such-Funktionalität fehlen)*
- [ ] **Plan/Maker (Search/GeoNames):** GeoNames Import erweitern, um PLZ-Daten für das Adress-Autocomplete bereitzustellen. *(DoD-Hinweis: LocationSearchTest.php muss explizite PLZ-Suche abdecken)*
- [ ] **Plan/Maker (Frontend UI):** Adress-Autocomplete (PLZ -> Stadt/Land) in `ManagementManualInvoiceView` und im CRM integrieren. *(DoD-Hinweis: Adress-Autocomplete fehlt noch komplett im CRM/Kunden anlegen Modal)*
- [ ] **Plan/Maker (Frontend CRM):** Neue Ansicht zur Verwaltung von Kunden; Integration einer Suche in das Rechnungsformular (Kunde wählen -> Adresse füllt sich).
- [ ] **Plan/Maker (Tiptap Extension):** Erweitere `WysiwygEditor.tsx` um einen "Snippet"-Button in der Toolbar, der Snippets vom Backend lädt und per Klick an der Cursor-Position einfügt.
- [ ] **Plan/Maker (Backend PDF):** Refactoring des Generators für den Support von Dokumenttypen (Angebot, Rechnung, Vertrag).
- [ ] **Plan/Maker (Backend PDF):** HTML-Sanitization via `strip_tags()` für `custom_html_terms` implementieren, um nur von DomPDF unterstützte Tags zuzulassen.

### Epic: Security, Performance & Core Fixes
- [ ] **Plan/Maker (Backend Delivery):** Implementiere `Cache::lock` für Bild-Downloads (Zip/Single) analog zu Thumbnails, um CPU-Spikes durch parallele Skalierungs-Requests (Imagick) zu verhindern.
- [ ] **Plan/Maker (Backend Webhooks):** Entferne den Fallback im `WebhookController` (`if (!$endpoint_secret && app()->environment('local'))`). Webhook-Signaturen müssen lokal zwingend via Stripe-CLI geprüft werden, um valide E2E-Tests zu garantieren.
