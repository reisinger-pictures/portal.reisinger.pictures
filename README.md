# portal.reisinger.pictures

Moderne, zustandslose SaaS-Plattform für Fotografen zur Bildauswahl und Auslieferung mit integriertem E-Commerce und B2B-Mandantenverwaltung.

 - 🌟 **[Feature-Übersicht (Für Fotografen & Kunden ansehen)](features/README.md)**
 - 📖 **[Technische Dokumentation & Konzepte ansehen (Für Entwickler)](features/tech/README.md)**

[`Features.md`](Features.md) ist eine ältere Übersichtsseite; die kanonischen
Einstiegspunkte für die aktuelle Dokumentation sind
[`features/README.md`](features/README.md) und
[`features/tech/README.md`](features/tech/README.md).

## 🚀 Hybrides Login-Verfahren

Das Portal unterscheidet strikt zwischen zwei Nutzertypen:

1. **Fotografen & Admins:** Loggen sich über die Sidebar mit E-Mail und Passwort ein, um das System zu verwalten.
2. **Kunden (Gäste):** Erhalten einen individuellen **Magic Link** (`/invite/{token}`). Beim ersten Aufruf identifizieren sie sich (optional) und erhalten danach ein JWT für den direkten Zugriff.

## Lokales Setup (Quickstart mit IntelliJ / PhpStorm)

Die verfügbaren Run-Configs findest du im **Run & Debug** Tab. Es gibt keine
kombinierte Config „Start Alles“: Der Backend-Dienst, die Docker-Services und
der Vite-Server werden bewusst separat gestartet.

### 1. Der reguläre Start
1. Starte **`🐳 [Run] Start Docker (Dev)`**. Das startet ausschließlich den lokalen
   Meilisearch-Service auf Port `7700` (keine Datenbank und kein Mailpit).
2. Starte das Backend über deine lokale PHP-Umgebung bzw. Laravel Herd
   (`portal.test`).
3. Starte **`⚡ [Run] Frontend: Start Frontend`**. Der Vite-Dev-Server läuft danach
   auf **http://localhost:4321**.

Mailpit ist ein optionaler, nativer Homebrew-Dienst und wird von keinem der
beiden Docker-Run-Configs automatisch gestartet. Starte ihn bei Bedarf mit
`brew services start mailpit` (SMTP `1025`, UI/API `8025`).

### 2. Einmaliges Setup (beim ersten Mal oder nach Pulls)
1. Kopiere `backend/.env.example` nach `backend/.env` und trage die benötigten
   Werte lokal ein. **Vor `composer setup` oder dem ersten Seed müssen mindestens
   `ADMIN_EMAIL` und ein nicht leeres `ADMIN_PASSWORD` gesetzt sein.** Generiere
   außerdem `JWT_SECRET` und `FILE_ENCRYPTION_KEY` nach den Hinweisen im Template.
   Die Datei bleibt untracked; echte Secrets gehören nicht ins Repository.
   Für den lokalen Foto-Speicher aus dem Template zuerst
   `mkdir -p /tmp/portal-reisinger-photos` ausführen. Der Template-Wert
   `PHOTO_STORAGE_PATH=/tmp/portal-reisinger-photos` ist ein absoluter,
   beschreibbarer Local-Dev-Pfad; `/var/www/photos` bleibt ausschließlich der
   Container-/Deployment-Pfad in `backend/.env.ci` und der Produktions-Compose.
2. Installiere die Backend-Abhängigkeiten bei einem frischen Checkout mit
   `composer install` im Verzeichnis `backend`. `composer setup` ist nur ein
   Automationsschritt: Es kopiert eine fehlende `.env` nicht interaktiv, erzeugt
   keine Admin-Zugangsdaten und ruft anschließend `migrate --force --seed` auf.
   Mit leerem `ADMIN_PASSWORD` schlägt dieser Flow am Seeder fehl.
3. Führe **`⚙️ [Setup] Backend: Init (Cache)`** aus. Diese Config führt nur
   `php artisan optimize:clear` aus; sie erstellt weder `.env` noch Keys.
4. Generiere die lokale Anwendungskennung mit `php artisan key:generate` und das
   JWT-Secret mit **`⚙️ [Setup] Backend: JWT Secret generieren`**.
5. Führe **`⚙️ [Setup] Backend: DB Migration (Update)`** aus. Die Config verwendet
   `php artisan migrate --seed`; der Seed ist für den Bootstrap-Admin erforderlich.

*(Bei Laravel Herd genügt für die aktuelle RP-Konfiguration
`herd secure portal.test`; eine separate `portal-srp.test`-Domain ist nicht
mehr Teil des aktuellen Brand-Setups.)*

