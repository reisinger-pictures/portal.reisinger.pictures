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

Beim Deploy über den **Web-Editor** schreibt Portainer `stack.env` selbst aus dem
GUI-Environment. Deshalb steht im Compose **kein `env_file`** und **kein
`${VAR:?}`-Guard**: Portainer löst die Interpolation ab, bevor die GUI-Umgebung
angewendet ist, ein Guard bricht jeden Deploy ab.

**Ein Wertepaar, nicht zwei.** SFTPGo liest eigene Bootstrap-Namen
(`SFTPGO_DEFAULT_ADMIN_*`); gepflegt wird nur das Paar aus `.env.production`,
das `backend/config/services.php` ohnehin liest. Das Compose mapt es:

```yaml
- SFTPGO_DEFAULT_ADMIN_USERNAME=${SFTPGO_ADMIN_USERNAME}   # sftpgo-Service
- SFTPGO_ADMIN_USERNAME=${SFTPGO_ADMIN_USERNAME:-}        # backend, JWT-Fallback
```

In die GUI gehören also nur die beiden neuen Zeilen aus `.env.production`:

```bash
SFTPGO_ADMIN_USERNAME=sftpgo-admin
SFTPGO_ADMIN_PASSWORD=<generiertes Passwort>
```

Fehlen sie, startet SFTPGo nicht und schreibt `no admins found, try to create
the default one` ins Log — sichtbar, nicht still.

`FTP_UPLOAD_PATH` ist die einzige **optionale** Variable in diesem Block: sie bestimmt
den Zielordner, den die Kamera beschreibt. `/` (Konto-Wurzel) ist der richtige Wert,
solange eine Zielgalerie alles nimmt, deshalb steht sie im Compose mit Default
(`- FTP_UPLOAD_PATH=${FTP_UPLOAD_PATH:-/}`) — fehlt sie in der GUI, bleibt der Upload
importierbar. Ein Unterordner wie `/shoots/2026-09` muss **identisch** im UI und in der
Kamera stehen, sonst schreibt die Kamera in einen Ordner, den der Import nie liest.

### Einmalige Host-Voraussetzung: Fotospeicher

Der Backend läuft als `1000:1000`, der Fotospeicher gehörte `33:33` mit `755`.
Ohne diesen Schritt verweigert der fail-closed Guard den Start mit
`PHOTO_STORAGE_PATH ist fuer UID 1000 nicht schreibbar`:

```bash
chown -R 1000:1000 /home/webadmin/portal/images
find /home/webadmin/portal/images -type d -exec chmod 2775 {} +
```

**Nicht** `/home/webadmin/websites` anfassen: das gehört `1002:webgroup` und
wird absichtlich nicht umgeschrieben (`19-ftp-upload-pipeline.md` 7.9).

### Gegenprobe vor jedem Deploy

```bash
docker compose -f docker-compose.yml config --format json | grep -c '\$\$('   # muss 0 sein
bash tests/infrastructure/compose-entrypoint-contract.sh                       # muss PASS sein
```

---

## 2. Firewall — die einzige Handarbeit

Drei Regeln öffnen, eine bleibt **geschlossen**.

**Auf diesem Host ist `ufw` nicht installiert** — die Firewall ist **firewalld**
(`inet firewalld`, Zone `public` auf `eth0`). Die früheren `ufw allow`-Zeilen
dieser Datei sind daher falsch und werden hiermit ersetzt:

```bash
# dauerhaft (--permanent) und dann erst aktivieren, sonst wirkt es erst beim
# nächsten Reload und der Port ist bis dahin weiter zu
firewall-cmd --permanent --zone=public --add-port=2222/tcp
firewall-cmd --permanent --zone=public --add-port=989/tcp
firewall-cmd --permanent --zone=public --add-port=50000-50100/tcp
firewall-cmd --reload

# Gegenprobe — 2222 und 989 müssen auftauchen, 8080 darf nicht
firewall-cmd --zone=public --list-ports

# 8080/tcp BLEIBT ZU. Nicht öffnen.
```

**Stand 2026-09-26:** in der Zone `public` steht bislang nur
`2222/tcp 10000-10600/tcp`. **989 und die passive Range fehlen**, FTPS ist also
von außen noch nicht erreichbar. 2222 ist bereits offen.

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

### Sync und der Container-Neustart (Pflicht)

`sync.sh` kopiert Code, nichts mehr. **Nach jedem Sync mit PHP-Änderungen
muss `portal_backend` neu gestartet werden:**

```bash
./sync.sh
ssh root@reisinger.pictures 'docker restart portal_backend'
```

Grund: PHP kompiliert Klassen und hält das Ergebnis im Prozess. Ein rsync
aktualisiert die Dateien, der laufende Prozess behält aber die alten Klassen
im Speicher — das Backend führt dann teils den neuen, teils den alten Code
aus, und das sieht aus wie ein inkonsistenter Zustand, nicht wie ein
Caching-Problem. Steht als STRICT-Regel in `AGENTS.md` §13.

Der Frontend-Sync braucht keinen Neustart (`dist/` ist statisch), und
Migrationen laufen nicht mit — die gehören bewusst und getrennt dazu.



```bash
cd /data/compose/15/       # Portainer-Stack-Verzeichnis
docker compose up -d
```

Der `backend`-Entrypoint erledigt beim Start **automatisch**, ohne Zutun.
Vollständig, in Ausführungsreihenfolge (`deployment/docker-compose.yml:246-272`) —
die Liste vorher enthielt nur 3 Einträge und ließ unter anderem den Seed weg:

