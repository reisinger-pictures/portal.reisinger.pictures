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

Read-only and free of SFTPGo contact (§7.5). Returns:

- `ftp_folder`: The user's inbox directory name (`/` + `ftp_slug`, falling back to the user `id`).
- `file_count`: Count of `.jpg`/`.jpeg`/`.JPG`/`.JPEG` files in the inbox.
- `current_target_gallery`: Currently selected gallery (with loaded relation), or `null`.
- `ftp_account_status`: The stored provisioning state from `users.ftp_account_status` — `pending` / `active` / `revoked` / `error` (§7.4, §7.16). Never a live query.
- `ftp_provisioned_at`: Timestamp of the last successful provisioning, ISO-8601, or `null`.
- `ftp_account_error`: Provider text for the `error` state, otherwise `null`.
- `ftp_reset_limit_per_hour`: `FtpCredentialService::RESET_LIMIT_PER_HOUR` — the hourly camera-password reset quota, exposed so the UI quotes the rule instead of copying it (§7.6a).
- `connection`: The camera connection details as `FtpConnectionDetails::toArray()` — `configured`, `host`, `username`, `path`, `sftp_port`, `ftps_port`, `pasv_port_start`, `pasv_port_end`, `ftps_tls_mode`. Carries no secret; the password only ever leaves `POST /api/management/ftp/reset-password`. The frontend mirror is `FtpConnection` in `frontend/src/logic/ftpConnection.ts`.

`ftp_folder`, `file_count` and `current_target_gallery` are the original fields; the account fields, the reset quota and `connection` are additive. The frontend contract is `FtpStatus` in `frontend/src/logic/useFtp.ts`.

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
- **Namensautorität (P1-M34, entschieden 2026-09-26):** `users.ftp_slug` führt,
  SFTPGo wird bei jeder Änderung nachgezogen. Ein Slug-Wechsel ist ein **Reset**:
  Neuer Slug + neues Passwort, einmal angezeigt. Begründung: SFTPGo's
  `PUT /api/v2/users/{username}` ersetzt das ganze User-Objekt (kein partielles
  Update), also wäre Read-Modify-Write nötig — und das hat Race Conditions. Der
  Reset vermeidet das und bleibt konsistent mit dem Show-once-Konzept.

### 7.3 Passwort-Fluss: erzeugen, anzeigen, verwerfen

**Das Portal speichert kein FTP-Passwort.** Das ist der Kern des Wechsels.

1. PHP erzeugt ein kamerataugliches Passwort
   `/^[a-km-zA-HJ-NP-Z2-9]{10,12}$/` — **keine Sonderzeichen** und **keine der
   fünf mehrdeutigen Zeichen**. Details und Begründung unten.
2. Übergabe per **HTTPS** an die SFTPGo-Admin-API.
3. Anzeige **einmal**, danach verwerfen.
4. Verloren → `resetPassword()`. Es gibt **keine** Wiederherstellung.

**Passwortform, Präzisierung 2026-09-27 (Owner-Entscheidung).** Verbindlich ist
`FtpCredentialService::PASSWORD_PATTERN` =
`/^[a-km-zA-HJ-NP-Z2-9]{10,12}$/`, mit `PASSWORD_MIN_LENGTH = 10` und
`PASSWORD_MAX_LENGTH = 12`. Die Länge wird **pro Aufruf gezogen**, damit ein
geleaktes Passwort nicht über die Länge eingegrenzt werden kann. Drei
Entscheidungen stecken in dieser einen Zeile:

- **Keine Sonderzeichen.** Unverändert gegenüber der Ursprungsregel: eine Kamera
  kann `-`, `&` oder `!` am Konfigurationsbildschirm nicht eingeben, das
  Alphabet ist streng alphanumerisch.
- **Die fünf mehrdeutigen Zeichen fallen weg — sie werden nicht bloß lesbar
  gemacht.** Ausgeschlossen sind `0`, `O`, `1`, `l` und `I`; es bleiben **57
  Zeichen**. Sie lesbar zu rendern reicht nicht: ein auf dem Kameradisplay
  verwechseltes `0`/`O` oder `1`/`l` ist kein Fehler, den der Fotograf bemerkt,
  sondern eine fehlgeschlagene Anmeldung und eine Neugenerierung.
- **Gemischte Groß-/Kleinschreibung ist erlaubt, und das hebt die frühere
  Regel auf.** Die alte Begründung — gemischte Schreibweise würde ein
  Tastatur-Layout in einen Support-Fall verwandeln — war eine abgewogene
  Abwägung und wird hier **überstimmt**, nicht vergessen; deshalb ist sie
  festgehalten. Sie unterstellt, dass ein Mensch das Passwort abtippt. Angezeigt
  wird es in `ShowOncePassword` in einer Monospace-Schrift (`font-mono`), also
  Zeichen für Zeichen lesbar, und niemand tippt es aus dem Gedächtnis nach.
  **Das kleingeschriebene Alphabet nicht wiederherstellen** — die gemischte
  Form war die Anforderung, und die Entropiekosten unten sind der dafür
  akzeptierte Preis.

