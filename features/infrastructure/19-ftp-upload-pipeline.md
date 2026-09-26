# FTP Upload Pipeline — Architecture

> **Status:** Soll-Zustand.
> Describes the complete FTP upload pipeline: watch → parse → import → cleanup.
> References: `features/infrastructure/13-ftp-brand-isolation.md`.
> Account-Provisioning und Transport (SFTPGo): Abschnitt 7.

## 1. Pipeline Overview

```
Photographer uploads JPG files via FTP
  → Files land in ftp_inbox/{user_slug}/
  → Photographer sets target gallery via API
  → Photographer triggers process() via API
  → Files are moved to photos/{gallery_id}/, metadata extracted
  → FTP inbox files are deleted
  → Photos are available immediately (thumbnails are lazy-generated on first request)
```

## 2. File Structure on FTP Server

Two storage disks are involved:

### 2.1 FTP Inbox (`ftp_inbox` disk)

- **Driver:** `local`
- **Root:** `env('FTP_STORAGE_PATH')`, defaults to `base_path('../ftp')`
- **Structure:**
  ```
  {ftp_storage_path}/
    {user_ftp_slug}/
      image001.jpg
      event_photo_02.jpeg
      ...
  ```
- Each authenticated photographer has a dedicated directory named by their `ftp_slug` (falls back to `user.id`).
- The FTP server (external) is configured to write incoming files into the correct user directory.
- **Transport:** externer Dienst, bis 2026-09-26 `pure-ftpd`, Ziel ist
  **SFTPGo** (Abschnitt 7.1). Auf diesem Pfad ändert sich für die Disks
  nichts.
- Details zu Transport und Provisioning: **Abschnitt 7**. Dort ist auch
  festgehalten, dass `FtpController` und `FtpImportTest` unverändert bleiben.

### 2.2 Photo Storage (`photos` disk)

- **Driver:** `local`
- **Root:** `env('PHOTO_STORAGE_PATH')`, defaults to `base_path('../photos')`
- **Structure:**
  ```
  {photo_storage_path}/
    {gallery_id}/
      {uuid}.jpg           ← renamed from original filename
      _thumbs/
        {size}/
          {photo_id}.webp  ← lazy-generated on first access
  ```

## 3. Controller Endpoints

All endpoints are under `auth:api` + `management` middleware.

### 3.1 `GET /api/management/ftp/status`

Returns:
- `ftp_folder`: The user's inbox directory name (`/` + `ftp_slug` or `id`).
- `file_count`: Count of `.jpg`/`.jpeg`/`.JPG`/`.JPEG` files in the inbox.
- `current_target_gallery`: Currently selected gallery (with loaded relation).

### 3.2 `POST /api/management/ftp/target`

Sets the target gallery for the next import.

- **Input:** `gallery_id` (nullable string, must exist in `galleries` table).
- **Brand isolation** (`features/infrastructure/13-ftp-brand-isolation.md`): If `gallery_id` is provided, it must be in the user's `getAllowedGalleryIds()` — otherwise 403.
- If `gallery_id` is null, clears the target (photographer must set one before process).
- Only photographers (`is_photographer=true`) can set a non-null target.

### 3.3 `POST /api/management/ftp/process`

Triggers the import pipeline.

**Pre-flight checks:**
1. User must be `is_photographer` — otherwise 403.
2. `current_ftp_gallery_id` must be set — otherwise 400.
3. Defense-in-depth: `current_ftp_gallery_id` must be in `getAllowedGalleryIds()` — otherwise 403.

**Processing loop:**
1. Enumerate all `*.{jpg,jpeg,JPG,JPEG}` files in the user's inbox.
2. For each file:
   - Strip original filename, keep original base name as metadata fallback.
   - **Rename to UUID:** Generate `Str::uuid()` — the original filename is only preserved as `photo.title` (if no better title is extracted).
   - Copy file to `photos/{gallery_id}/{uuid}.{ext}`.
   - **Delete** original from inbox (files are moved, not copied — after successful copy the inbox copy is unlinked).
   - Generate thumbnail metadata path (`_thumbs/md5({filename}1024).webp`) — but actual thumbnail generation is **lazy** (see §3.3.1).
3. `PhotoProcessingService::processImage()` extracts metadata via ExifTool and applies gallery defaults.