```bash
# reproduziert die vollständige Schrittliste
awk 'NR>=263 && NR<=272' deployment/docker-compose.yml \
  | grep -oE 'php artisan [a-z:-]+|/usr/local/bin/[a-z-]+' | sort -u
```

0. **Fail-closed-Vorprüfung** — 17 `exit 1`-Guards (`docker-compose.yml:246-262`),
   davon 12 über `printenv | grep`: UID **und** GID 1000, `APP_ENV=production`,
   `APP_KEY`, `JWT_SECRET`, `FILE_ENCRYPTION_KEY`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`,
   `PHOTO_STORAGE_PATH` (gesetzt, absolut, existierendes beschreibbares
   Verzeichnis), `AI_SESSION_HEADER`/`AI_SESSION_PREFIX` (Zeichensatz),
   Beschreibbarkeit von `/var/www/html` und `/var/www/ftp` sowie das Vorhandensein
   von `validate-production-env` und `portal-backend-supervisor` im Image.
   Fehlt **ein** Wert, startet der Container gar nicht.
1. `validate-production-env` — bricht ab, wenn ein Pflichtwert fehlt
2. `php artisan cache:clear` — leert den Anwendungs-Cache
3. `php artisan optimize` — baut Config-/Route-/Event-Caches
4. `php artisan ops:validate-production` — Betriebs-Vorprüfung
5. `php artisan migrate --force` — **unbedingt**, jede offene Migration.
   Stand 2026-09-28: 45 Migrationen, zuletzt `V045` (Geld-Felder in Cent)
   (`ls backend/database/migrations/*.php | wc -l`)
6. `php artisan app:seed-if-fresh` — **nur auf einer noch nie geseedeten
   Datenbank**, siehe unten
7. `php artisan admin:update` — **unbedingt**: legt den Bootstrap-Admin aus
   `ADMIN_EMAIL`/`ADMIN_PASSWORD` an bzw. rotiert sein Passwort
8. `php artisan ftp:provision-folders --fix-permissions` — legt `ftp/<slug>` für
   jeden Fotografen an und setzt das **setgid-Bit**. Ohne setgid landet ein
   Upload in der Gruppe des SFTPGo-Prozesses statt in `webgroup`, und der Import
   kann die Datei nicht lesen.
9. `php artisan scout:sync-index-settings` — Index-Einstellungen mit Meilisearch
   synchronisieren
10. `php artisan queue:restart` — wartende Queue-Worker beenden, damit sie den
    neuen Code laden

Danach `exec /usr/local/bin/portal-backend-supervisor` — das ist kein weiterer
Seiteneffekt, sondern die Übergabe des Prozesses an den Supervisor. Jeder der
Schritte 1-10 bricht den Start bei Fehler ab (`|| exit 1`); Schritt 6 ist eine
`&&`-Kette mit 5 und 7.

#### Der Seed ist bedingt (Owner-Entscheidung 2026-09-28)

Bis 2026-09-28 stand hier `php artisan db:seed --force` — **unbedingt bei jedem
Start**. Der `DatabaseSeeder` ist für die 28 von ihm deklarierten `settings`-Keys
(`price_*`, `mult_*`, `term_*`, `calc_*`, `base_price`, `setup_fee`,
`privacy_fee`, `extra_image_fee`, `bank_*`, `company_*`) autoritativ und
überschreibt sie per `upsert`. Da `AGENTS.md` §13 nach **jedem** Sync mit
PHP-Änderungen `docker restart portal_backend` vorschreibt, überschrieb jeder
vorgeschriebene Neustart diese 28 Produktions-Keys — ohne dass ein Mensch den
Seed ausgelöst hätte, und damit auch außerhalb der Reichweite der Warnung
„vor `db:seed` in Produktion prüfen" in `backend/AGENTS.md`. Bei einem echten
Deploy am 2026-09-28 entdeckt.

`php artisan app:seed-if-fresh` behält die Automatik für die Erstinstallation
und lässt die laufende Produktion unangetastet: geseedet wird nur, wenn die
`users`-Tabelle leer ist (ohne Seed gibt es keinen Admin, also ist der Login tot
— `backend/AGENTS.md`, Database Setup Policy). Eine leere `settings`-Tabelle ist
kein brauchbares Signal, weil Migrationen dort bereits Zeilen anlegen (V004
`base_price`/`term_*`, V005 die Bank-Keys). Ein Neustart auf einer geseedeten
Datenbank ist damit ein No-op.

Ein Seed wird bewusst nur noch manuell ausgelöst, mit der Vorprüfung aus
`backend/AGENTS.md`:

```bash
docker compose exec backend php artisan db:seed --force
```

Falls ein Seed nach dem Anlegen des Admins abgebrochen ist, überspringt
`app:seed-if-fresh` die Datenbank beim nächsten Start (die `users`-Tabelle ist
dann nicht mehr leer). Abhilfe ist dasselbe manuelle Kommando — der Seeder ist
idempotent.

### Nachkontrollieren

```bash
docker compose logs backend | grep seed-if-fresh     # "Fresh database seeded." oder "already seeded"
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

→ **Anleitung: [`kamera-einrichtung.md`](kamera-einrichtung.md)** — Canon EOS R1 /
R6 Mark II, Menüpfade aus dem Herstellerhandbuch, alle einzutragenden Werte, und die
drei stillen Fehlerquellen (Dateiname `ROOT.PEM`, `Verzeichnisstruktur: Standard`,
Konto-Zustand `pending`).

Kurzfassung: der Test ist der einzige offene Cutover-Schritt (P1-M32). Zuerst SFTP
probieren — ein Port, kein Zertifikat, keine passive Range. Nur wenn die Kamera SFTP
nicht spricht, FTPS mit dem importierten Stammzertifikat.

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
