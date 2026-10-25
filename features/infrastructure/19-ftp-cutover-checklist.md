# FTP Cutover-Checklist

> **Stand:** 2026-09-26. **Ziel:** SFTPGo live schalten, `pure-ftpd` abschalten,
> dann mit echten Kameras testen und korrigieren.
>
> **Quellen:** Harness-Befund (`tests/scripts/ftp-transport-test/`),
> `19-ftp-upload-pipeline.md` §7.

---

## 1. Host-Vorbereinigung (vor Docker)

- [ ] **Backup des gesamten `ftp`-Baums** inkl. `1002:webgroup`, `2775`/setgid
- [ ] **Restore-Test** — nicht nur Backup vorhanden, sondern geprüft, dass ein
      Restore funktioniert
- [ ] **SFTPGo-Ordner auf dem Host:** `ftp/<slug>` mit `1002:webgroup` und
      `2775` (setgid, Gruppe schreibbar, **Welt nicht schreibbar**) — neue Dateien
      erben die Gruppe. **Geändert 2026-09-28, D-2 (FTP inbox directory provisioning):** vorher
      stand hier `2777`. **Nicht** `2755` — damit kann der Prozess in einem
      fremden Verzeichnis weder anlegen noch `unlink`en, und
      `FtpController::process()` macht genau das. Kein gemeinsamer
      `r1`-User mehr; jeder User bekommt sein eigenes Home. Ab D-2 legt das Portal
      den Ordner selbst an; dieser Punkt gilt nur für den Bestand und für den
      Handbetrieb vor dem ersten Slug-Wechsel.
- [ ] **`pure-ftpd` läuft noch** — nicht vor Kamerabestätigung abschalten

## 2. Docker-Stack (Portainer)

- [ ] **Compose-Datei aktualisieren** (aus `deployment/docker-compose.yml`)
- [ ] **In der Portainer-Stack-Env setzen** (Betriebswerte und Geheimnisse,
      **nicht** ins Repo):
  - `SFTPGO_BASE_URL=http://sftpgo:8080`
  - `SFTPGO_DEFAULT_ADMIN_USERNAME=<user>`
  - `SFTPGO_DEFAULT_ADMIN_PASSWORD=<pass>`
  - `SFTPGO_API_KEY=<密钥>` — **optional und in Produktion nicht gesetzt**: der
        JWT-Fallback über das Admin-Wertepaar ist der endgültige Weg
        (Owner-Entscheidung 2026-10-22, D-53, §7.15). Der Platzhalter im
        Compose bleibt der dokumentierte Weg, falls SFTPGo den
        Admin-Zugang abweichend benennt.
  - `SFTPGO_FTPD_TLS_MODE=<0|1|2>` (Default 1 = implizites TLS)
  - `SFTPGO_PASV_PORT_START=50000`, `SFTPGO_PASV_PORT_END=50100` (passive
        FTPS-Range)
  - `SFTPGO_SFTP_PORT=2222`, `SFTPGO_FTPS_PORT=989` (nur die **Host**-Ports;
        die Container-Ports sind im Compose festgelegt)
- [ ] **Feste Compose-Werte** (stehen in `deployment/docker-compose.yml` und
      gehören **nicht** in die Stack-Env — sie haben keinen Secret-Bezug und
      müssen ohne Pflege eines Stack-Env wirken):
  - `SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN=true` (sonst keine Admins —
        der Wert muss **vor** dem ersten Start wirksam sein, sonst bleibt die
        `admins`-Tabelle leer und der Token antwortet mit 401; D-54)
  - `SFTPGO_FTPD__BINDINGS__0__ADDRESS=0.0.0.0`
  - `SFTPGO_FTPD__BINDINGS__0__PORT=989`
  - `SFTPGO_FTPD__BINDINGS__0__CERTIFICATE_FILE=/var/lib/sftpgo/ftps/cert.pem`
  - `SFTPGO_FTPD__BINDINGS__0__CERTIFICATE_KEY_FILE=/var/lib/sftpgo/ftps/key.pem`
  - `SFTPGO_SFTPD__BINDINGS__0__ADDRESS=0.0.0.0`
  - Optional: `SFTPGO_FTPD__BINDINGS__0__CIPHER_SUITES` (Go-Namen, kommagetrennt)
- [ ] **Zertifikat:** SFTPGo erstellt beim ersten Start ein Self-Signed-Zertifikat
      unter `/etc/sftpgo/`. Die Kamera akzeptiert es (verifiziert mit pure-ftpd).
      Kein gültiges Zertifikat nötig, kein Host-Setup.
- [ ] **Volume `sftpgo_data` mounten** → `/var/lib/sftpgo` (ohne das ist die
      Instanz nach jedem Neustart ohne Admin-User)
