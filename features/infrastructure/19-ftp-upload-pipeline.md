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

#### 3.3.2 Concurrency Guard

`process()` is a read → copy → Photo row → unlink sequence over a directory the
FTP server writes into at the same time. Two runs can each hold their own
`glob()` snapshot of the same file; without a guard the loser of the unlink race
would still have produced a second `Photo` row and a second stored file under a
different UUID. The pipeline is therefore serialized per photographer.

- **Lock:** `Cache::lock('ftp-process:{user_id}', 300)`, acquired **only** once
  the inbox actually holds images, and **non-blocking**. A second call is
  refused with **409** plus a German error message and a `Retry-After` header —
  it is never queued, merged, or silently served. The lock is released in a
  `finally`, so a failed import frees it immediately.
- **Key:** the user id, which is a globally unique UUID. The inbox folder is
  user-level too, so this is the narrowest key that still covers exactly the
  files a run can touch. No brand component: the `Brand` enum has a single case
  and the folder namespace is deliberately not brand-scoped yet (§7.11).
- **Per file:** the `glob()` snapshot is re-checked with `file_exists()` at the
  top of each loop iteration. A file that a competing run claimed in between is
  skipped, not imported a second time.

**What the lock is not.** It is not a lock across a system boundary: the
FTP/SFTP server takes no protocol-level lock, so a file a camera is still
writing can be read half-finished. Keeping uploads and imports apart is the
photographer's workflow, not the guard's job. Its reach is exactly the reach of
the configured cache store — a deployment with a per-node file cache degrades it
to a per-node best effort. It is not a renewed lease: a run that outlives the
TTL loses the guard, and a PHP fatal releases the lock only when the TTL
expires. The TTL is therefore set far above a realistic import, because the
failure mode of a too-short TTL is a duplicated photo, while a too-long TTL only
delays a retry.

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
| Concurrent process calls | Guarded per photographer — see §3.3.2. A second call is refused with 409 and imports nothing. Not a cross-boundary lock: the FTP server keeps writing into the same directory, and the guard's reach is only the configured cache store. |
| File vanishes between the `glob()` snapshot and its loop iteration | Skipped, not imported — a competing run already claimed it (§3.3.2). |
| Gallery deleted between setTarget and process | `Gallery::find()` returns null in process() loop — error may occur. Current code uses `Gallery::find()` after the check. |
| Brand isolation violation | 403 response — the user is informed before any import occurs. |

## 7. Account Provisioning & Transport (SFTPGo)

> Erweitert 2026-09-26. Kameras laden per FTPS/SFTP in `ftp_inbox/{ftp_slug}/`;
> der Dateitransport ist ein **externer Dienst** und nicht Teil des Portals.
> Verbindlicher Soll-Zustand, in Umsetzung.
>
> **Verknüpfte Tasks:** P1-M21 bis P1-M32 in `AGENTS.todo.md`.
> Infrastruktur-Seite: `~/dev/strato-vps/ANALYSIS.md` Abschnitt 6e.

### 7.0 Stack-Zuordnung

**SFTPGo ist ein Service im bestehenden Portal-Compose**
(`deployment/docker-compose.yml`), **kein eigener Stack.** Festgelegt
2026-09-26.

Dafür:

- **Ein `env_file` für den Stack.** `SFTPGO_BASE_URL` und `SFTPGO_API_KEY`
  liegen neben den bestehenden Variablen in der Portainer-Stack-Env; im
  versionierten Compose nur die Platzhalter (7.7).
- **Die Admin-API muss nicht nach außen publiziert werden.** `backend` und
  `sftpgo` hängen an `webnet` und sprechen sich über den Service-Namen an
  (`http://sftpgo:8080`). Nur der Kamera-Port wird auf dem Host veröffentlicht.
  Das ist ein Sicherheitsgewinn gegenüber einem eigenen Stack.
- **Ein `docker compose up -d` für beides**, ein Rollback-Punkt.

Dagegen — bewusst in Kauf genommen:

- **Gekoppelte Lebensdauer.** `up -d` startet beides, `down` stoppt beides.
  Ein defektes SFTPGo-Image blockiert damit den Portal-Deploy. Deshalb: **kein
  `depends_on` vom `backend` auf `sftpgo`**, und der `restart: unless-stopped`
  ist bewusst unabhängig. Der Import darf den Dienst nicht brauchen (7.5) —
  das ist die Voraussetzung dafür, dass die Kopplung nur den Deploy betrifft
  und nicht den Betrieb.
- **Ein geteiltes Datenverzeichnis mit `backend`:** der Bind-Mount
  `/home/webadmin/websites/ftp` (`:75`) wird von beiden genutzt. Die
  Ownership-Regeln in 7.9 gelten damit für beide Seiten.

**Noch zu klären:** das SFTPGo-Datenverzeichnis (Accounts, Zertifikate) wird
ein benanntes Volume im Portal-Namespace. Die bestehende Sicherung
(`/usr/local/bin/volume-backup.sh`) sichert `SRC_WEBSITES` plus eine **feste
Liste benannter Volumes** und würde es **nicht** erfassen. Vor dem ersten
Deploy muss das Volume dort eingetragen werden — Quelltext dafür ist
`~/dev/strato-vps/proposed/backup.sh`. Die Fotos selbst liegen im Bind-Mount
und sind über `SRC_WEBSITES` bereits abgedeckt; ungesichert bliebe nur die
Kontodatenbank.

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
  (`AuthController::updateProfile()`).
- **Verbindliche Formatregel** (P1-M21, umgesetzt 2026-09-26):
  `^[a-z0-9][a-z0-9_-]{2,31}$`. Keine Punkte, kein `@`, kein Slash, max. 32
  Zeichen, kleingeschrieben. **Einzige Quelle:** `App\Support\FtpSlug`
  (`PATTERN`, `MESSAGE`, `normalize()`, `toValidBase()`, `withSuffix()`), benutzt
  vom Schreibpfad und von der Auto-Generierung. Umsetzung und die Entscheidung zu
  Bestandswerten: 7.11.
- Der FTP-Ordner ist `ftp/<ftp_slug>`, konsistent mit `getInboxPath()`
  (`:151-156`).
- **Jeder User hat einen eigenen SFTPGo-Account** mit Home `ftp/<slug>`.
  Kein gemeinsamer User, kein `r1`-User. Die Kamera konfiguriert `/` als
  Subpath und lädt direkt in das Home-Verzeichnis. **Die GUI-Auswahl des
  Subfolders entfällt** — der User wählt keinen Unterordner mehr aus.
- **Physischer Ordner pro User:** Jeder User bekommt einen eigenen physischen
  Ordner auf dem Host (`ftp/<slug>`), auf den er eingeschränkt ist. SFTPGo
  konfiguriert das als `home_dir` — der User sieht nur sein eigenes
  Verzeichnis, nicht die anderer User. Das ist die Isolationsgrenze.
- **Brand-Scope (P1-M29, entschieden 2026-09-26):** Ein SFTPGo-Account pro
  Brand, nicht pro User. Nur Super-Admin verwaltet den Account. Die
  Galerie-Zuordnung (0..1) über `current_ftp_gallery_id` bleibt das
  Zuordnungsinstrument: Ein Fotograf lädt in den Brand-Account, das Portal
  ordnet über `process()` die Galerie zu. Das eliminiert das Problem, dass
  ein Fotograf Ordner eines anderen Brands sehen könnte — er sieht nur den
  Brand-Account.

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

**Umsetzung (P1-M23).** `FtpCredentialService`
(`app/Services/FtpCredentialService.php`) kapselt genau diese vier Schritte;
`provisionAndShow(User $user)` erzeugt, provisioniert und **gibt das Passwort
einmal zurück**. Festgeschrieben ist:

- **Kein Schreibzugriff.** Die Methode schreibt keine Spalte, keine Datei, kein
  Cache-Element. Der Provisionierungsstatus aus 7.4 wird **nicht** von hier
  gepflegt — das ist eine eigene Entscheidung pro Aufrufer (und bis dahin
  bleibt der Cache bewusst `pending`).