**Photo database record:**
- `id`: UUID
- `gallery_id`: Target gallery
- `lr_uuid`: `'ftp-' . uniqid()` (marks photo as FTP-imported, not from Lightroom)
- `user_id`: Uploading photographer
- `title`, `description`, `keywords`, `location`, `city`, `state`, `country`, `iso_country`, `captured_at`: From ExifTool extraction, falling back to gallery defaults
- `width`, `height`: From `getimagesize()`

#### 3.3.1 Lazy Thumbnail Generation

Thumbnails are NOT generated during FTP import. The old thumbnail generation in `PhotoProcessingService` was removed. Thumbnails are created on-the-fly by `FileDeliveryController::serve()` when a `_thumbs/{size}/{photoId}.webp` URL is requested (see `features/delivery/03-file-delivery-controller.md`).

## 4. Brand Isolation

See `features/infrastructure/13-ftp-brand-isolation.md` for full details. Summary:

- **`setTarget()`**: Rejects galleries not in `getAllowedGalleryIds()` (403).
- **`process()`**: Re-validates `current_ftp_gallery_id` against `getAllowedGalleryIds()` (defense-in-depth).
- Frontend displays gallery brand as a daisyUI `badge` in the target selector.

## 5. Gallery Auto-Assignment Logic

Gallery assignment is purely explicit via `setTarget()`:

1. Photographer selects a gallery from the frontend dropdown (which shows only allowed galleries, filtered by brand).
2. `setTarget()` persists `user.current_ftp_gallery_id`.
3. `process()` reads this value to determine the target.
4. There is no automatic gallery assignment based on filename, folder, or metadata — the photographer must always explicitly select the target.

## 6. Error Handling & Retry

| Scenario | Behavior |
|---|---|
| Inbox directory missing | `process()` returns `{processed: 0}` — no error |
| No image files in inbox | Returns `{processed: 0}` |
| File copy fails | Exception propagates → `process()` returns HTTP 500. Transaction per file (not wrapped in global transaction) — already-copied files remain imported. |
| Metadata extraction fails | `PhotoProcessingService` falls back to gallery defaults. The photo is still created. |
| Storage disk full | PHP file operations throw — HTTP 500. Admin must free space. |
| Concurrent process calls | No locking — duplicate processing of same files is possible. Design assumes single-user access. |
| Gallery deleted between setTarget and process | `Gallery::find()` returns null in process() loop — error may occur. Current code uses `Gallery::find()` after the check. |
| Brand isolation violation | 403 response — the user is informed before any import occurs. |

## 7. Account Provisioning & Transport (SFTPGo)

> Erweitert 2026-09-26. Kameras laden per FTPS/SFTP in `ftp_inbox/{ftp_slug}/`;
> der Dateitransport ist ein **externer Dienst** und nicht Teil des Portals.
> Verbindlicher Soll-Zustand, in Umsetzung.
>
> **Verknüpfte Tasks:** P1-M21 bis P1-M32 in `AGENTS.todo.md`.
> Infrastruktur-Seite: `~/dev/strato-vps/ANALYSIS.md` Abschnitt 6e.

### 7.1 Ausgangslage und Bewegungsgründe

Bis 2026-09-26 war der externe Dienst `pure-ftpd` mit **einem** pauschalen
User (`webadmin`), dessen Passwort fest in einer Stack-ENV-Datei lag und dessen
Home auf den **gesamten** Website-Baum zeigte. Nicht haltbar aus zwei Gründen:

1. **Blast Radius.** Wer das Passwort hat, kann in jede Site schreiben —
   inklusive `api-portal.reisinger.pictures` und des Portal-Codes.
2. **Keine Verwaltung durch die Applikation.** `pure-ftpd` liest seine
   User-Datenbank **einmal beim Start**. Jedes neue Konto und jede Rotation
   erfordert einen Container-Neustart.

Ein dritter Grund kam bei der Prüfung hinzu: `pure-ftpd` kann `AES128-SHA`
**nicht anbieten** (verifiziert — die Cipher-Liste wird intern gebaut, die
`-C`-Bits sind *Verbote*, `OPENSSL_CONF` wird ignoriert). Kameras, die CBC/SHA1
brauchen, erreichen den Server damit nicht, und das ist nicht konfigurierbar.

