# portal.reisinger.pictures

Moderne, zustandslose SaaS-Plattform für Fotografen zur Bildauswahl und Auslieferung mit integriertem E-Commerce und B2B-Mandantenverwaltung.

 - 🌟 **[Feature-Übersicht (Für Fotografen & Kunden ansehen)](Features.md)**
 - 📖 **[Technische Dokumentation & Konzepte ansehen (Für Entwickler)](features/README.md)**

## 🚀 Hybrides Login-Verfahren

Das Portal unterscheidet strikt zwischen zwei Nutzertypen:

1. **Fotografen & Admins:** Loggen sich über die Sidebar mit E-Mail und Passwort ein, um das System zu verwalten.
2. **Kunden (Gäste):** Erhalten einen individuellen **Magic Link** (`/invite/{token}`). Beim ersten Aufruf identifizieren sie sich (optional) und erhalten danach ein JWT für den direkten Zugriff.

## Lokales Setup (Quickstart mit IntelliJ / PhpStorm)

Das lokale Setup ist vollständig in VS Code / IntelliJ integriert. Öffne einfach den **Run & Debug** Tab.

### 1. Der reguläre Start
Wähle im Dropdown **`🚀 [Run] Start Alles (Docker + Frontend)`** und klicke auf Play.
Das startet automatisch MariaDB, Meilisearch, Mailpit und deinen Vite-Dev-Server (Frontend auf **http://localhost:4321**).

### 2. Einmaliges Setup (beim ersten Mal oder nach Pulls)
Falls du das Projekt neu eingerichtet hast oder sich die Datenbankstruktur geändert hat:
1. `⚙️ [Setup] Backend: Init (.env & Cache)`
2. `🔑 [Setup] Backend: JWT Secret generieren`
3. `💾 [Setup] Backend: DB Migration (Update)`

*(Tipp: Vergiss nicht, lokal `herd secure portal.test` auszuführen, falls du Laravel Herd nutzt!)*

### 3. Wartung & Herunterfahren
* **Index aktualisieren:** Wenn du Probleme mit der Suche hast, führe `🔍 [Wartung] Meilisearch Sync & Import` aus.
* **Feierabend:** Nutze `🛑 [Core] Stop Docker (Graceful)` um die Services sauber herunterzufahren.
* **Achtung:** `🧨 [Gefahr] DB Reset & Seed` löscht deine gesamte lokale Datenbank unwiderruflich und baut sie neu auf!

### Login-Daten (Lokal)
- **Dashboard:** `florian@reisinger.pictures` / `admin`
- **Datenbank:** user: `portal_user` / pass: `admin`

### Stripe Webhooks (Lokal Testen)
Führe `stripe listen --forward-to localhost:8000/api/webhooks/stripe` aus und trage das ausgegebene `STRIPE_WEBHOOK_SECRET` in die `.env` Datei im Backend ein.