- **Kein Konto-Name-Fallback.** Ohne `ftp_slug` wird **nicht** auf die
  Primary-Key-ID zurückgefallen: `FtpController` mag das für den Inbox-Pfad,
  ein Konto namens einer UUID kann aber niemand auf einer Kamera eintippen.
- **Die Regel aus 7.2 wird durchgesetzt**, nicht nur dokumentiert. Ein
  regelwidriger Alt-Slug (`j.doe`) führt zu einem benannten Fehler mit
  Verweis auf die Profilseite, statt still ein unbrauchbares Konto anzulegen.
- **Das Home-Verzeichnis** ist `filesystems.disks.ftp_inbox.root` + Slug, nicht
  eine zweite Konstante: beide Container mounten denselben Host-Pfad auf
  denselben Container-Pfad, also stimmen Portal und Dienst mit einem String
  überein (7.8).
- **Kein Passwort im Log.** Der Client loggt pro Aufruf Operation, Konto,
  Status, Dauer und `body_length`, nie den Body — der Body von
  `provisionUser` **ist** das Passwort. Passwort-Parameter stehen zusätzlich
  unter `#[\SensitiveParameter]`, was sie aus Stacktraces entfernt.

### 7.4 Provisionierungsstatus auf `users`

`status()` braucht eine Kontoanzeige, obwohl kein Passwort gespeichert wird.

**Festgelegt: eine Spalte, kein Live-Query gegen SFTPGo.** Ein Live-Query im
Lesepfad würde die UI vom Dienst abhängig machen und widerspricht 7.5; er
bräuchte außerdem ein Credential auch für Read-Operationen und nähme der
Reduktion der Secret-Fläche den Kern.

Migration **V041** (`V041__add_ftp_account_status_to_users.php`; die Reihe endete
bei V040, und `backend/AGENTS.md` verlangt die vorab dokumentierte
Schema-/Backfill-/Rollback-Entscheidung):

| Spalte | Typ | Bedeutung |
|---|---|---|
| `ftp_account_status` | enum `pending`/`active`/`error` | Kontozustand |
| `ftp_provisioned_at` | timestamp nullable | letzter erfolgreicher Provision |
| `ftp_account_error` | text nullable | Fehlertext für `error` |

- **Backfill:** bestehende Fotografen auf `pending` — der Zustand ist
  unbekannt, sie wurden nie über SFTPGo provisioniert. Der **Spaltendefault ist
  der Backfill**: MySQL/MariaDB, PostgreSQL und SQLite füllen eine neu
  hinzugefügte Spalte aus ihrem Default, ein zusätzliches `UPDATE users ...` wäre
  ein zweiter Volltabellen-Schreibvorgang auf der Produktions-`users` und könnte
  nur denselben Wert erzeugen. `pending` ist zugleich der korrekte Zustand für
  Nicht-Fotografen, die nie ein FTP-Konto bekommen.
- **Kein Index:** die Spalten werden ausschließlich über den Primärschlüssel
  gelesen, ein Index wäre reiner Schreib-Overhead ohne Query, das ihn nutzt.
- **Nicht massenassignierbar:** die drei Spalten stehen bewusst weder in
  `$fillable` noch in `$visible` des `User`-Modells. Sie sind Systemzustand der
  Provisionierung, kein Request-Input, und kein Teil des User-Payloads.
- **Rollback:** reines Spalten-Drop, kein Datenverlust. Die `down()`-Methode ist
  trotz der Repo-Policy implementiert und getestet, weil der Rollback Teil des
  dokumentierten Vertrags ist und ein ungeprüfter Rollback kein Vertrag ist.
- Die Spalte ist ein **Cache**, nicht die Wahrheit: wird der User in SFTPGo von
  Hand gelöscht, ist sie veraltet. Deshalb ein expliziter
  `reconcileAccount()`-Pfad statt eines stillen Live-Query.

### 7.5 Der Import darf nicht am Dienst hängen

Dateien, die bereits in `ftp/<slug>` liegen, müssen auch dann importierbar
sein, wenn SFTPGo ausfällt. **`process()` hat keinen SFTPGo-Kontakt.**

- `status()` liefert bei Timeout **keinen** 500er, sondern den zuletzt
  bekannten Stand aus 7.4. Der Fotograf soll sehen, was das System weiß, nicht
  einen Fehler, der nach Datenverlust aussieht.