- [ ] **Container-User:** `1002:82` (Host-Konvention des Website-Baums, D-1) —
      seit 2026-10-25 dieselbe Identität wie das Backend, weil beide auf denselben
      Host-Baum schreiben

## 3. Firewall (Host)

- [ ] **2222/tcp** offen (SFTP)
- [ ] **989/tcp** offen (FTPS)
- [ ] **50000-50100/tcp** offen (passive FTPS) — **nur** FTPS braucht diese
      Range; SFTP nicht
- [ ] **8080/tcp** **nicht** offen (Admin-API — nur intern)

> **Firewall bleibt manuell, bewusst (Owner-Entscheidung 2026-09-26).** Keine
> Automatisierung: ein Container ändert die Host-Firewall nur mit
> `host`-Netzwerk + `NET_ADMIN` — ein privileged Container, der bei
> Fehlkonfiguration bestehende Regeln überschreibt statt nur zu ergänzen. Der
> Aufwand steht in keinem Verhältnis zu drei einmaligen Zeilen.
>
> ```bash
> ufw allow 2222:2222/tcp     comment 'SFTPGo SFTP'
> ufw allow 989:989/tcp       comment 'SFTPGo FTPS'
> ufw allow 50000:50100/tcp   comment 'SFTPGo passive FTPS'
> # 8080/tcp bleibt ZU.
> ```
>
> Prüfung von **außen** (lokal sagt der Container immer „offen"):
>
> ```bash
> nc -zv reisinger.pictures 2222
> nc -zv reisinger.pictures 989
> nc -zv reisinger.pictures 8080   # MUSS fehlschlagen
> ```

### 3a. Ordner-Anlage — automatisch, kein Handschritt

`php artisan ftp:provision-folders --fix-permissions` läuft im
`backend`-Entrypoint bei **jedem** `up -d`, direkt nach der Migration. Legt
`ftp/<slug>` für jeden Fotografen an und setzt das **setgid-Bit** (2775) — ohne
setgid landet ein Upload in der Gruppe des SFTPGo-Prozesses statt in
`webgroup`, und der Import kann die Datei nicht lesen. Idempotent, also
gefahrlos bei jedem Deploy.

Slugs, die die Formatregel verletzen (Bestand vor P1-M21), werden übersprungen
und geloggt — sie bekommen ihren Ordner mit dem nächsten Slug-Reset (P1-M34).

**Nach einem neuen Fotografen:** `docker compose exec backend php artisan
ftp:provision-folders` — oder einfach das nächste `up -d`.

## 4. Kamera-Konfiguration (nach Stack-Start)

- [ ] **SFTP testen:** Kamera → `ftp_slug`@host:2222, Passwort aus Portal
- [ ] **FTPS testen:** Kamera → `ftp_slug`@host:989, Passwort aus Portal,
      **explizites** TLS (FTPES), TLS 1.2
- [ ] **Beide Uploads erfolgreich** — Dateien landen in `ftp/<slug>/` mit
      `1002:webgroup`

## 5. Cutover (nach Kamerabestätigung)

- [ ] **Portal-Backend neustarten** (picked up neue Env-Variablen)
- [ ] **`pure-ftpd` abschalten** — erst jetzt, nicht vorher
- [ ] **Erster echter Import** mit Kamera durchführen
- [ ] **Rollback-Fenster:** beide Zugänge bleiben parallel bestehen, bis ein
      Import mit Kamera durchgelaufen ist

## 6. Fehlerbehebung

| Symptom | Ursache | Fix |
|---|---|---|
| 401 auf `/api/v2/token` | Admin-User nicht erstellt | `SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN=true` im **Compose** prüfen (nicht in der Stack-Env) |
| `no such table: schema_version` | Volume falsch gemountet oder nicht beschreibbar | `sftpgo_data` → `/var/lib/sftpgo` prüfen |
| TLS-Handshake erfolgreich, Upload bricht ab | Passive Ports nicht offen | 50000-50100 in Firewall öffnen |
| `SSL handshake has read 0 bytes` | Dienst nicht erreichbar oder falscher Port | Compose-Log prüfen, Port-Mapping prüfen |
| `Config file used: "/etc/sftpgo/sftpgo.json"` | SFTPGo meldet das, nutzt aber DB | Ignorieren — das ist ein Struct-Dump, kein Fehler |
| Kamera blockiert Zertifikat | Self-Signed | Gültiges Zertifikat von öffentlicher CA einsetzen |
| `ftp_slug` als Login nicht akzeptiert | Slug-Validierung noch nicht deployed | Migration + Backend-Deploy nötig |