**Der laufende Betrieb ist Teil des Problems.** `pure-ftpd` ist kein Altbestand,
sondern derzeit der einzige Weg, auf dem die Fotos hereinkommen. SFTPGo ersetzt
ihn; der Wechsel ist ein Schnitt mit laufendem Betrieb, kein Greenfield-Aufbau.
Die Reihenfolge in 7.10 und das Runbook in 7.12 sind daraus abgeleitet. Wer die
Ablösung als Parallelsystem plant, plant den Ausfall für den Fotografen.

### 7.2 Ein Account pro Fotograf, Username = `ftp_slug`

- Der FTP-/SFTP-Username ist **`users.ftp_slug`** — bereits vorhanden
  (`V001__initial_portal_schema.php:62`), `unique()` und selbst wählbar
  (`AuthController.php:220-230`).
- **Verbindliche Formatregel** (P1-M21): `^[a-z0-9][a-z0-9_-]{2,31}$`.
  Keine Punkte, kein `@`, kein Slash, max. 32 Zeichen, kleingeschrieben.
  Offene Frage zur Migration bestehender Werte: 7.6.
- Der FTP-Ordner ist `ftp/<ftp_slug>`, konsistent mit `getInboxPath()`
  (`:151-156`).

### 7.3 Passwort-Fluss: erzeugen, anzeigen, verwerfen

**Das Portal speichert kein FTP-Passwort.** Das ist der Kern des Wechsels.

1. PHP erzeugt ein kamerataugliches Passwort `^[a-z0-9]{16,24}$` —
   **keine Sonderzeichen**, da Kameras sie am Konfigurationsbildschirm nicht
   eingeben können; keine gemischte Groß-/Kleinschreibung, um
   Tastatur-Layout-Fehler zu vermeiden.
2. Übergabe per **HTTPS** an die SFTPGo-Admin-API.
3. Anzeige **einmal**, danach verwerfen.
4. Verloren → `resetPassword()`. Es gibt **keine** Wiederherstellung.

Daraus folgt: **keine** `ftp_credentials`-Tabelle, **kein**
`FILE_ENCRYPTION_KEY`/`FILE_ENCRYPTION_PREVIOUS_KEYS` für FTP, **keine**
verschlüsselten Secrets at rest. Der zwischenzeitlich erwogene Entwurf mit
`encrypted`-Cast auf einer `text`-Spalte (Muster `ModelProfile.php:55-62`) ist
**hinfällig**.

*Offen (Produktentscheidung):* dürfen Fotografen ihr Passwort selbst ändern
oder nur der Admin? Ein Selbständerungs-Flow bräuchte einen Confirm-Schritt
mit dem aktuellen Passwort.

### 7.4 Provisionierungsstatus auf `users`

`status()` braucht eine Kontoanzeige, obwohl kein Passwort gespeichert wird.

**Festgelegt: eine Spalte, kein Live-Query gegen SFTPGo.** Ein Live-Query im
Lesepfad würde die UI vom Dienst abhängig machen und widerspricht 7.5; er
bräuchte außerdem ein Credential auch für Read-Operationen und nähme der
Reduktion der Secret-Fläche den Kern.

Migration **V041+** (Reihe endet bei V040; `backend/AGENTS.md` verlangt die
vorab dokumentierte Schema-/Backfill-/Rollback-Entscheidung):

| Spalte | Typ | Bedeutung |
|---|---|---|
| `ftp_account_status` | enum `pending`/`active`/`error` | Kontozustand |
| `ftp_provisioned_at` | timestamp nullable | letzter erfolgreicher Provision |
| `ftp_account_error` | text nullable | Fehlertext für `error` |

- **Backfill:** bestehende Fotografen auf `pending` — der Zustand ist
  unbekannt, sie wurden nie über SFTPGo provisioniert.
- **Rollback:** reines Spalten-Drop, kein Datenverlust.
- Die Spalte ist ein **Cache**, nicht die Wahrheit: wird der User in SFTPGo von
  Hand gelöscht, ist sie veraltet. Deshalb ein expliziter
  `reconcileAccount()`-Pfad statt eines stillen Live-Query.

### 7.5 Der Import darf nicht am Dienst hängen