- Der HTTP-Client bekommt feste Timeouts, kein Default-Timeout:
  `Http::connectTimeout(5)` für den Connect, `Http::timeout(15)` als **Gesamt**
  budget. **Präzisierung 2026-09-26 (P1-M22):** in Laravel ist `timeout()` das
  Gesamtbudget, der Connect-Budget heißt `connectTimeout()`. Ein
  `timeout(5)->timeout(15)` — wie die frühere Fassung dieses Absatzes es
  nahelegte — setzt nur das Gesamtbudget und **verwirft** den Connect-Wert
  lautlos.

### 7.6 Admin-API-Client

`SftpGoClient` (`app/Services/`) kapselt ausschließlich HTTP gegen die
Admin-API. **Kein** FTP-/SFTP-Protokoll-Speak im Portal.

- Endpunkte `/api/v2/users` (POST anlegen, GET lesen, PUT ändern, DELETE
  löschen), Auth über `X-SFTPGO-API-KEY` oder JWT aus **`GET /api/v2/token`**
  (Basic-Auth des Admin-Users). **Korrigiert 2026-09-26 (P1-M22):** ein `POST`
  auf `/api/v2/token` antwortet **405** — `openapi/openapi.yaml` v2.7.6
  definiert dort `get: security: [BasicAuth]`. Die frühere Fassung dieses
  Absatzes nannte `POST`.
- **Schema nicht raten.** Quelle: `GET /openapi` an der laufenden Instanz
  (Swagger UI, im Community-Build aktiv), dann `openapi.yaml` im Repository
  `drakkan/sftpgo`. Für die Umsetzung (P1-M22) wurde `openapi.yaml` des Tags
  **v2.7.6** plus `internal/httpd/api_user.go` und `api_utils.go` gelesen.
- **`PUT` ist kein partielles Update.** `updateUser` dekodiert den Body in ein
  *neues* User-Objekt und stellt nur `password`, `username`, `id`,
  Recovery-Codes, TOTP und `last_password_change` wieder her. Ein
  `PUT` mit nur `{"password": ...}` würde `home_dir` und `permissions`
  zurücksetzen und das Konto still brechen. `resetPassword()` liest deshalb
  vorher den User, ersetzt das Passwort und schreibt das vollständige Objekt
  zurück — mit `?disconnect=1`, damit eine bestehende Sitzung nicht mit dem
  alten Passwort weiterläuft.
- **Kein automatisches `retry()`.** Ein wiederholtes `POST /api/v2/users` aus
  einer verlorenen Antwort heraus erzeugt ein zweites Konto (409) statt eines
  sauberen Fehlers.

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
- `FtpImportTest` muss nach dem Wechsel unverändert grün bleiben (P1-M25).
  `FtpInboxLocalDiskTest` schreibt zusätzlich fest, dass `ftp_inbox` lokal
  bleibt: kein `sftp`-/`ftp`-Disk in der Config und kein Treiber `ftp`/`sftp`,
  der Import liest und schreibt ausschließlich über `ftp_inbox` und `photos`
  (beobachtet über einen `Storage::disk()`-Spy), kein HTTP-Roundtrip, und kein
  Treiber- bzw. `sftp://`-/`ftp://`-Verweis im `FtpController`-Quelltext für
  Zweige, die der Spy nicht durchläuft. Wer den Import auf
  `Storage::disk('sftp')` umbaut, bekommt drei rote Tests.

### 7.9 Ownership-Regeln auf dem Host

**FTPS-Zertifikat (Host-Setup, P1-M24).** SFTPGo kann ein Self-Signed-Zertifikat
nutzen — die Kamera akzeptiert es (verifiziert mit pure-ftpd). Das Zertifikat wird
beim ersten Start erstellt und liegt dann unter `/etc/sftpgo/`. Kein gültiges
Zertifikat einer öffentlichen CA nötig.

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

**Kamera-Protokoll — Protokoll geklärt, Cipher offen.** Die Kameras können
**sowohl FTPS als auch SFTP** (Auskunft 2026-09-26). Die Grundsatzentscheidung
ist damit **nicht mehr gefährdet**; der Punkt war als stack-abbruchendes P0
geführt und ist es nicht mehr.