**Die Entropie ist gesunken, das ist keine Rundungsdifferenz.** Die Entropie ist
`Länge × log2(57)`, `log2(57) ≈ 5.833` Bit je Zeichen: **10 Zeichen ≈ 58,3 Bit,
12 Zeichen ≈ 70,0 Bit.** Die frühere Form waren 36 Zeichen bei 16–24 Zeichen,
`log2(36) ≈ 5.170`, also **~82,7 bis ~124,1 Bit.**

Die neue Spanne liegt damit **nicht innerhalb** der alten, sondern vollständig
**darunter**: unten um ~24 Bit, oben um ~54 Bit. Klar gesagt, weil ein
Absatz, der das verschweigt, schlechter ist als keiner: **die Mindeststärke eines
Kamerapassworts ist gesunken.** Das ist eine echte Reduktion des
Brute-Force-Raums. Bewusst in Kauf genommen (Owner-Entscheidung, 2026-09-27)
aus zwei Gründen. Ein Online-Angreifer gewinnt vom Alphabet nichts: die
Stundenquota (7.6a) begrenzt, wie schnell ein Konto sein **eigenes**
Zugangsdatenpaar rotiert, und diese Klasse speichert das Passwort nie — ein
Offline-Ziel gibt es hier nicht, ein Offline-Angriff müsste den Credential-Store
von SFTPGo selbst treffen, ein fremdes System mit eigenem Schutz, das diese
Klasse weder schreibt noch liest. Was die Reduktion kauft, ist der Punkt der
Änderung: 16–24 Zeichen sind „extrem schwer auf der Kamera einzugeben", und ein
Passwort, das nicht eingegeben werden kann, ist eine Kamera, die nie hochlädt.
Die Kürzung um 37 % an der Untergrenze (16 → 10) und 50 % an der Obergrenze
(24 → 12) ist die **Kompensation** für die gemischte Schreibweise, kein
Nebeneffekt des Alphabets.

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
- **Erzeugung und Prüfung sind zwei Hälften einer Form.** `Str::random()` liefert
  Base64 ohne `/`, `+` und `=`, also 62 alphanumerische Zeichen; die Klasse
  filtert daraus die fünf mehrdeutigen weg und behält 57.
  `NON_CAMERA_CHARACTERS` ist bewusst das **exakte Komplement** zu
  `PASSWORD_PATTERN` — eine in zwei Formen geschriebene Zeichenklasse muss
  zweimal gelesen werden, um geprüft zu werden. **Die gemischte Schreibweise
  bleibt erhalten**: ein `Str::lower()` im Erzeugungspfad würde die
  Owner-Entscheidung oben stillschweigend zurücknehmen, und genau deshalb steht
  dort keines mehr.
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

**Umsetzung (P1-M26, ergänzt 2026-09-27).** `FtpController::status()` gibt die
drei Kontofelder zusammen mit dem Bestand und der Reset-Quota zurück:

| Feld | Quelle | Bedeutung |
|---|---|---|
| `ftp_account_status` | `users.ftp_account_status` | `pending` / `active` / `error` |
| `ftp_provisioned_at` | `users.ftp_provisioned_at` | letzter erfolgreicher Provision, ISO-8601 oder `null` |
| `ftp_account_error` | `users.ftp_account_error` | Fehlertext zu `error`, sonst `null` |
| `ftp_reset_limit_per_hour` | `FtpCredentialService::RESET_LIMIT_PER_HOUR` | Stundenkontingent des Passwort-Resets (7.6a) |

- **Kein SFTPGo-Kontakt.** Der Lesepfad ruft den Dienst nicht auf; der Wert
  kommt aus der Spalte. Ein 500er aus dem Dienst kann hier prinzipiell nicht
  entstehen — schlimmster Fall ist eine veraltete Spalte. Genau das ist der in
  7.5 geforderte Verhalten: der Fotograf sieht, was das System weiß.
- **Additiv.** `ftp_folder`, `file_count` und `current_target_gallery` bleiben
  unverändert im Response; der Inbox-UI baut darauf auf.
- **`ftp_reset_limit_per_hour` ist Teil des Vertrags, damit die
  Kamera-Anleitung keine veraltete Quota nennen kann.** Quelle ist die
  Konstante, die der Guard selbst benutzt, nicht ein Literal. Der Anlass war
  eine Lücke, die 2026-09-27 aufgefallen ist: die Quota wurde von 3 auf 10
  angehoben, während der Schritt *Kennwort anfordern* in
  `KameraEinrichtungContent` im deutschen Copy noch „drei Anforderungen pro
  Stunde" sagte — und **nichts ist fehlgeschlagen**, weil eine Zahl im deutschen
  Copy keinen Test hat, der sie widerlegt. Der Fotograf hätte eine falsche
  Auskunft bekommen. Die Anleitung ist damit **Leserin** der Regel statt einer
  zweiten Kopie davon, und die beiden können nicht auseinanderlaufen. Eine
  Konstante ist aus demselben Grund die richtige Quelle und kein Config-Wert:
  das ist eine Sicherheitsregel, die UI darf sie beschreiben, aber nicht
  definieren. `KameraEinrichtungContent` bekommt den Wert als Prop
  `resetLimitPerHour`; `KameraEinrichtungContent.test.tsx` prüft in beide
  Richtungen, damit ein Literal nicht zurückkehren kann.
