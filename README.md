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

*(Tipp: Vergiss nicht, lokal sowohl `herd secure portal.test` als auch `herd secure portal-srp.test` auszuführen, falls du Laravel Herd nutzt!)*

### 3. Wartung & Herunterfahren
* **Index aktualisieren:** Wenn du Probleme mit der Suche hast, führe `🔍 [Wartung] Meilisearch Sync & Import` aus.
* **Feierabend:** Nutze `🛑 [Core] Stop Docker (Graceful)` um die Services sauber herunterzufahren.
* **Achtung:** `🧨 [Gefahr] DB Reset & Seed` löscht deine gesamte lokale Datenbank unwiderruflich und baut sie neu auf!

### macOS: exiftool & ImageMagick für Laravel Herd

Das Backend verarbeitet Bilder über die externen CLI-Tools `exiftool` (EXIF-Metadaten, MIME-Validierung) und ImageMagick (`magick`/`convert`, Skalierung). Diese werden via Symfony Process aufgerufen und müssen im `PATH` des Webserver-Prozesses liegen.

**Problem auf macOS:** Laravel Herd betreibt PHP-FPM als GUI-Daemon via `launchd`. GUI-Prozesse erben beim Systemstart nur `/usr/local/bin` und die Systempfade aus `/etc/paths` — **nicht** die Shell-Config (`~/.zshrc`). Homebrew installiert die Tools unter `/opt/homebrew/bin` (Apple Silicon) bzw. `/usr/local/bin` (Intel). Auf Intel-Macs sind die Tools somit automatisch erreichbar, auf Apple Silicon jedoch **nicht**.

**Lösung (einmalig, Apple Silicon):** Lege Symlinks im systemweiten `PATH` an, den auch `launchd`/PHP-FPM lesen:

```bash
sudo ln -s /opt/homebrew/bin/exiftool /usr/local/bin/exiftool
sudo ln -s /opt/homebrew/bin/magick /usr/local/bin/magick
sudo ln -s /opt/homebrew/bin/convert /usr/local/bin/convert
```

Danach Laravel Herd einmal neu starten, damit PHP-FPM die Tools findet. Ohne diesen Schritt schlagen Bild-Uploads mit `422 "Die hochgeladene Datei ist kein gültiges oder lesbares Bild."` fehl (der serverseitige `exiftool`-MIME-Check läuft ins Leere).

### Login-Daten (Lokal)
- **Dashboard:** `florian@reisinger.pictures` / `admin`
- **Datenbank:** user: `portal_user` / pass: `admin`

### Stripe Webhooks (Lokal Testen)
Führe `stripe listen --forward-to localhost:8000/api/webhooks/stripe` aus und trage das ausgegebene `STRIPE_WEBHOOK_SECRET` in die `.env` Datei im Backend ein.