Die **Cipher**-Frage ist damit aber nicht erledigt, nur von „unmöglich" auf
„konfigurierbar" gesenkt: Protokollfähigkeit sagt nichts über die Cipher aus,
und eine ältere Kamera kann SFTP sprechen und trotzdem nur `AES128-CTR` oder
`3DES` können. Der entscheidende Unterschied: auf der SSH-Seite ist die
Cipher-Liste über `Ciphers`/`tls_config` **einstellbar**, während pure-ftpd sie
fest selbst baute — `AES128-SHA` war dort nachweislich nicht erreichbar.

**Beide Protokolle bleiben in Betrieb — SFTP ist der bevorzugte Weg, FTPS der
Rückweg.** Wir stellen **beides** bereit, nicht nur eines: nicht jede Kamera
spricht dasselbe, und ein nicht vorhersehbarer Kameratyp soll den Cutover nicht
blockieren. Die Argumente für SFTP: (a) die Cipher-Frage ist auf der SSH-Seite
lösbar, auf der FTP-Seite nicht; (b) SFTP braucht **keine passive Port-Range** —
damit entfallen 501 Firewall-Ports und der Docker-`DOCKER`-Chain-Sonderfall, der
in `strato-vps` 6e dokumentiert ist; (c) SFTP ist ein einziger TCP-Kanal, FTPS
braucht Control- plus Datenkanal. FTPS bleibt, weil die Kameras es können und der
User `florian` unter pure-ftpd heute schon darüber funktioniert.

**Achtung:** SFTPGo lauscht für SFTP und FTPS auf **getrennten Ports**
(`sshd`/`ftpd` mit eigener `address`), sie teilen sich keinen. Für FTPS wird
`AUTH TLS` auf dem normalen FTP-Port unterstützt, also explizites FTPS ohne
zusätzlichen Port. Die Portwahl ist Teil des Portal-Stacks — die Firewall
dokumentiert `~/dev/strato-vps/ANALYSIS.md` Abschnitt 6e.

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

**Bestehende `ftp_slug`-Werte — entschieden (2026-09-26, P1-M21): nichts wird
stillschweigend umbenannt.** Der Fotograf wählt, mit Fehlerpfad bis dahin.

- **Korrektur der Diagnose:** `Str::slug()` ließ Punkte *nicht* zu — es
  entfernt sie ohne Trennzeichen. Aus `j.doe` wurde `jdoe` und damit eine stille
  Namenskollision mit einem Fotografen, dessen Localpart genau so lautet;
  `Max.Mustermann` wurde zu `maxmustermann` statt `max-mustermann`. `@` wurde über
  das Default-Dictionary zu `at`, ein Slash verschwand ersatzlos (`a/b` → `ab`).
  Der Fehler war nicht der erhaltene Punkt, sondern das **stille Verschlucken**
  identitätsrelevanter Zeichen.
- **Deshalb zwei Normalisierungen, nicht eine.** Für den selbst gewählten Wert
  (`AuthController::updateProfile()`) gilt `FtpSlug::normalize()`: es fasst nur
  Kosmetik an (Groß-/Kleinschreibung, Umlaute, Leerzeichen). Punkt, `@`, Slash
  und ein führendes Trennzeichen überstehen und werden von der Formatregel
  **abgelehnt** — aus einem getippten Login darf nicht ein anderer werden. Für
  **generierte** Werte (`User::nextAvailableFtpSlug()`, nur beim Insert, niemand
  hat sie getippt, nichts referenziert sie) gilt `FtpSlug::toValidBase()`, das
  auch reduziert (`j.doe` → `j-doe`).
- **Keine Migration, kein Backfill.** `ftp_slug` ist Fremdschlüssel für
  `storage/app/private`-Pfade und für `ftp/<slug>` auf dem Host; eine
  automatische Normalisierung würde das Verzeichnis verwaizen lassen, ohne dass
  jemand etwas mitbekäme. Bestehende regelwidrige Werte bleiben deshalb stehen,
  und wer sie erneut abspeichert, bekommt den Fehler mit der Formatregel im Text —
  der Fotograf vergibt selbst einen zulässigen Namen. Das ist der bewusst
  gewählte Zweig aus der offenen Frage.