- **Frontend-Typ:** `FtpAccountStatus` in `frontend/src/logic/useFtp.ts` ist die
  geschlossene Menge der drei Werte. `null` gehört nicht zum Vertrag (die Spalte
  ist NOT NULL mit Default), deshalb ist der Union-Typ erschöpfend und ein
  UI-`switch` ist zur Compile-Zeit vollständig.

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
- `process()` hat keinen SFTPGo-Kontakt und behält den Cache-Lock aus P1-M28.

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

### 7.6a Passwort-Reset: Rate-Limit und Audit-Trail (P1-M33)

Die Show-once-Semantik aus 7.3 macht den Reset zum **einzigen**
Recovery-Weg: das alte Passwort ist unwiederbringlich. Genau das macht ihn
gefährlich — ein unbeschränkter Reset-Endpoint lässt ein Konto die
Zugangsdaten einer **funktionierenden** Kamera so oft tauschen, wie es klickt.
(Nicht: unbegrenzt gültige Zugangsdaten. Ein Reset ersetzt; was die Quota
tatsächlich begrenzt, steht unten.) 7.6 (M22) und 7.3 (M23) sichern ab, dass
ein Passwort nicht ins Log gerät; **wie oft** zurückgesetzt wird, regeln sie
nicht.

**Endpoint.** `POST /api/management/ftp/reset-password` →
`FtpCredentialController::resetPassword()`.

- **Kein Request-Input außer dem authentifizierten Nutzer.** Das Konto ist
  immer `auth('api')->user()`. Ein `user_id` im Payload wäre eine IDOR mit
  Body: das Zurücksetzen der Kamera-Zugangsdaten einer anderen Person ist genau
  der Missbrauch, den Quota und Audit-Trail sichtbar machen sollen — die Fläche
  dafür wird gar nicht erst angeboten.
- **Eigener Controller, nicht `FtpController`.** Letzterer ist die
  Import-Pipeline, und 7.8 fixiert seine Form. Der Reset ist das Gegenteil — ein
  reiner SFTPGo-Write — und hätte dem Import-Pfad sonst einen Grund gegeben,
  sich zu ändern.
- **Statusabbildung:** 429 mit `Retry-After` bei Quota, 422 bei
  Portal-Vorbedingungen, 503 bei `not_configured`/`unreachable`, 404 bei
  `not_found`, 409 bei `already_exists`, 502 sonst. Kein 500 aus dem Dienst —
  der Fotograf muss wissen, ob sein Passwort sich geändert hat, denn es wird
  **nicht** zurückgerollt.

**Rate-Limit.** `FtpCredentialService::RESET_LIMIT_PER_HOUR = 10`, Fenster 3600 s,
über `RateLimiter`, Schlüssel `ftp-password-reset:{userId}`.

- **Pro Konto, nicht global.** Sonst könnte ein Fotograf den Recovery-Weg allen
  anderen nehmen.
- **Schlüssel ist die User-ID, nicht der Slug.** P1-M34 lässt offen, ob
  `users.ftp_slug` oder der SFTPGo-Store führend ist; ein Slug-Schlüssel würde
  bei einem Rename still ein frisches Kontingent gutschreiben.
- **Konstante, kein Config-Wert.** Zehn ist eine Sicherheitsregel aus dem Board,
  keine Kapazitätseinstellung — und ein Wert in `config/app.php` könnte als `0`
  ausgeliefert werden, was den Recovery-Weg still abschaltet.
- **Von 3 auf 10 angehoben — das schwächt den Guard, und das ist die ehrliche
  Schlagzeile** (Owner-Entscheidung, 2026-09-27). Drei war gegen jemanden
  gemessen, der bereits weiß, was er tut, und scheitert an genau dem Fall, der
  tatsächlich eintrifft: jemand an der Kamera, der an einer Scheibe dreht und
  ein Passwort falsch abtippt, das er nicht nachlesen kann. Jeder Versuch gibt
  das Zugangsdatenpaar neu aus, also verwandelt eine niedrige Quota ein
  Transkriptionsproblem in eine stundenlange Sperre ohne Ausweg. Die
  Kompensation ist die Audit-Zeile, und beides ist **zusammen** zu lesen: eine
  weitere Quota bedeutet eine **längere** Spur, nicht eine leisere. Ein Schwall von
  zehn Resets sind zehn zuordenbare Zeilen, nicht eine verschüttete.
- **Abgelehnte Aufrufe zählen mit.** Ein gehämmerter Button verlängert das
  Fenster dadurch nicht endlos — dieselbe bewusste Entscheidung wie in
  `CheckoutRiskService`.
