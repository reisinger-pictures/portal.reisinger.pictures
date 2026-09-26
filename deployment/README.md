# Deployment — was zu tun ist

> **Stand: 2026-09-26.** Ziel-Soll-Zustand für `reisinger.pictures`.
> Diese Datei ist die **einzige** Anleitung für den Produktions-Deploy.
>
> Architektur: `features/infrastructure/19-ftp-upload-pipeline.md`
> Board: `AGENTS.todo.md` (P1-M21…M36)

---

## Kurzfassung

| Schritt | Wer | Aufwand |
|---|---|---|
| 1. Env-Variablen setzen | Owner | 5 Minuten |
| 2. **Firewall: 3 Ports öffnen** | Owner, SSH | **2 Minuten** |
| 3. Backup + Restore-Test | Owner, SSH | 10 Minuten |
| 4. `docker compose up -d` | Owner, SSH | 3 Minuten |
| 5. Kamera testen | Fotograf | 20 Minuten |
| 6. `pure-ftpd` abschalten | Owner, SSH | 1 Minute |

**Es gibt keinen Hand-Schritt für Ordner, Rechte oder Passwörter.** Das erledigt
der Stack selbst (Schritt 4).

---

## 1. Env-Variablen (Portainer-Stack-Env)

Nicht ins Repo — dieselbe Fehlerklasse wie die Security-Historie C1–C4.

```bash
SFTPGO_DEFAULT_ADMIN_USERNAME=<admin-user>
SFTPGO_DEFAULT_ADMIN_PASSWORD=<admin-passwort>
SFTPGO_BASE_URL=http://sftpgo:8080
SFTPGO_API_KEY=<api-key>
```

`SFTPGO_DEFAULT_ADMIN_USERNAME` und `_PASSWORD` sind im Compose **Pflicht**
(`${VAR:?...}`) — fehlen sie, startet der Stack absichtlich nicht, statt mit
einem unbenutzbaren Dienst zu laufen.

---

## 2. Firewall — die einzige Handarbeit

Drei Regeln öffnen, eine bleibt **geschlossen**:

```bash
ufw allow 2222:2222/tcp      comment 'SFTPGo SFTP'
ufw allow 989:989/tcp        comment 'SFTPGo FTPS'
ufw allow 50000:50100/tcp    comment 'SFTPGo passive FTPS'

# 8080/tcp BLEIBT ZU. Nicht öffnen.
```

| Port | Warum | Wenn fehlt |
|---|---|---|
| **2222** | SFTP (SSH) | Kamera erreicht den Server nicht |
| **989** | FTPS, Control-Port | Kamera erreicht den Server nicht |
| **50000–50100** | passiver FTPS-Datenkanal | **Handshake ok, Upload bricht danach lautlos ab** |
| ~~8080~~ | Admin-API | **Nicht öffnen** — das ist die Kontoverwaltung im Internet |

Die passive Range ist der unangenehme Fall: TLS baut vollständig auf, der
Upload stirbt danach ohne sichtbare Fehlermeldung. Wenn ein FTPS-Upload ohne
Fehler abbricht, ist das die erste Verdachtsstelle.

**8080 bleibt zu**, weil der Backend die Admin-API intern über
`http://sftpgo:8080` erreicht. Öffentlich wäre sie eine Kontoverwaltungs-
Oberfläche im Internet — darüber werden Konten angelegt und Passwörter gesetzt.

### Warum das nicht automatisiert ist

Ein Container kann die Host-Firewall nur mit `host`-Netzwerk plus `NET_ADMIN`
ändern. Das ist ein privileged Container, der bei Fehlkonfiguration bestehende
Regeln überschreibt statt nur zu ergänzen. Drei einmalige Zeilen stehen in
keinem Verhältnis zu diesem Risiko.

### Prüfen (von einem **anderen** Rechner)

```bash
nc -zv reisinger.pictures 2222    # muss succeed
nc -zv reisinger.pictures 989     # must succeed
nc -zv reisinger.pictures 8080    # MUSS fehlschlagen
```

Lokal im Container ist der Test wertlos — dort ist der Port immer offen.

---

## 3. Backup vor dem Cutover

```bash
# 1. Backup des gesamten ftp-Baums
tar czf /root/ftp-backup-$(date +%Y%m%d-%H%M%S).tar.gz -C /home/webadmin/websites ftp

# 2. RESTORE TESTEN — nicht nur prüfen, dass ein Backup existiert
mkdir -p /tmp/ftp-restore-test && tar xzf <das-backup> -C /tmp/ftp-restore-test
ls /tmp/ftp-restore-test/ftp/          # muss die Fotografenordner zeigen
rm -rf /tmp/ftp-restore-test
```

**Warum der Restore-Test:** die Fotos auf `/home/webadmin/websites/ftp` sind der
**einzige** Ort, an dem die Originale liegen. `pure-ftpd` ist abgeschaltet, es
gibt keinen zweiten Stack, der den Fehler rückgängig macht. Ein Backup, dessen
Restore nie geprüft wurde, ist eine Vermutung.