- **Einzige Quelle der Wahrheit:** `backend/app/Support/FtpSlug.php` (Muster,
  Längen, Meldung, beide Normalisierungen, kollisionssicheres Suffix). Der
  Client prüft dasselbe Muster in `ProfileSettingsCard.tsx` gegen die **Endform**
  (nicht gegen eine zweite Normalisierung) und benennt die Regel im Feld.

**Brand-Scope.** `ftp_slug` ist user-level, nicht brand-level
(`25-brand-separation-matrix.md:33`). Der `Brand`-Enum hat aktuell genau einen
Fall (`app/Enums/Brand.php:12-15`), das Schema ist faktisch Single-Tenant. Für
einen zweiten Brand braucht es eine Trennung des Folder-Namespaces, sonst sieht
ein Fotograf die Ordner einer anderen Marke. Bewusst nicht vorgebaut, aber
dokumentiert (P1-M29).

**Concurrency im Import.** Erledigt (P1-M28): `process()` hat einen Lock pro
Fotograf, siehe §3.3.2. Die Annahme "single-user access" aus Abschnitt 6 gilt
nicht mehr und ist dort ersetzt; ein zweiter Aufruf bekommt 409 und arbeitet
nicht. Der Lock ist ausdrücklich **kein** Lock über Grenzen hinweg — der
FTP-Server schreibt ohne Protokoll-Lock in dasselbe Verzeichnis, und die Reichweite
des Guards ist nur der konfigurierte Cache-Store.

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

### 7.13 Port-Erreichbarkeit

**Kurzfassung:** Zwei Protokolle gehen nach draußen (SFTP **und** FTPS, beide
werden genutzt), der Verwaltungsport bleibt drinnen. Alles andere ist Detail.

| Port | Zweck | Erreichbar | Compose |
|---|---|---|---|
| 2222 (Host) → 2222 | SFTP (SSH) | **öffentlich** | `ports:` |
| 989 (Host) → 989 | FTPS (explizites TLS) | **öffentlich** | `ports:` |
| 50000–50100 | Passive FTPS-Datenkanal | **öffentlich** | `ports:` als Range |
| 8080 | HTTP- und Admin-API | **nur `portal_internal`** | *kein* `ports:` |

- **Warum beide Protokolle offen sind:** Wir nutzen **SFTP und FTPS**, weil
  nicht jede Kamera dasselbe spricht. SFTP ist der bevorzugte Weg (7.11), FTPS
  der Rückweg für Kameras, die SFTP nicht können — der User `florian` läuft
  heute noch über den FTPS-Pfad. Beide Ports zu öffnen kostet nichts und
  verhindert, dass eine nicht vorhersehbare Kamera den Cutover blockiert. Wer
  später weiß, welche Kameras im Einsatz sind, kann den jeweils anderen Port in
  der Firewall schließen.
- **Warum die passive Range zwingend ist:** nur FTPS braucht sie. Der Client
  verbindet sich auf 989, der **Datenkanal** kommt aber aus 50000–50100. Wird
  die Range nicht mitpubliziert, läuft der TLS-Handshake erfolgreich durch und
  der Upload bricht danach lautlos ab — der häufigste Stillschweizgrund bei
  FTPS. **Für SFTP ist die Range irrelevant**, SFTP ist ein einziger TCP-Kanal.
- **Warum 8080 nicht `ports:` bekommt:** über diesen Port werden Konten
  angelegt und Passwörter gesetzt (P1-M22). Öffentlich wäre das eine
  Verwaltungsfläche im Internet. Der Portal-Backend erreicht ihn als
  `http://sftpgo:8080` über `portal_internal`; der Service hängt **nicht** an
  `webnet`. Eine Veröffentlichung wäre ein eigener Change mit eigener
  Begründung, nicht eine Zeile in dieser Datei.
