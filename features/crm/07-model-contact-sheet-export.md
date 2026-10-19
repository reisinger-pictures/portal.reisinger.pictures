# 07 — Model Contact Sheet Export (PDF)

> **Current SOLL / implemented (reviewed 2026-09-24).** Phase 1+2 (Einzelprofil
> intern/extern) ist umgesetzt und nutzt die verschlüsselten Dateien aus
> `features/crm/06-model-profile-iteration.md`. Phase 3 (Bulk) + Phase 4
> (signierter Extern-Link) bleiben Future.
> Plan: `~/.opencode/plan/pdf-contact-sheet-export.md`.

## 1. API-Vertrag

```
GET /api/management/models/{id}/contact-sheet?variant=internal|external
```

- Auth: `AdminOnly` + Brand-Scope (`ModelProfile::forBrand($brand)->findOrFail()` → 404 bei fremder Brand, 403 für Nicht-Admin).
- `variant` Pflicht, nur `internal|external`, sonst 422.
- Response: PDF-Stream (`Content-Type: application/pdf`), `Content-Disposition: attachment; filename="model-<id>-<variant>-<Ymd>.pdf"`.
- Audit-Log `model.contact_sheet.export` mit `model_profile_id`/`customer_id`/`variant`/`user_id` — PII-frei.
- Rate-Limit auf Export-Endpunkten (Standard Admin-Throttling).
- `{id}` = Model-Profile-ID (`model.id`), nicht `customer_id`.

## 2. Datenselektion

**Intern** (Admin/Casting): alle Fotos (public + internal, max. 12 — bewusstes Speicher-Limit), Name/Künstlername, Kontakt (E-Mail/Telefon/Adresse), Geburtsdatum/Alter, Kunden-ID, Altersnachweis-Status (`age_proof_uploaded_at`, nie das Dokument), voller `answers`-Snapshot gruppiert in Formular-Sektionen, Erfahrungs-/Bereitschafts-Matrix inkl. Stock.

**Extern** (Kunden-Weitergabe): **nur `visibility = public`**; Anzeigename = `stage_name` oder generisch `Model` (**nie** Realname-Fallback); Ort; Kategorien + Erfahrung/Bereitschafts-Matrix. **Explizit ausgeschlossen:** interne Fotos, `original_name`-Captions (stattdessen generisch `Foto 1..N`), Geburtsdatum, Ausweis-Status, Kontakt-/Adressdaten, Agentur, Notizen, interne Keys.

**Wasserzeichen (nur extern):** wiederholtes Diagonal-Wasserzeichen mit Brand-Name + `Nur zur Ansicht`-Marker, auf jeder Seite (DomPDF `position: fixed`). Intern ohne Wasserzeichen.

## 3. Technik

- Service `App\Services\ModelContactSheetService` (Datenselektion je Variante + Bild-Auflösung + Render).
- Bilder via `ModelFileStore::getDecrypted()` → `data:<mime>;base64,…` (keine öffentliche URL, keine Klartext-Ablage). WebP → GD-JPEG-Normalisierung; `enable_remote => false` (keine externen Requests). PDFs mit `output(['compress' => 0])` (Content-Stream inspizierbar).
- Labels/Codes ausschließlich aus `ModelQuestionnaire` (keine duplizierte Farb-/Label-Logik).
- View `resources/views/pdf/model-contact-sheet.blade.php` (A4, Brand-Header, Kachel-Grid, Fakten, Matrix, Answers-Sektionen).

## 4. Frontend

- `ModelDetailModal`: zwei Aktionen „Contact Sheet (intern)" / „Contact Sheet (extern)" in der `modal-action`-Leiste (Danger-Zone unberührt), nur für Admin (`isAdmin`), Toast bei Erfolg/Fehler.
- Download: `apiDownload()` (gleicher 401-Refresh-/Fehlerpfad wie `fetcher`, `credentials: 'include'`) → Blob → `<a download>` + `revokeObjectURL`. Filename aus `Content-Disposition`, Fallback `model-<id>-<variant>-contact-sheet.pdf`.
- Tests: Vitest (URL/Blob/Filename/Fehler, Button-Rendering Admin/Nicht-Admin) + Playwright `@feature:model-export` (Download-Event, Status 200, Content-Type/Disposition, Dateiname `-internal-<Ymd>.pdf` / `-external-<Ymd>.pdf`).

## 5. Tests (DoD-Referenz)

- PHPUnit (`ModelContactSheetTest`): 403 Nicht-Admin · Brand-Isolation 404 · 422 fehlend/ungültig · intern enthält PII + beide Fotos + Geburtsdatum · extern enthält public-Foto/`stage_name`/Ort und **nicht** internal-Foto, Realname, E-Mail, Telefon, Adresse, Geburtsdatum, Agentur, Notiz, Original-Dateinamen · Wasserzeichen extern (wiederholt) / intern nicht · Content-Type + Disposition.
- Siehe auch: Profil-Update-Mail (`ModelProfileUpdatedMail`, Inviter via Customer→Act→Invite, Multi-Act-Auflösung über alle Acts, stiller Skip ohne Invite).
- Byte-/Decode-Budget: `ModelContactSheetTest` pinnt, dass der Cap die **persistierbare** Grenze ist (ein Foto zwischen Quelle-Gate und Budget erreicht noch den Decoder) und dass ein Legacy-Foto über dem Budget vor dem Decoder ausgelassen wird; `ModelFileImageBudgetTest` pinnt das Gate in `ModelFileStore::storeUpload()` (Re-Encode über Budget → 422, nichts auf Disk) und dass `size_bytes` die kodierte Größe ist.

