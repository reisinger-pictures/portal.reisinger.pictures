# FTP Cutover-Checklist

> **Stand:** 2026-09-26. **Ziel:** SFTPGo live schalten, `pure-ftpd` abschalten,
> dann mit echten Kameras testen und korrigieren.
>
> **Quellen:** Harness-Befund (`tests/scripts/ftp-transport-test/`),
> `19-ftp-upload-pipeline.md` §7, Board P1-M24/M32/M36.

---

## 1. Host-Vorbereinigung (vor Docker)

- [ ] **Backup des gesamten `ftp`-Baums** inkl. `1002:webgroup`, `2777`/setgid
- [ ] **Restore-Test** — nicht nur Backup vorhanden, sondern geprüft, dass ein
      Restore funktioniert
- [ ] **SFTPGo-Ordner auf dem Host:** `ftp/<slug>` mit `1002:webgroup` und
      `2777` (setgid) — neue Dateien erben die Gruppe. Kein gemeinsamer
      `r1`-User mehr; jeder User bekommt sein eigenes Home.
- [ ] **`pure-ftpd` läuft noch** — nicht vor Kamerabestätigung abschalten

## 2. Docker-Stack (Portainer)

- [ ] **Compose-Datei aktualisieren** (aus `deployment/docker-compose.yml`)
- [ ] **Env-Variablen setzen:**
  - `SFTPGO_BASE_URL=http://sftpgo:8080`
  - `SFTPGO_API_KEY=<密钥>` (in Portainer-Stack-Env, nicht im Repo)
  - `SFTPGO_DEFAULT_ADMIN_USERNAME=<user>`
  - `SFTPGO_DEFAULT_ADMIN_PASSWORD=<pass>`
  - `SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN=true` (sonst keine Admins)
  - `SFTPGO_DATA_PROVIDER__DRIVER=sqlite`
  - `SFTPGO_DATA_PROVIDER__NAME=sftpgo.db`
  - `SFTPGO_FTPSERVER__BINDINGS__0__PORT=989`
  - `SFTPGO_FTPSERVER__BINDINGS__0__CERTIFICATE_FILE=/etc/sftpgo/cert.pem`
  - `SFTPGO_FTPSERVER__BINDINGS__0__KEY_FILE=/etc/sftpgo/key.pem`
  - `SFTPGO_FTPD__PASSIVE_PORT_RANGE__START=50000`
  - `SFTPGO_FTPD__PASSIVE_PORT_RANGE__END=50100`
  - `SFTPGO_FTPD__BINDINGS__0__PASSIVE=true`
  - Optional: `SFTPGO_FTPD__BINDINGS__0__CIPHER_SUITES` (Go-Namen, kommagetrennt)
- [ ] **Zertifikat:** SFTPGo erstellt beim ersten Start ein Self-Signed-Zertifikat
      unter `/etc/sftpgo/`. Die Kamera akzeptiert es (verifiziert mit pure-ftpd).
      Kein gültiges Zertifikat nötig, kein Host-Setup.
- [ ] **Volume `sftpgo_data` mounten** → `/var/lib/sftpgo` (ohne das ist die
      Instanz nach jedem Neustart ohne Admin-User)
- [ ] **Container-User:** `1000:1000` (wie Backend) — **nicht** `1002:webgroup`,
      weil der Container auf den Host-Pfad schreibt

## 3. Firewall (Host)

- [ ] **2222/tcp** offen (SFTP)
- [ ] **989/tcp** offen (FTPS)
- [ ] **50000-50100/tcp** offen (passive FTPS) — **nur** FTPS braucht diese
      Range; SFTP nicht
- [ ] **8080/tcp** **nicht** offen (Admin-API — nur intern)

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
| 401 auf `/api/v2/token` | Admin-User nicht erstellt | `SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN=true` setzen |
| `no such table: schema_version` | Volume falsch gemountet oder nicht beschreibbar | `sftpgo_data` → `/var/lib/sftpgo` prüfen |
| TLS-Handshake erfolgreich, Upload bricht ab | Passive Ports nicht offen | 50000-50100 in Firewall öffnen |
| `SSL handshake has read 0 bytes` | Dienst nicht erreichbar oder falscher Port | Compose-Log prüfen, Port-Mapping prüfen |
| `Config file used: "/etc/sftpgo/sftpgo.json"` | SFTPGo meldet das, nutzt aber DB | Ignorieren — das ist ein Struct-Dump, kein Fehler |
| Kamera blockiert Zertifikat | Self-Signed | Gültiges Zertifikat von öffentlicher CA einsetzen |
| `ftp_slug` als Login nicht akzeptiert | Slug-Validierung noch nicht deployed | Migration + Backend-Deploy nötig |