- **Die Quota läuft ab.** Zehn echte Versuche in einer Stunde sind ein
  Support-Fall; ein Limiter, der nie vergisst, wäre ein Lockout.

**Was die Quota tatsächlich begrenzt**, gehört präzise gesagt, denn die
naheliegende Formulierung ist falsch. Ein Reset **ersetzt** das Passwort, es
gibt also je Konto zu jedem Zeitpunkt genau **ein** gültiges Zugangsdatenpaar,
und kein Reset erzeugt je ein zweites. Die Grenze ist deshalb **keine**
Obergrenze für die Anzahl existierender Zugangsdaten — das Portal verwaltet zu
jedem Zeitpunkt nie mehr als eines. Begrenzt werden zwei reelle Dinge:

1. **Die Störung einer funktionierenden Kamera.** Jede Rotation macht das
   Passwort ungültig, das die Kamera hält. Ohne Drossel ließe ein Konto den
   Uploader so oft offline nehmen, wie es klickt.
2. **Die Last auf die SFTPGo-API.** Jede Rotation ist ein Read-Modify-Write
   (`findUser()`, dann das Update) gegen einen laufenden Dienst; der Endpoint
   wäre sonst ein Verstärker für Request-Volumen.

Der Audit-Trail ist die ausgleichende Kontrolle, und er skaliert **nicht** mit
der Zahl: `recordResetAttempt()` schreibt eine Zeile für jeden Versuch, der die
Quota passiert hat, erfolgreich oder nicht. Das Anheben von 3 auf 10 verlängert
die Spur und schwächt **nichts** an der Zurechenbarkeit.

**Audit-Trail.** Migration **V042**, Tabelle `ftp_password_resets`
(`FtpPasswordReset`):

| Spalte | Typ | Bedeutung |
|---|---|---|
| `user_id` | FK `users` CASCADE | betroffenes Konto |
| `ip` | varchar(45) nullable | Aufruferadresse |
| `success` | boolean NOT NULL | Ausgang |
| `reset_at` | timestamp NOT NULL | Zeitpunkt |

- **Jeder Versuch schreibt eine Zeile, nicht nur der Erfolg.** Ein fehlgeschlagener
  Reset ist die *interessantere* Zeile: ein Dienst, der zehnmal 500 liefert, ist
  ein Support-Fall, und ohne diese Zeilen bleibt die Beschwerde „Reset tut
  nichts" spurlos. Erfasst sind auch Portal-Vorbedingungen, die den Dienst nie
  erreichen.
- **Ein 429 schreibt nichts.** Eine Ablehnung ist die *Verweigerung* eines
  Versuchs, kein Versuch.
- **Kein IP-Pflichtfeld.** Ein Console- oder Job-Reset hat keine Adresse, und ein
  Platzhalter wäre in einer Audit-Tabelle eine Lüge.
- **Keine Passwortspalte.** Die schreibende Methode bekommt Nutzer, IP und
  Boolean — es gibt weder eine Spalte noch ein Argument, durch das ein
  Passwort hindurchkäme.
- **Fehlschlag der Audit-Zeile wird nicht geschluckt.** `Eloquent::create()`
  liefert bei einem vetoenden `creating`-Listener ein *ungespeichertes* Modell
  statt einer Exception; ohne `exists`-Prüfung wäre eine Rotation ohne Spur
  unbemerkt geblieben. `FtpAuditWriteException` → 500 **ohne** Passwort im
  Response: die Kamera läuft dann auf einem Passwort, das niemand notiert hat.

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
  die `status()`-Struktur und `process()` bleiben. **Präzisierung 2026-09-26
  (P1-M26):** `status()` liefert zusätzlich die drei Kontofelder aus 7.4.
  Additiv — die bestehende Struktur und der Import-Pfad bleiben unberührt, und
  der Lesepfad bekommt **keinen** SFTPGo-Kontakt. `FtpImportTest` und
  `FtpInboxLocalDiskTest` bleiben unverändert grün.
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

**FTPS-Zertifikat (Host-Setup, P1-M24).** Die Kamera akzeptiert ein
selbstsigniertes Zertifikat (verifiziert mit pure-ftpd). Kein gültiges
Zertifikat einer öffentlichen CA nötig.

**Korrigiert am 2026-09-26:** SFTPGo 2.7 erzeugt **kein** Zertifikat selbst. Die
frühere Fassung behauptete das und war falsch — sie ist der Grund, warum der
erste Start mit `could not start FTP server: to enable TLS you need to provide a
certificate` in einer Restart-Schleife endete. Das Zertifikat wird jetzt beim
ersten Start per `openssl` selbstsigniert erzeugt und liegt unter
`/var/lib/sftpgo/ftps/` im Dataprovider-Volume, damit es Neustart und Redeploy
überlebt.