## 6. Akzeptierte Grenzen

- **Byte-Budget der eingebetteten Fotos:**
  `ModelContactSheetService::inspectRasterImage()` prüft gegen
  `PhotoProcessingService::MAX_IMAGE_BYTES` — dieselbe Grenze, die
  `ModelFileStore::storeUpload()` nach dem GD-Re-Encode für **gespeicherte**
  Fotos durchsetzt, nicht das 10-MB-Gate der Quelldatei. Ein konform
  gespeichertes Foto kann am Cap deshalb nicht scheitern, auch wenn der
  Re-Encode den Upload legal aufbläht. Die Garantie lautet **keine
  ungeprüften Bytes** (Byte-/MIME-/Dimensions-Vorprüfung plus erfolgreicher
  GD-Decode), nicht „keine Rohbytes": JPEG/PNG werden nach dem Decode
  unverändert als Data-URI weitergereicht.
- **Stilles Weglassen hat mehrere Gründe, nicht nur das Byte-Budget:**
  `inspectRasterImage()` lässt ein gespeichertes Foto fail-closed aus, wenn
  (a) die Bytes über `PhotoProcessingService::MAX_IMAGE_BYTES` liegen, (b)
  `PhotoProcessingService::isWithinImageBudget()` die Dimension-/Pixel-Grenze
  verletzt (Kantenlänge `MAX_IMAGE_DIMENSION`, Pixel `MAX_IMAGE_PIXELS` —
  `grep -n 'MAX_IMAGE_DIMENSION =\|MAX_IMAGE_PIXELS ='
  backend/app/Services/PhotoProcessingService.php`), (c) die aus den Bytes
  gelesene MIME nicht mit der in der DB gespeicherten `mime_type`
  übereinstimmt (`inspectRasterImage()` prüft `$detectedMime !== $mime`), oder
  (d) die Bytes gar kein dekodierbares JPEG/PNG/WebP sind. **(a)–(d) waren vor
  dieser Änderung alle vier noch einbettbar** — die frühere Fassung dieses
  Absatzes nannte nur (b) und (c) und war damit zu eng:
  `git show 29afe5b25787dd9a86e24565b25b17ba999b0e88:backend/app/Services/ModelContactSheetService.php`
  zeigt an diesem fest verankerten Commit weder einen Byte-Cap noch
  `isWithinImageBudget()` noch den MIME-Abgleich noch eine Decode-Bedingung,
  und `imageDataUri()` reichte JPEG/PNG ohne Decode sowie ein undekodierbares
  WebP als rohe Data-URI durch (ebd. die Zeile `return 'data:'.$mime.';base64,'…`).
  Der Commit ist bewusst fest genannt und **nicht** `HEAD`: `HEAD` wandert mit
  jedem Merge und würde die Aussage später widerlegen (nach dem Merge dieses
  Features zeigt `git show HEAD:…` den neuen, geprüften Stand). Neu sind also
  alle vier Gründe. Kein Fallback auf ungeprüfte Bytes.
- **Legacy-Bestand:** Dateien, die vor diesen Grenzen gespeichert wurden,
  werden fail-closed ausgelassen (kein Fallback auf ungeprüfte Bytes). Es gibt
  dafür **kein sichtbares Signal im PDF** — der Blade-Contract kennt keinen
  Zähler für ausgelassene Fotos; bewusst nicht in dieser Änderung.
- **GD als akzeptierte Umgebungsgrenze (nicht in `composer.json` deklariert):**
  `imageDataUri()` gibt `null` zurück, wenn `imagecreatefromstring()` fehlt
  (und für WebP zusätzlich `imagejpeg()`), und JPEG/PNG werden nur **nach**
  einem erfolgreichen GD-Decode als Data-URI weitergereicht. Ohne GD ließe der
  Kontaktbogen damit **alle** Fotos stillschweigend aus. Die Prod- und
  E2E-Images bringen GD mit (`grep -n 'gd' deployment/Dockerfile`;
  `deployment/Dockerfile.e2e` baut auf demselben `portal-base:8.5`). Eine
  `ext-gd`-Deklaration in `backend/composer.json` wurde **bewusst nicht**
  ergänzt: die gesamte Galerie-Pipeline (`app/Services/ImageProcessor.php`)
  nutzt GD bereits voraussetzungslos, die Runtime-Annahme ist also älter als
  dieses Feature; die Deklaration würde eine Plattform-Anforderung neu
  einführen, die CI (`composer install --ignore-platform-reqs`) und Prod-Image
  ohnehin erfüllen, und dafür `composer.lock` (content-hash) anfassen.
- `MAX_PHOTOS = 12` für beide Varianten (Speicher-Limit; interne „alle Fotos"-Zusage endet hier).
- `whereNotNull('invited_by')` in `notifyInviter()` wirkungslos (Spalte NOT NULL) — defensiv, kein Funktionsproblem.
- `Mail::queue()` nach Commit: Queue-/Transport-Fehler bei `sync`-Queue → 500 trotz gespeichertem Profil (konsistent mit bestehendem Deferred-Mail-Flow).
