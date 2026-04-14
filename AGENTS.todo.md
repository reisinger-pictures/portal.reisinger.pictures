# 📝 Projekt-Master-Backlog

### Epic: Stripe Dispute Protection & Compliance (High Priority)
- [ ] **Planner (Legal Evidence):** Konzept für ein DSGVO-konformes "Evidence Package" erstellen. Wie verknüpfen wir `download_logs` mit Stripe `PaymentIntents`, ohne gegen Datensparsamkeit zu verstoßen? (Idee: Speicherung der IP + UserAgent + Timestamp nur für bezahlte Transaktionen).
- [ ] **Planner (Access Control):** Definition der Zugriffs-Sperre. Welche UI-Zustände zeigen wir dem Kunden bei einer `disputed` Order? (Meldung: "Zugriff aufgrund von Rückbuchung gesperrt").
- [ ] **Maker (Backend Webhooks):** Implementiere `charge.dispute.created` Handler: Setzt Order-Status auf `disputed` und benachrichtigt den Admin via Accounting-BCC.
- [ ] **Maker (Backend Security):** Erweitere `DownloadController` und `FileDeliveryController`: Downloads und High-Res Ansichten müssen blockiert werden, wenn die verknüpfte Order im Status `disputed` oder `failed` ist.
- [ ] **Maker (Backend Webhooks):** Implementiere `charge.refunded` Handler: Setzt Order-Status auf `refunded` (Händler-Rückerstattung).

### Epic: Governance & Search Refinement
- [ ] **Planner (Search Security):** Evaluierung, ob `is_hidden` Galerien/Fotos bereits durch die `getAllowedGalleryIds()` Logik geschützt sind oder ob Meilisearch hier eine Sicherheitslücke (Inhalts-Leak via Suche) darstellt.
- [ ] **Maker (Search/Governance):** Registriere `is_hidden` als `filterableAttribute` in `scout.php`. Update `SearchController`, um `where('is_hidden', false)` für alle Suchen anzuwenden, die nicht von Admins/Fotografen stammen.

### Epic: Quality Assurance & Testing
- [ ] **Maker (Testing):** PHPUnit Feature-Tests für CRM und Snippets um explizite RBAC/403 Checks erweitern (Super-Admin Enforcement validieren).
- [ ] **Maker (Testing):** `LocationSearchTest.php` um Testfälle für PLZ-Suche ergänzen, um die Genauigkeit des GeoNames-Imports zu prüfen.
- [ ] **Checker:** Playwright E2E-Validierung der neuen CRM-Formulare und Tiptap-Shortcuts auf **mobilen Viewports** (Touch-Target Prüfung).