- **Image-Pin:** `drakkan/sftpgo:2.7.x`, bewusst **nicht** `latest`. Stand
  2026-09-26 ist `v2.7.6` die neueste Release (2026-09-19). Der Tag `latest`
  zeigt auf den Build vom **2026-09-18** und ist damit bereits veraltet,
  während `2.7.x` am 2026-09-26 neu gebaut wurde und beweglich die Minor-Reihe
  2.7 mit Bugfixes versorgt. Damit bleiben Security-Patches automatisch drin,
  ein Minor-Sprung (2.7 → 2.8, der das Config-Format mitbringt) aber nicht.
  Exakt auf `v2.7.6` zu pinnen würde bedeuten, Security-Patches manuell
  einzuspielen — auf einem Server mit laufendem Betrieb die schlechtere Wahl.

**Noch offen für den echten Stack:** SFTPGo muss seine Bindings auf die
**Container**-Ports 2222/989/8080 bekommen, und der passive Bereich muss auf
`50000-50100` stehen. Seit 2.6 liegt die Konfiguration in der Datenbank
statt in einer JSON-Datei, der Seeding-Weg ist deshalb Teil von P1-M35 und
nicht als Kleinigkeit zu behandeln.

### 7.14 Teststrategie für den Transport

Drei Ebenen, strikt getrennt. Der Grund für die Trennung ist eine Grenze, die
man leicht falsch liest: **ein Client-Protokolltest prüft die Server-Seite, nicht
die Kamera.**

| Ebene | Was | Wie | Beweist die Kamera? |
|---|---|---|---|
| Unit/Feature | Pipeline, `SftpGoClient` | `Storage::fake()`, gemocktes HTTP | nein |
| Integration | Transport über beide Protokolle | echter Dienst, echter Client | **nein** |
| Manuell | Kameraupload | echte Kamera, echtes Foto | **ja** |

Die Kamera ist kein `curl`. Sie hat eine eigene TLS-Bibliothek, eine eigene
Cipher-Liste und ein Passwortfeld, das ausschließlich alphanumerisch akzeptiert.
Ein grüner Integrationstest sagt daher **nicht**, dass die Kamera den Server
erreicht — das bleibt P1-M32 und ist ein operativer Schritt, den kein CI-Job
ersetzen kann.

Trotzdem ist die Integrationsebene die wertvollste, weil sie die Lücke zwischen
Kamera-Verhalten und Server-Konfiguration prüft — also genau das, was sonst
niemand prüft:

- **Die passive Port-Range.** Ein FTPS-Client, der 50000–50100 nicht erreicht,
  baut den TLS-Handshake erfolgreich auf und bricht erst beim Transfer ab. Ein
  Test gegen Port 989 allein findet das **nicht**, weil der passive Kanal ein
  zweiter TCP-Verbindungspfad ist. Für SFTP irrelevant — dort ist es ein
  einzelner Kanal.
- **Die Ownership-Kette:** Datei ankommt mit `1002:webgroup`, `setgid` auf
  `ftp/<slug>` gehalten.
- **Das Show-once-Passwort:** ein alphanumerisches 16–24-Zeichen-Passwort ohne
  Sonderzeichen muss durch Provisionierung und Login kommen (7.3).
- **Die Cipher-Liste als Fixture** — mit Handshake-Vollständigkeitsprüfung vor
  dem Auslesen. Siehe die Messfalle in 7.11.

Board: P1-M38 (Integrationstest), P1-M35 (Harness und Config-Weg), P1-M27
(Cipher-Frage selbst).

### 7.15 Bewusst nicht im Compose-Stack

- **Der Fotograf-Upload läuft nicht über den Portal-Container.** Die Dateien
  landen direkt auf dem Host-Pfad, den der Backend-Container liest. Ein
  Einbruch ins Portal-Core liefert deshalb **keinen** Dateizugriff.
- **`SFTPGO_DEFAULT_ADMIN_*` sind Pflichtvariablen** (Compose `:?` ohne
  Default). Fehlen sie, startet der Stack nicht absichtlich: ohne Admin-User
  gibt es kein `/api/v2/token`, also keine Provisionierung. Ein Stack, der
  läuft, aber nicht provisionieren kann, wäre der schlechtere Zustand.
