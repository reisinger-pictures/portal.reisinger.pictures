---
domain: infrastructure
topic: deployment
status: active
---

# Technical Concept: Deployment & Production

## 1. Portainer & Docker Stack
- The project is deployed as a Docker stack via **Portainer**.
- **Automated Init:** The initialization logic is embedded directly into the `command` block of the `backend` container. It waits for the database to be ready, generates missing application keys (`APP_KEY`, `JWT_SECRET`), and runs `php artisan migrate --force` before starting the background workers and the Apache webserver. 

## 2. Environment Variables
- All configuration is managed via Portainer environment variables, overriding the `.env` file.
- Key variables include database credentials (`DB_ROOT_PASSWORD`), Meilisearch keys (`MEILI_MASTER_KEY`), and SMTP settings (e.g., Gmail App Passwords).

## 3. Frontend & Routing
- The frontend is built statically (`pnpm build`) and served via Nginx.
- A Reverse Proxy (e.g., Nginx Proxy Manager / Traefik) handles routing:
  - `/api/*` -> `portal_backend` (Port 80)
  - `/*` -> `portal_frontend` (Port 80)


## 4. On-The-Fly Webserver-Konfiguration (Apache DocumentRoot Patching)
- **Problem:** Das Basis-Image `ghcr.io/reisi007/...` erwartet den DocumentRoot unter `/var/www/html`, Laravel benötigt jedoch `/var/www/html/public`.
- **Lösung:** Anstatt eines eigenen Dockerfiles wird die Konfiguration beim Start des "backend"-Containers via `sed`-Befehl im Docker-Command überschrieben.
- **Implementierung (Idempotent):** In der `docker-compose.yml` wird vor der Ausführung von `sed` mit `grep` geprüft, ob die Ersetzung bereits stattgefunden hat. Dadurch überlebt der Container auch reguläre Neustarts (z.B. via Portainer) ohne 403-Fehler.

## 5. Split-Domain-Deployment-Strategie
- **Pfad-Trennung:** Das Backend liegt unter `/home/webadmin/websites/api-portal.reisinger.pictures`, das Frontend unter `/home/webadmin/websites/web-portal.reisinger.pictures`.
- **NPM Routing:** Der Nginx Proxy Manager routet die Location `/api` explizit an die feste IP des Backend-Containers (172.18.0.31) im `webnet`.

## 6. Externer FTP-Mount & Pfad-Konfiguration
- **Storage:** Der FTP-Ordner liegt außerhalb der App-Verzeichnisse unter `/home/webadmin/websites/ftp`.
- **Integration:** Dieser wird als Volume nach `/var/www/ftp` gemountet. Die Umgebungsvariable `FTP_STORAGE_PATH` weist Laravel an, diesen Pfad für die FTP-Inbox zu nutzen.

## 7. Automatisierte Admin-Provisionierung
- **Kommando:** `php artisan admin:update`
- **Logik:** Synchronisiert beim Start des `backend-init`-Containers den Admin-Nutzer basierend auf den ENV-Variablen `ADMIN_EMAIL` und `ADMIN_PASSWORD`. Dies stellt sicher, dass ein Login auch ohne initiale Datenbank-Seeds sofort möglich ist.

## 8. Produktion-Sicherheits-Gatekeeper
- **Validierung:** Der `backend-init`-Container verweigert den Start in 'production', wenn die Standard-Schlüssel (APP_KEY oder JWT_SECRET) aus der Dokumentation/Entwicklung erkannt werden.
- **Start-Delay:** `backend-init` wartet aktiv 15 Sekunden auf die MariaDB-Bereitschaft (First-Boot), um Race-Conditions bei der Migration zu verhindern.


## 9. OPcache & Live-Updates (Rclone Sync)
- **Die Falle:** Wenn PHP-Dateien im laufenden Betrieb über `rclone` (z.B. durch die `sync.bat`) auf den Produktionsserver synchronisiert werden, greifen Backend-Änderungen unter Umständen nicht sofort.
- **Der Grund:** In Produktionsumgebungen ist der PHP OPcache aus Performancegründen scharf geschaltet (meist `opcache.validate_timestamps=0`). PHP liest geänderte Dateien nicht neu von der Festplatte ein, sondern nutzt den alten Bytecode aus dem RAM.
- **Die Lösung:** Nach einem Rclone-Sync von Backend-Dateien muss der Apache/PHP-Container zwingend neu gestartet werden, um den Cache zu leeren:
  `docker restart portal_backend`