**Für die Kamera exportieren:** Canon verlangt die Datei auf der Speicherkarte
(`.CER`/`.CRT`/`.PEM`) plus „Zielserver vertrauen → Aktivieren", sonst bricht die
Übertragung mit Error 48 ab (cam.start.canon UG-06_Network_0230). Die Datei liegt
im Container unter `/var/lib/sftpgo/ftps/cert.pem`; ein Download aus der GUI ist
noch nicht gebaut (offen, `AGENTS.todo.md`).

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
- `ftp/<slug>` ist `1002:webgroup` mit `2775` (setgid, Gruppe schreibbar, Welt
  nicht schreibbar), damit neue Dateien die Gruppe erben. **Geändert 2026-09-28:**
  vorher stand hier `2777`; das Welt-Schreibrecht ist entfallen, weil jeder
  Prozess mit Schreibbedarf entweder Eigentümer oder in `webgroup` ist.
- SFTPGo legt virtuelle Ordner **nicht** an (Doku: *"you have to create the
  folder on disk yourself"*). **Das Portal legt `ftp/<slug>` deshalb selbst an**
  (D-2, `AGENTS.md` §14): `App\Support\FtpInboxDirectory::ensure()` läuft im
  Slug-Schreibpfad **vor** dem Speichern. Schlägt die Anlage fehl, wird der Slug
  **nicht** gespeichert und die Antwort nennt Pfad und Grund (fehlender Mount,
  read-only, fehlende Rechte). Der Zustand „Slug gesetzt, Ordner fehlt" kann so
  nicht entstehen — er sähe im Posteingang wie ein leerer Ordner aus, nicht wie
  ein Fehler. `ftp:provision-folders` bleibt als Nachzieh- und Reparaturlauf für
  Bestände; beide Pfade lesen den Modus aus derselben Konstante
  (`FtpInboxDirectory::MODE`), damit der Reparaturlauf ein frisch angelegtes
  Verzeichnis nicht still zurückstuft.
- **Ownership ohne root:** Der Web-Prozess ist nicht root und chownt nichts
  Fremdes. Die **Gruppe** erbt das neue Verzeichnis vom übergeordneten `ftp/`
  über dessen **setgid**-Bit; das neue Verzeichnis trägt setgid weiter und gibt
  es an spätere Uploads weiter, sodass der importierende Backend sie lesen und
  löschen kann. Der **Eigentümer** ist die uid, unter der der Web-Prozess läuft —
  deshalb muss der Prozess als uid des Baums laufen (D-1: `1002`).
- **Slug-Wechsel A → B:** `ftp/B` wird angelegt, `ftp/A` bleibt **erhalten**.
  Löschen wäre destruktiv — dort können noch nicht importierte Uploads liegen —
  und der alte SFTPGo-Account ist bereits gelöscht, die Kamera lädt also nicht
  mehr hinein. `AuthController::updateProfile()` schreibt beim Wechsel eine
  Warnung mit dem Pfad des alten Ordners, damit der Owner ihn nach dem Import
  bewusst aufräumt; stilles Löschen wäre der schlechtere Fehler.

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
7. Ordner-Anlage (P1-M24, D-2): automatisch beim Setzen des Slugs; der
   `ftp:provision-folders`-Lauf zieht Bestände nach und korrigiert Rechte.
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

**Stand 2026-09-26: die Messung ist nachgeholt, gegen die laufende
Produktionsinstanz, per `openssl s_client -starttls ftp`.** Ergebnis:

- **SFTP (2222):** `aes128-gcm@openssh.com`, `aes128-ctr`, `aes256-gcm@openssh.com`
  und `chacha20-poly1305@openssh.com` werden alle akzeptiert. Die Cipher-Frage ist
  auf der SSH-Seite **erledigt** — das war die offene Begründung dafür, SFTP zum
  bevorzugten Weg zu machen.
- **FTPS (989), explizit:** TLS 1.2 **und** 1.3 werden bedient; Go handelt mit
  einem älteren Client selbst herunter. `ECDHE-RSA-AES128-GCM-SHA256` und
  `ECDHE-RSA-AES256-GCM-SHA384` — die Cipher, die Canons Dokumentation nennt —
  werden angeboten.
- **Nicht** angeboten: `AES128-SHA`, `AES256-SHA`, DHE-Suites, TLS 1.0/1.1. Nach
  der Canon-Doku ist das **kein** Mangel: sie verlangt gerade die
  RSA-GCM-Suites. Der frühere Befund "AES128-SHA fehlt" stammt aus dem
  pure-ftpd-Vergleich und ist für die fraglichen Kameramodelle gegenstandslos.
  Ein Legacy-Cipher-Override (`TLS_CIPHER_SUITES`) ist damit **nicht** nötig und
  wird bewusst nicht gesetzt — er würde die Transportverschlüsselung schwächen,
  ohne einen bekannten Nutzen.

Die frühere Fassung dieses Abschnitts (Messung stehe aus, wiederholt gescheitert)
ist damit überholt. Nachfolgend die ursprüngliche Begründung, unverändert
erhalten. Belegt ist:
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
| 2222 (Host) → **2022** (Container) | SFTP (SSH) | **öffentlich** | `ports:` |
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

**Erledigt am 2026-09-26, gegen den laufenden Stack gemessen.** Die Annahme in der
früheren Fassung war an drei Stellen falsch und ist die Ursache der
Nicht-Erreichbarkeit gewesen:

1. **SFTPGo 2.7 lauscht mit SSH auf 2022, nicht auf 2222.** `2222:2222` band
   einen Host-Port, im Container aber nichts — der Host antwortete mit RST. Die
   Korrektur ist `2222:2022`; kameraseitig bleibt 2222.
2. **Der FTP-Daemon ist standardmäßig aus** (`ftpd.bindings[].port = 0`). Port
   989 war ebenfalls tot, bis die Bindung aktiviert wurde.
3. **Die Konfiguration liegt nicht in der Datenbank**, sondern in
   `/etc/sftpgo/sftpgo.json`; `SFTPGO_*`-Variablen überschreiben sie. Der
   Präfix lautet `SFTPGO_FTPD__…`, **nicht** `SFTPGO_FTP__…` (der Abschnitt
   heißt seit 2.6 `ftpd`). Mit `FTP__` wird die Variable kommentarlos ignoriert —
   die Konfiguration sah korrekt aus und der Port blieb tot.

Zusätzlich: `exec sftpgo serve` ist zwingend, blankes `sftpgo` druckt nur die
Hilfe und beendet sich mit 0. Und `TLS_MODE=1` ist **explizites** TLS (`AUTH
TLS`), weil eine Canon-Kamera direkt nach dem Verbindungsaufbau `AUTH TLS`
sendet und implizites FTPS nicht zuverlässig unterstützt
(cam.start.canon UG-06_Network_0060).

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
- **Das Show-once-Passwort:** ein Passwort aus den 57 alphanumerischen
  Kamerazeichen mit 10–12 Zeichen, gemischter Groß-/Kleinschreibung und ohne
  `0 O 1 l I`, muss durch Provisionierung und Login kommen (7.3).
- **Die Cipher-Liste als Fixture** — mit Handshake-Vollständigkeitsprüfung vor
  dem Auslesen. Siehe die Messfalle in 7.11.

Board: P1-M38 (Integrationstest), P1-M35 (Harness und Config-Weg), P1-M27
(Cipher-Frage selbst).

### 7.15 Bewusst nicht im Compose-Stack

- **Der Fotograf-Upload läuft nicht über den Portal-Container.** Die Dateien
  landen direkt auf dem Host-Pfad, den der Backend-Container liest. Ein
  Einbruch ins Portal-Core liefert deshalb **keinen** Dateizugriff.
- **Kein `${VAR:?}`-Guard im Compose, trotz allem.** Portainer löst die
  Interpolation ab, **bevor** es die GUI-Umgebung anwendet; ein Guard bricht
  jeden Deploy ab. SFTPGo ist deshalb fail-*sichtbar* statt fail-closed: ohne
  Admin-Credentials startet es nicht und schreibt `no admins found, try to create
  the default one` ins Log, und der Container restartet.
- **Ein Wertepaar, nicht zwei.** Gepflegt wird `SFTPGO_ADMIN_USERNAME` /
  `SFTPGO_ADMIN_PASSWORD` aus `.env.production` — dieselben Namen, die
  `config/services.php` für den JWT-Fallback liest. Das Compose mappt sie auf
  SFTPGOs Bootstrap-Namen `SFTPGO_DEFAULT_ADMIN_*`. Vorher las das Compose
  `SFTPGO_DEFAULT_ADMIN_*`, der Backend-Service deklarierte keines der beiden
  Paare, und mit leerem `SFTPGO_API_KEY` wäre die Provisionierung mit 401
  gescheitert, während die Konfiguration korrekt aussah.

### 7.16 Lebenszyklus des Kamera-Kontos

Stand 2026-09-26, entschieden vor der Umsetzung (Schema-Entscheidung nach
`backend/AGENTS.md`, Migration Policy).

**Der Zustand ist eine Liste, kein Flag.** `users.ftp_account_status` ist ein
Enum mit `pending | active | error`. Ein Entzug braucht einen vierten Wert, weil
„hat nie gehabt" und „hatte es und wurde entzogen" für die Oberfläche und das
Audit zwei verschiedene Dinge sind. Ohne vierten Wert müsste der Entzug auf
`pending` oder `error` landen — beides wäre eine Lüge: `pending` behauptet
„noch nie provisioniert" und lädt former Fotografen ein, Zugangsdaten
anzufordern.

**Migration V043:**

| Änderung | Typ | Begründung |
|---|---|---|
| `ftp_account_status` Enum um `revoked` erweitern | Schema | vierter Zustand, siehe oben |
| `ftp_revoked_at` | timestamp, null | Audit-Spur, spiegelbildlich zu `ftp_provisioned_at` |
| Backfill | **keiner** | es wurde noch nie entzogen; alle bestehenden Werte bleiben gültig |
| Rollback | Enum auf drei Werte, `ftp_revoked_at` droppen | nur sinnvoll, wenn keine Zeile `revoked` existiert; die Spalte ist genau für den Fall da, also wird sie im Normalbetrieb nicht zurückgerollt |

`down()` wird laut Repo-Regel nie ausgeführt und bleibt leer.

**Die Invariante, die ein Umbau sonst zerstört:** Ein SFTPGo-Account existiert
**genau dann**, wenn `users.ftp_account_status === 'active'` ist. Der Slug bleibt
beim Entzug stehen — er benennt den Account, den es einmal gab —, aber er ist
kein Beleg dafür, dass der Account noch lebt; die frühere Formulierung „der Slug
benennt einen existierenden Account — oder ist leer" gilt seit `revoked` nicht
mehr. `ftp_provisioned_at` bleibt ebenfalls stehen: es beschreibt die
*vergangene* Provisionierung, `ftp_revoked_at` sagt, wann sie endete; beide
zusammen lesen sich korrekt. Es gibt keinen Zustand, in dem der Portal-Stand und
der Dienst auseinanderlaufen, ohne dass das System es bemerkt. Daraus folgt
fail-closed an beiden Stellen:

- **Slug-Wechsel** (P1-M34): Der alte Account wird gelöscht, bevor der neue
  User-Datensatz geschrieben wird. Ist SFTPGo nicht erreichbar, bricht der
  Vorgang ab und der User bleibt unverändert. Grund: ohne Abbruch bekäme der
  Fotograf einen Slug, für den es weder Verzeichnis noch Account gibt — das sieht
  in der Oberfläche wie ein leerer Posteingang aus und nicht wie ein Fehler.
- **Entzug bei Rollenverlust** (neu): Verlässt jemand die Rolle Fotograf, wird
  der SFTPGo-Account gelöscht und `revoked` + `ftp_revoked_at` geschrieben. Auch
  hier fail-closed: ist SFTPGo nicht erreichbar, wird der Rollenwechsel
  abgelehnt. Eine Belegschaft ohne Fotograf-Rolle, die SFTPGo nicht erreichen
  kann, ist ein schlechterer Zustand als ein abgelehnter Rollenwechsel.

**Verdrahtung:** `UserController::update()` (Rollenwechsel über `role_ids`) und
`UserController::destroy()` (Kontolöschung). Beide rufen
`FtpCredentialService::revoke()` auf, **bevor** sie schreiben.

**Was das nicht löst:** Es gibt weiterhin keine Reconciliation, die einen
SFTPGo-Account findet, den das Portal nicht kennt — etwa nach einem manuellen
Eingriff in SFTPGo. `reconcileAccount()` (P1-M30) ist der vorgesehene Weg und
bleibt offen.

**Provisionierung ist der Übergang nach `active`.** Die Invariante hält nur,
wenn der Pfad, der den Account anlegt, den Zustand auch schreibt. Bis V043 tat er
das nicht — sein Kommentar stammte aus der Zeit vor V041 —, also meldete das
Portal `pending`, während SFTPGo einen lebenden Account hielt. Nach dem Entzug
wurde daraus ein echter Widerspruch: ein entzogener Fotograf, der die Rolle
zurückbekam, wäre neu provisioniert worden, während der Status `revoked` blieb.
`provisionAndShow()` schreibt jetzt `active` und `ftp_provisioned_at`.
**Wiedereintritt ist kein Sonderfall**, sondern derselbe Übergang:
`revoked → active`, und `ftp_revoked_at` bleibt als Beleg des vergangenen Entzugs
stehen. Geschrieben wird dabei kein Geheimnis, nur die zwei Buchungsspalten.

**Der Reset ist die Beschaffung, nicht nur die Rotation (P1-M58).**
`POST /management/ftp/reset-password` war die einzige Aktion der Oberfläche und
rief `resetPassword()`, das als Read-Modify-Write mit `findUser()` beginnt — ein
nie provisioniertes Konto antwortet also 404. Damit konnte ein Fotograf, der
seinen `ftp_slug` nie ändert, **gar kein** Konto bekommen: Status `pending`, und
die Oberfläche sagte „Fordere zuerst die Zugangsdaten an", wofür es keine Tür
gab. Derselbe Fehler stand eine Zeile früher: der Slug-Wechsel in
`AuthController::updateProfile()` rief `deleteUser()` **unbedingt**, sobald ein
Slug gesetzt war. `User::booted()` vergibt aber bei jedem Anlegen einen Slug und
niemals ein Konto — der Delete lief also gegen einen Account, den es nie gab,
und der Fail-closed brach **jeden ersten Slug-Wechsel mit HTTP 500** ab.

Der Endpunkt bekommt deshalb die Zustandstabelle. Keine neue Tür: er trägt
bereits Auth, die `management/ftp*`-Berechtigung, die Quota (10/Stunde), den
Audit-Trail und das Show-once-Kontrakt — eine zweite Tür mit derselben Disziplin
wäre mehr Fläche für Fehler als eine Tür mit zwei Fällen.

| `ftp_account_status` | Verhalten | HTTP |
|---|---|---|
| `pending` | **Provisionieren** (`provisionAndShow()`), Passwort einmal zeigen | 200 |
| `active` | **Rotieren** (unverändert) | 200 |
| `revoked` | **Ablehnen**, gar kein SFTPGo-Aufruf | 409 |
| `error` | **Ablehnen**, gar kein SFTPGo-Aufruf | 409 |

Zwei Punkte sind Last:

- **`revoked` ist per Klick nicht wiederzubeleben.** Der Entzug war eine
  bewusste Entscheidung (Rollenverlust). Ein Reset, der das Konto anlegt, gäbe
  einem entzogenen Fotografen mit einem Klick wieder funktionierenden
  SFTPGo-Zugang — die Absicherung wäre wertlos. Die Prüfung steht deshalb
  **innerhalb des `try`**, vor dem SFTPGo-Aufruf: der `catch` auditiert jede
  von dort kommende Ablehnung als **fehlgeschlagenen Versuch**, und genau das ist
  der interessante Eintrag. Vor dem `try` würde derselbe Klick unauditiert
  bleiben — die Lücke, die der Trail schließen soll.
- **`error` bleibt `error`.** Der letzte Provisionierungsversuch schlug fehl,
  der Zustand ist unbekannt. Ein Reset, der ihn überschreibt, rät und meldet
  danach ein Passwort für einen Account, dessen Existenz niemand belegen kann.

Nur `pending` provisioniert, und dabei **ohne** Audit-Zeile: eine Anlage ist kein
Reset, `ftp_password_resets` ist der Reset-Trail. Die Quota gilt auch für diesen
Zweig — sonst wäre die Erstanlage ein Weg um sie herum. **409 statt 422**, weil
nichts an der Anfrage falsch ist: der Account ist in einem Zustand, der keinen
Zugang erlaubt, und es gibt keinen zu korrigierenden Input. Fail-closed bleibt
beidseits: ist SFTPGo nicht erreichbar, bleibt der Status `pending`, weil
`provisionAndShow()` `active` erst nach einer erfolgreichen Annage schreibt.

Denselben Guard trägt der Slug-Wechsel jetzt auch: `deleteUser()` nur bei
`STATUS_ACTIVE`, wie in `revoke()`. Er schränkt ein, *ob* gelöscht wird, nicht
ob ein Fehler toleriert wird — `active` plus nicht erreichbares SFTPGo bricht
den Wechsel weiterhin ab.

**Reihenfolge im Frontend:** `FtpAccountStatus` ist eine abschließende Union, und
die Statusanzeige ist ein `Record<FtpAccountStatus, …>` statt einer if-Kette mit
Fallback. Der Unterschied ist nicht kosmetisch: die frühere Form endete in „alles
andere ist aktiv" und hätte `revoked` als **Konto aktiv** angezeigt — die eine
Auskunft, die ein Fotograf über ein nicht funktionierendes Konto nie bekommen
darf. Als Record ist ein neuer Status ein Compile-Fehler statt einer stillen
Lüge.

### 7.17 Zielordner der Kamera

Stand 2026-09-26. Canons Assistent bietet im Schritt *Zielordner* neben
`Stammverzeichnis` ein eigenes `Ordner wählen`. Der Wert kommt deshalb aus der
Konfiguration und nicht aus einem festen Text: `FTP_UPLOAD_PATH`, Default `/`.

**Kein Schema.** Der Zielordner ist Deployment-Konfiguration, keine Eigenschaft
des Fotografen — es gibt genau ein Konto je Marke und höchstens eine
Zielgalerie, also braucht der Pfad keine eigene Spalte. `/` bleibt der korrekte
Wert, solange eine Zielgalerie alles nimmt, was ankommt.

**Zwei Richtungen, zwei Härten.** Das ist der Kern:

- **Anzeige streng:** Das Value Object validiert den Pfad (`/` oder
  `/segment/segment`, kein `..`) und meldet bei einem malformierten Wert
  `path: null`. `isConfigured()` zählt den Zielordner mit, sonst zeigte die
  Oberfläche eine vollständige Verbindung ohne Zielordner — und der Fotograf
  würde das Feld in der Kamera raten.
- **Import permissiv:** `resolvedUploadPath()` fällt bei einem malformierten Wert
  auf die Konto-Wurzel zurück, und `getInboxPath()` hängt den Ordner an. Ein
  Import, der zu viel liest, kann Dateien retten; einer, der zu wenig liest,
  verliert sie still.

**Der Fehlermodus, der die ganze Sorgfalt erklärt:** Weicht der Zielordner in der
Kamera vom konfigurierten Wert ab, landet der Upload in einem Verzeichnis, das
der Import nie liest. Das Verzeichnis ist der einzige Fehler, der wie ein
**leerer Posteingang** aussieht und nicht wie ein Fehler — deshalb steht der
Hinweis sowohl im Deployment-Runbook als auch auf der Einrichtungsseite im UI.
Der Import ist nicht rekursiv (`glob(...)`), also bleibt auch Canons
„Verzeichnisstruktur" der zweite Weg in denselben stillen Fehlermodus.