Dateien, die bereits in `ftp/<slug>` liegen, müssen auch dann importierbar
sein, wenn SFTPGo ausfällt. **`process()` hat keinen SFTPGo-Kontakt.**

- `status()` liefert bei Timeout **keinen** 500er, sondern den zuletzt
  bekannten Stand aus 7.4. Der Fotograf soll sehen, was das System weiß, nicht
  einen Fehler, der nach Datenverlust aussieht.
- Der HTTP-Client bekommt feste Timeouts (`Http::timeout(5)` connect,
  `Http::timeout(15)` total), kein Default-Timeout.

### 7.6 Admin-API-Client

`SftpGoClient` (`app/Services/`) kapselt ausschließlich HTTP gegen die
Admin-API. **Kein** FTP-/SFTP-Protokoll-Speak im Portal.

- Endpunkte `/api/v2/users` (POST anlegen, PUT ändern), Auth über
  `X-SFTPGO-API-KEY` oder JWT aus `POST /api/v2/token`.
- **Schema nicht raten.** Quelle: `GET /openapi` an der laufenden Instanz
  (Swagger UI, im Community-Build aktiv), dann `openapi.yaml` im Repository
  `drakkan/sftpgo`.

### 7.7 Credential-Haltung

- `deployment/docker-compose.yml` ist **versioniert** und enthält
  ausschließlich `${SFTPGO_BASE_URL}` und `${SFTPGO_API_KEY}`. Der Wert gehört
  in die **Portainer-Stack-Env**. Commit nur Platzhalter — dieselbe
  Fehlerklasse wie C1–C4.
- **Lizenz:** SFTPGo ist AGPL-3.0. Betrieben wird das **offizielle,
  unveränderte** Image; damit haften keine Offenlegungspflichten für das
  Portal. Ein selbstgebautes oder gepatchtes Image wäre eine andere
  Rechtslage und ist **untersagt**. (Keine Anwaltsberatung, nur die
  technische Konsequenz aus der Lizenz.)

### 7.8 Was sich bewusst NICHT ändert

SFTPGo schreibt auf **denselben** Host-Pfad `/home/webadmin/websites/ftp`.
Bind-Mount `-> /var/www/ftp` und Disk `ftp_inbox` als `driver=local` bleiben.

- **`FtpController` wird nicht angefasst** — `getInboxPath()`, `setTarget()`,
  die `status()`-Struktur und `process()` bleiben.
- **Kein** `Storage::disk('sftp')` für den Import: Netzwerk-Roundtrip nach
  localhost pro Datei **plus** ein Credential im Portal für den eigenen Host.
- **Getestete Version:** `drakkan/sftpgo:latest` war am 2026-09-26
  `2.7.6-62ae9ba3` (Build 2026-09-18). Seit 2.6 liegt die Konfiguration in
  der **Datenbank**, nicht mehr als JSON-Datei im Config-Verzeichnis — das
  ändert den Seeding-Weg gegenüber der 2.5-Dokumentation. Für den Cutover ist
  ein **gepinntes** Image statt `latest` verbindlich: die Konfigurationsform
  und damit der Startvorgang können sich zwischen Minor-Versionen ändern, und
  ein Rebuild, der den Startpfad verändert, fällt auf einem Server mit
  laufendem Betrieb nicht auf, sondern erst beim Fotografen.
- `FtpImportTest` muss nach dem Wechsel unverändert grün bleiben. P1-M25
  ergänzt einen Test, der festschreibt, dass `ftp_inbox` lokal bleibt.

### 7.9 Ownership-Regeln auf dem Host

Aus dem Vorfall vom 2026-09-26, verbindlich für jeden Prozess, der auf
`/home/webadmin/websites` schreibt.

**Verboten:**

- `chown -R` durch Container-Entrypoints auf `/home/webadmin/websites`. Ein
  FTP-Stack-Start hat damit die Ownership von **38.969 Dateien** von
  `1002:webgroup` auf `1000` umgeschrieben (Container-UID 1000 → Host-UID 1000
  = `r1`).
- `adduser -h DIR` auf bestehende Pfade. BusyBox `adduser -h` chownt das Home
  **selbst**, auch ohne `-R`.

**Soll:**

