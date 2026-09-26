---
domain: infrastructure
topic: email-system
status: active
---

# Technical Concept: Email System & Notifications

## 1. Hardcoded Templates (Blade)
- Um die Architektur und das Deployment zu vereinfachen, wurde das datenbankgestützte Template-System (EmailTemplate Model) entfernt.
- **Architektur-Richtlinie (STRIKT):** E-Mails werden **ausschließlich** über native Laravel Blade Views gerendert (z.B. `custom.blade.php`, `activate.blade.php`, `notification.blade.php`).
- **Verbot von Inline-HTML:** Das Verwenden von `Mail::html()` mit hartcodierten HTML-Strings innerhalb von Controllern ist untersagt, um das Corporate Design (Logo, Layout) konsistent über alle System-E-Mails hinweg zu garantieren.

## 2. Opt-In & Benachrichtigungs-Management (DSGVO / UX)

- **Strikte Geschäftslogik (Client-Only):** Allgemeine E-Mail-Benachrichtigungen zu Galerien werden **ausschließlich** an Kunden versendet. Diese Regel ist strikt und das System ist nicht dafür vorgesehen, Fotografen über diesen Weg Updates zu schicken.
- **Skalierbares UI-Design (Component Reuse):** Obwohl logisch nur Kunden benachrichtigt werden, sollen UI-Komponenten (wie Opt-In-Toggles) so generisch und rollenunabhängig wie möglich gebaut werden. Das Ziel ist es, "ganz andere UIs" für verschiedene Rollen zu vermeiden. Bestehende Komponenten sollen stattdessen leicht für andere Rollen oder zukünftige Features "upgegradet" werden können.
- **Standardmäßig Deaktiviert:** Kunden und zugewiesene Benutzer erhalten nicht automatisch E-Mails über Galerie-Updates. Das Feld `wants_notifications` in den Pivot-Tabellen `user_galleries` und `user_gallery_groups` steht standardmäßig auf `false`.
- **Lokaler Toggle:** Kunden können in der jeweiligen Galerieansicht (`DeliveryView` / `SelectionView`) Benachrichtigungen gezielt aktivieren.
- **Zentrale Verwaltung:** Über die zentrale Ansicht "Benachrichtigungen" (`/notifications`) können Nutzer ihre Abonnements für alle zugewiesenen Meta-Galerien (Gruppen) und Einzel-Galerien zentral verwalten.
- **Filterung beim Versand:** Der `MailController` filtert beim manuellen oder automatischen Versand strikt nach Nutzern, deren `wants_notifications` Flag auf `true` gesetzt ist.

## 3. Custom Messages & Preview
- Für manuelle E-Mails (z. B. der "E-Mail senden" Action-Button im Fotografen-Dashboard) existiert ein Modal, in dem der Fotograf eine individuelle HTML-Nachricht verfassen kann.
- Dieses Modal bietet einen Live-Preview-Toggle, der die eingegebenen Variablen (z. B. `{user_name}`, `{link}`) durch Dummy-Daten ersetzt und das finale HTML rendert.

## 4. Brand-Aware Emails (EMAIL-01–04)
- Alle 7 Mail-Klassen (`InvoiceMail`, `CustomMail`, `GalleryInviteMail`, `ActivateAccountMail`, `RatingFinishedMail`, `NotificationMail`, `OrgInviteMail`) nutzen das `BrandAwareMail` Trait.
- Das Trait stellt sicher:
  - **Brand Context Restoration:** `ensureBrandContext()` stellt den Brand bei Queue-Workern wieder her (captured via `initializeBrand()` im Konstruktor).
  - **Frontend URL (EMAIL-01):** `brandFrontendUrl()` liefert `config('app.frontend_url_srp')` für SRP, sonst `config('app.frontend_url')`.
  - **Logo URL (EMAIL-02):** `brandLogoUrl()` kombiniert Frontend-URL mit `/android-chrome-192x192.png`.
  - **Sender (EMAIL-03):** `applyBrandFrom()` setzt Absender per `config('mail.from_srp.*')` für SRP.
  - **BCC (EMAIL-04):** `brandBcc()` liest `config('services.accounting_email_srp')` oder `accounting_email_rp`.
- Templates erhalten `$logoUrl` via `->with()` und nutzen `??=` als Fallback.
- Siehe `backend/app/Mail/BrandAwareMail.php` und `features/infrastructure/06-multi-domain-branding.md`.

## 5. Local Testing
- Der lokale `Mailpit`-Service fängt alle ausgehenden E-Mails im Development-/CI-Testmodus ab.
- **Zustellungsnachweis:** PHPUnit-Integrationstests (mindestens der Invoice-/Checkout-Pfad) prüfen über die Mailpit-API die tatsächlich angekommene Nachricht und parse HTML/Token-Links. `Mail::fake()` ist ausschließlich für deterministische Enqueue-/Fault-Injection-Tests erlaubt und liefert keinen SMTP-Zustellungsnachweis. E2E-Tests verwenden Mailpit ebenfalls für die End-to-End-Linkprüfung.

## 6. Production SMTP Gate
- Im Produktionsbetrieb muss `MAIL_MAILER=smtp` gesetzt sein. `MAIL_SCHEME=smtp|smtps` und `MAIL_REQUIRE_TLS=true` sind zusammen mit `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` und `MAIL_FROM_NAME` Pflichtwerte. `MAIL_ENCRYPTION` ist in Laravel 13 kein gültiger SMTP-Schlüssel und darf nicht als TLS-Nachweis verwendet werden.
- `smtp` verwendet Symfony STARTTLS nur mit `require_tls=true`; `smtps` verwendet implizites TLS. Der effektive Transport wird vor Queue-, Scheduler- und PHP-FPM-Start geprüft. Der Gate validiert nur Konfiguration und Topologie, nicht die Zustellung oder Erreichbarkeit des SMTP-Providers.
- Die Queue kann einen SMTP-Transportfehler retryen. Invoice-Mail garantiert deshalb höchstens einen durable Enqueue-Vorgang, nicht exactly-once SMTP-Zustellung. Terminale Jobs bleiben in `failed_jobs` und benötigen eine dokumentierte Operator-Recovery.
- Mailpit darf ausschließlich als lokales/CI-Testfixture dokumentiert werden. Ein Mailpit-Test ist kein Live-Nachweis für den Produktions-SMTP- oder den Provider-Zustellungsstatus.
- Die Betriebsprüfung und die Grenzen der Queue-Semantik stehen im [Production Operations Runbook](29-production-operations-runbook.md).