---

## 4. Deploy

```bash
cd /data/compose/15/       # Portainer-Stack-Verzeichnis
docker compose up -d
```

Der `backend`-Entrypoint erledigt beim Start **automatisch**, ohne Zutun:

- Migration (`V041` Provisionierungsstatus, `V042` Reset-Audit)
- `php artisan ftp:provision-folders --fix-permissions` — legt `ftp/<slug>` für
  jeden Fotografen an und setzt das **setgid-Bit**. Ohne setgid landet ein
  Upload in der Gruppe des SFTPGo-Prozesses statt in `webgroup`, und der Import
  kann die Datei nicht lesen.
- `validate-production-env` — bricht ab, wenn ein Pflichtwert fehlt

### Nachkontrollieren

```bash
docker compose logs backend | grep ftp:provision   # Ordner-Anlage
docker compose logs sftpgo | grep -iE "error|fatal"
docker compose exec sftpgo sh -c 'id'             # muss uid=1000 sein
```

### Health

```bash
curl -sS -o /dev/null -w '%{http_code}\n' https://api-portal.reisinger.pictures/up
```

---

## 5. Kamera testen

Erst **hier** darf `pure-ftpd` abgeschaltet werden. Wer vorher abschaltet,
schaltet den einzigen funktionierenden Uploadweg ab, bevor jemand geprüft hat,
dass der neue funktioniert.

| Protokoll | Einstellungen in der Kamera |
|---|---|
| **SFTP** | `ftp_slug`@`reisinger.pictures`, Port 2222, Passwort aus dem Portal |
| **FTPS** | `ftp_slug`@`reisinger.pictures`, Port 989, **explizites TLS**, TLS 1.2 |

Das Passwort steht **einmal** im Portal, wenn das Konto provisioniert wird. Es
wird nirgends gespeichert — verloren bedeutet Reset (P1-M33).

**Passwort-Constraint:** nur Kleinbuchstaben und Ziffern, 16–24 Zeichen. Kameras
nehmen auf dem Konfigurationsbildschirm keine Sonderzeichen an.

Beide Uploads müssen funktionieren. Danach im Portal: Galerie wählen →
`process()` → Foto prüfen.

---

## 6. `pure-ftpd` abschalten

**Erst** wenn ein echter Kamera-Upload vollständig durchgelaufen ist.

```bash
docker compose down pureftp
```

Rollback-Fenster: Solange der erste echte Import nicht durch ist, **beide**
Zugänge parallel lassen. Der alte Zugang ist der Fallback, falls SFTPGo sich im
Betrieb doch anders verhält als im Test.

---

## Fehlersuche

| Symptom | Ursache | Lösung |
|---|---|---|
| Upload bricht ohne Fehler ab | passive Range zu | `ufw status` prüfen — 50000–50100 |
| `Connection refused` auf 2222/989 | Port-Mapping oder Firewall | beide prüfen |
| Kamera verlangt Passwort neu | Provisionierung nicht gelaufen | Portal-Status prüfen (M26-Spalten) |
| `no such table: schema_version` | `sftpgo_data`-Volume nicht persistiert | Volume prüfen, sonst verliert jeder Neustart alle Konten |
| `401` auf `/api/v2/token` | Admin-User fehlt | `SFTPGO_DATA_PROVIDER__CREATE_DEFAULT_ADMIN=true` prüfen |
| Datei liegt da, Import sieht sie nicht | setgid fehlt | `ftp:provision-folders --fix-permissions` |
| `FTP_STORAGE_PATH` relativer Fehler | Config-Fehler | muss absolut sein, z.B. `/home/webadmin/websites/ftp` |

### Messfalle bei Cipher-Tests

Ein nicht erreichbarer Dienst liefert `SSL handshake has read 0 bytes` und eine
**leere** Cipher-Liste. Das ist **kein** Befund über die verfügbaren Ciphers.
Vor dem Auslesen prüfen, ob der Handshake überhaupt abgeschlossen wurde.

Bekannt: Go `crypto/tls` bietet `AES128-SHA` **nicht** an. Falls eine Kamera
das braucht, muss es über `FTPD_CIPHER_SUITES` explizit aktiviert werden.

---

## Nicht tun

- **`chown -R` auf `/home/webadmin/websites`.** Ein FTP-Stack-Start hat damit
  einmal die Ownership von **38.969 Dateien** umgeschrieben
  (2026-09-26, 1002:webgroup → UID 1000).
- **`adduser -h DIR` auf bestehende Pfade.** BusyBox chownt das Home selbst,
  auch ohne `-R`.
- **`pure-ftpd` abschalten, bevor die Kamera bestätigt hat.**
- **8080/tcp öffnen.**
- **`-f` beim `docker compose down`.** Das entfernt auch die Volumes.