### 3. Wartung & Herunterfahren
* **Index aktualisieren:** Wenn du Probleme mit der Suche hast, führe
  **`🔧 [Wartung] Meilisearch Sync & Import`** aus.
* **Feierabend:** Nutze **`🛑 [Core] Stop Docker (Dev)`**, um die lokalen
  Docker-Services herunterzufahren. Für die Test-Services gibt es separat
  **`🛑 [Core] Stop Docker (Test)`**.
* **Achtung:** **`🧨 [Gefahr] DB Reset & Seed`** löscht deine gesamte lokale
  SQLite-Datenbank (`backend/database/database.sqlite`) unwiderruflich und baut
  sie mit `migrate:fresh --seed` neu auf.

### Deployment-Sync (optional)

Der Run-Config **`🚀 [Deploy] Sync Only`** führt `sync.sh` aus. Das Script
verwendet `set -euo pipefail`: Der Backend-Sync (inklusive
`rsync-backend-exclude.txt`) und der Frontend-`dist`-Sync müssen beide
erfolgreich abgeschlossen sein, bevor der Erfolg ausgegeben wird. Ein
fehlgeschlagener `rsync`-Aufruf beendet den Vorgang mit einem Fehlerstatus.
Der Transport ist `rsync` über `ssh root@reisinger.pictures` (seit 2026-09-26;
zuvor `rclone` über ein SFTP-Remote). `./sync.sh --dry-run` zeigt den
Änderungsumfang ohne Schreibzugriff. Nach einem Backend-Deploy ist zusätzlich
`docker restart portal_backend` nötig (OPcache, siehe
`features/infrastructure/01-deployment.md` §11).
Der Sync ersetzt weder Migration noch Seed und ist kein Ersatz für den
fail-closed Deployment-Start.

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
- **Dashboard:** Verwende die lokal gesetzten `ADMIN_EMAIL`/`ADMIN_PASSWORD` aus
  `backend/.env`. `admin@example.com` steht nur als Beispiel im Template bzw. in
  expliziten E2E-Fixtures; es gibt im aktuellen Runtime-Config keinen
  Admin-Passwort-Fallback.
- **Datenbank:** SQLite-Datei `backend/database/database.sqlite` (kein DB-Container; Einrichtung via `php artisan migrate:fresh --seed`)

### Stripe Webhooks (Lokal Testen)
Führe `stripe listen --forward-to localhost:8000/api/webhooks/stripe` aus und trage das ausgegebene `STRIPE_WEBHOOK_SECRET` in die `.env` Datei im Backend ein.

## Lokale E2E-Tests isoliert ausführen (eigene Backend-Instanz + SQLite-DB)

Für die Playwright-E2E-Suite gibt es lokal einen **isolierten E2E-Backend**
mit eigener SQLite-DB (`backend/database/database.e2e.sqlite`, gitignored) auf
Port **8001**. So bleiben die Daten der Dev-Instanz (`portal.test` /
`backend/database/database.sqlite`) unberührt:

1. **Backend starten:** Run-Config `🐘 [Run] Start E2E Backend (isoliert)` (oder `bash scripts/e2e-up.sh`) — startet Test-Docker-Services (Meili 7701), generiert `backend/.env.e2e` aus deinem `.env`, migriert + seedet die E2E-DB, lädt die deterministischen Location-Fixtures in den Test-Suchindex und served auf `http://127.0.0.1:8001`.
2. **Frontend mit Proxy:** Run-Config `⚡ [Run] Frontend: Start Frontend (E2E Proxy)` (oder `VITE_API_PROXY=http://127.0.0.1:8001 pnpm dev`).
3. **Tests:** Run-Config `🧪 [Test] Frontend: Playwright (E2E isoliert)` (oder `pnpm test:e2e`).

*Hinweis: Die Dev-Instanz (`portal.test`) bleibt davon völlig unberührt — die E2E-Daten liegen ausschließlich in der e2e-SQLite-DB und dem Test-Search-Container. Mails nutzen das native Homebrew-Mailpit (Ports 1025/8025) — kein Docker-Mailpit-Container.*

### Mailpit (lokal & Tests)
Das native **Homebrew-Mailpit** (`brew install mailpit`, Service via
`brew services start mailpit`) läuft lokal auf Port **1025** (SMTP) und **8025**
(HTTP-API/UI unter `http://localhost:8025`) und wird für lokale PHPUnit-/E2E-
Läufe genutzt. Die lokalen Compose-Dateien starten **keinen** Mailpit-
Container; der CI-Workflow bringt seinen Mailpit-Service separat als Job-
Service mit.