- SFTPGo läuft als eigener System-User, der auf `1002:webgroup` gemappt wird.
- `ftp/<slug>` ist `1002:webgroup` mit `2777` (setgid), damit neue Dateien die
  Gruppe erben.
- SFTPGo legt virtuelle Ordner **nicht** an (Doku: *"you have to create the
  folder on disk yourself"*). Anlegen und Ownership-setzen ist ein
  Host-seitiger Schritt, getrennt vom User-Provisioning (P1-M24).

### 7.10 Reihenfolge

Die Reihenfolge ist **verifikationszuerst** und nicht implementierungszuerst.
Anlass ist P1-M27: die Cipher-Frage ist die einzige Unbekannte, die die
Grundentscheidung kippen kann. Ein Stapel, der auf einem Protokoll beruht, das
die Kamera nicht spricht, ist nach dem Umschalten funktional tot — egal wie
viel Code darauf liegt.

1. **SFTPGo-Instanz mit gesunder Konfiguration hochfahren** (P1-M35) — Config-
   Verzeichnis, persistenter Datenprovider, Admin-Bootstrap. Ohne das ist jede
   Messung wertlos: ein nicht erreichbarer Dienst liefert eine leere
   Cipher-Liste, die wie ein Befund aussieht.
2. **Cipher-Liste messen** (P1-M27): `openssl s_client -cipher …` gegen die
   laufende Instanz. **Ergebnis schriftlich festhalten**, bevor weitergearbeitet
   wird. Braucht die Kamera einen Cipher, den SFTPGo nicht anbietet, endet der
   Pfad hier — und zwar vor dem Schreiben von Anwendungscode.
3. **Kameraneukonfiguration verifizieren** (P1-M32) — mit echter Kamera, nicht
   mit einem Desktop-Client. Solange die Kamera nicht bestätigt hat, wird
   `pure-ftpd` **nicht** angefasst.
4. Formatregel für `ftp_slug` (P1-M21).
5. `ftp_account_status`-Spalten (P1-M30).
6. `SftpGoClient` + Passwort-Fluss (P1-M22, P1-M23, P1-M31).
7. Ordner-Anlage auf dem Host (P1-M24).
8. Firewall, Berechtigungen, Host-Umgebung (`strato-vps` 6e).
9. **Cutover** nach §7.12 — inklusive Sicherung und Rollback-Fenster.
10. Erst dann `pure-ftpd` stilllegen.

Schritt 3 darf nicht übersprungen und nicht nach hinten verschoben werden: Wird
SFTPGo eingerichtet und niemand stellt die Kamera um, ist nach dem Umschalten
alles gleichzeitig still — und der Import läuft scheinbar weiter, weil die
Dateien noch auf der Platte liegen. Der Ausfall ist damit leicht zu übersehen.
Deshalb ist der Reihenfolgewechsel gegenüber dem Stand vom 2026-09-26 (der
Kameraschritt stand dort an Position 6 von 7) **eine bewusste Korrektur**, kein
Versehen.

Schritt 9 ersetzt die frühere Formulierung „Umschalten“ als einzelner Schritt:
siehe §7.12, denn der Betrieb läuft während des Wechsels weiter.

### 7.11 Offene Punkte

**Kamera-Protokoll (blockiert die Abnahme).** Ob die konkrete Kamera FTPS mit
GCM oder CBC/SHA1 braucht, ist **ungeklärt**. Testmethode: Cipher-Liste des
laufenden SFTPGo mit `openssl s_client -cipher …` prüfen, dann mit echter
Kamera. Unterstützt die Kamera SFTP, ist SFTP vorzuziehen — moderne Ciphers,
und SFTPGo bietet FTPS und SFTP auf demselben Port. Der User `florian` unter
`pure-ftpd` gilt bis dahin als **Betriebs-Workaround, kein Beweis**.

**Stand 2026-09-26: die Messung steht noch aus.** Sie ist nicht gescheitert,
sondern dreimal an der Konfiguration der Messinstanz (P1-M35). Belegt ist:
SFTPGo 2.7.6-62ae9ba3; das Image meldet `config file used:
"/etc/sftpgo/sftpgo.json"`, während `--config-dir` auf `.` defaultet; FTPS
Bindings liegen unter `ftpserver.bindings[].port`. **Warnung für die
Messung:** ein nicht erreichbarer Dienst liefert `SSL handshake has read 0
bytes` und eine leere Cipher-Liste. Das ist **kein** Befund über die
verfügbaren Ciphers und darf nicht als „Cipher nicht angeboten" protokolliert
werden. Vor der Messung Config-Weg und Datenprovider-Persistenz klären.

Über die TLS-Seite hinaus ist offen, ob die Kamera überhaupt Authentifizierung
über die SFTPGo-User-Datenbank akzeptiert — der Benutzer existiert dort erst ab
Provisionierung (M22), während die Kamera ihn vorher konfigurieren muss. Der
Cutover (7.12) behandelt das als Reihenfolgeproblem, nicht als Detail.

**Bestehende `ftp_slug`-Werte.** `Str::slug()` lässt Punkte zu, ein Localpart
wie `j.doe` wird zu `j.doe`; die neue Regel verbietet das. Eine Umbenennung ist
keine Kosmetik, weil `ftp_slug` Fremdschlüssel für `storage/app/private`-Pfade
ist. Zu entscheiden: Altslugs automatisch normalisieren (mit Folge für die
Ordnerstruktur) oder den Fotografen wählen lassen, mit Fehlerpfad bis dahin.

**Brand-Scope.** `ftp_slug` ist user-level, nicht brand-level
(`25-brand-separation-matrix.md:33`). Der `Brand`-Enum hat aktuell genau einen
Fall (`app/Enums/Brand.php:12-15`), das Schema ist faktisch Single-Tenant. Für
einen zweiten Brand braucht es eine Trennung des Folder-Namespaces, sonst sieht
ein Fotograf die Ordner einer anderen Marke. Bewusst nicht vorgebaut, aber
dokumentiert (P1-M29).

**Concurrency im Import.** Siehe Abschnitt 6: `process()` hat keinen Lock. Mit
mehreren Fotografen ist die Annahme "single-user access" eine Fehlerquelle
(P1-M28).

### 7.12 Cutover-Runbook

`pure-ftpd` läuft heute und bedient einen Fotografen. SFTPGo **ersetzt** diesen
Server; es gibt keinen Parallelbetrieb als dauerhafte Lösung. Der Datenbestand
auf `/home/webadmin/websites/ftp` ist dabei der einzige Ort, an dem die
ungesicherten Originalfotos liegen — ein Fehler im Schnitt ist durch einen
zweiten Stack **nicht** reversibel, solange die Dateien nur einmal existieren.

**Reihenfolge, bindend:**

1. **Sicherung vor allem anderen.** Kompletter `ftp`-Baum inkl. Eigentümer und
   Rechten (`1002:webgroup`, `2775`/setgid). Nachweis, dass ein **Restore
   geprüft** wurde, nicht nur, dass ein Backup existiert.
2. **Konfiguration verifiziert** (P1-M35), **Cipher-Liste gemessen und
   schriftlich festgehalten** (P1-M27).
3. **Kamera bestätigt den neuen Zugang** (P1-M32) — mit der echten Kamera und
   einem echten Upload. Ein erfolgreicher Desktop-Client beweist nichts.
4. **Erst jetzt** Konto in SFTPGo provisionieren und den alten Zugang am
   Fotografen widerrufen. `pure-ftpd` läuft bis hierher **unverändert weiter**.
5. **Rollback-Fenster:** beide Zugänge bleiben parallel bestehen, bis mit der
   Kamera **mindestens ein** Import vollständig durchgelaufen ist. Erst danach
   `pure-ftpd` stilllegen.
6. **Nach dem ersten echten Import:** prüfen, dass Dateien mit korrekter
   Ownership und Gruppenrechten ankommen, und dass der `setgid`-Bit auf
   `ftp/<slug>` gehalten hat (neue Dateien müssen die Gruppe erben, sonst
   scheitert der Import nach dem ersten Upload).

**Ausdrücklich verboten:**

- `pure-ftpd` vor Schritt 3 oder 4 stillzulegen.
- Backup und Restore in einem Schritt zu erledigen.
- Den Cutover mit einem Windows- oder macOS-Client zu testen.
- Nach dem Schnitt Ownership-Fixes per `chown -R` auf `/home/webadmin/websites`
  (siehe 7.9).
