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

**Auslassungs-Hinweis (beide Varianten):** Fotos, die nicht im Bogen landen,
werden benannt (Anzahl + Grund) statt stillschweigend verworfen — Details in
§6. Der Hinweis ist PII-frei (nur Zählwerte und Grundbezeichnungen). In der
externen Variante zählen ausschließlich `public`-Fotos als Kandidaten; interne
Fotos werden weder gezählt noch erwähnt.

## 3. Technik

- Service `App\Services\ModelContactSheetService` (Datenselektion je Variante + Bild-Auflösung + Render).
- Bilder via `ModelFileStore::getDecrypted()` → `data:<mime>;base64,…` (keine öffentliche URL, keine Klartext-Ablage). WebP → GD-JPEG-Normalisierung; `enable_remote => false` (keine externen Requests). PDFs mit `output(['compress' => 0])` (Content-Stream inspizierbar).
- Labels/Codes ausschließlich aus `ModelQuestionnaire` (keine duplizierte Farb-/Label-Logik).
- View `resources/views/pdf/model-contact-sheet.blade.php` (A4, Brand-Header, Kachel-Grid, Fakten, Matrix, Answers-Sektionen).
- Auslassungs-Zählung: `ModelContactSheetService::selectPhotos()` liefert die Fotos **und** die Auslassungs-Statistik (`OMISSION_*`, `OMISSION_PRECEDENCE`, `OMISSION_LABELS`) an die View; der Hinweis steht direkt unter dem Foto-Block.

## 4. Frontend

- `ModelDetailModal`: zwei Aktionen „Contact Sheet (intern)" / „Contact Sheet (extern)" in der `modal-action`-Leiste (Danger-Zone unberührt), nur für Admin (`isAdmin`), Toast bei Erfolg/Fehler.
- Download: `apiDownload()` (gleicher 401-Refresh-/Fehlerpfad wie `fetcher`, `credentials: 'include'`) → Blob → `<a download>` + `revokeObjectURL`. Filename aus `Content-Disposition`, Fallback `model-<id>-<variant>-contact-sheet.pdf`.
- Tests: Vitest (URL/Blob/Filename/Fehler, Button-Rendering Admin/Nicht-Admin) + Playwright `@feature:model-export` (Download-Event, Status 200, Content-Type/Disposition, Dateiname `-internal-<Ymd>.pdf` / `-external-<Ymd>.pdf`).

## 5. Tests (DoD-Referenz)

- PHPUnit (`ModelContactSheetTest`): 403 Nicht-Admin · Brand-Isolation 404 · 422 fehlend/ungültig · intern enthält PII + beide Fotos + Geburtsdatum · extern enthält public-Foto/`stage_name`/Ort und **nicht** internal-Foto, Realname, E-Mail, Telefon, Adresse, Geburtsdatum, Agentur, Notiz, Original-Dateinamen · Wasserzeichen extern (wiederholt) / intern nicht · Content-Type + Disposition.
- Siehe auch: Profil-Update-Mail (`ModelProfileUpdatedMail`, Inviter via Customer→Act→Invite, Multi-Act-Auflösung über alle Acts, stiller Skip ohne Invite).
- Byte-/Decode-Budget: `ModelContactSheetTest` pinnt, dass der Cap die **persistierbare** Grenze ist (ein Foto zwischen Quelle-Gate und Budget erreicht noch den Decoder) und dass ein Legacy-Foto über dem Budget vor dem Decoder ausgelassen wird; `ModelFileImageBudgetTest` pinnt das Gate in `ModelFileStore::storeUpload()` (Re-Encode über Budget → 422, nichts auf Disk) und dass `size_bytes` die kodierte Größe ist.
- D-41 (Auslassungs-Hinweis): `ModelContactSheetTest` pinnt je Variante den sichtbaren Hinweis mit Anzahl und Grund, die Trennung von Prüfungs-Auslassung und `MAX_PHOTOS`-Limit, die Einmal-Zählung bei mehreren Gründen, das Fehlen des Hinweises ohne Auslassung und die PII-Freiheit (kein Dateiname im Hinweis). Die **Label-Zuordnung** ist je Grund gepinnt: `Byte-Limit` (über-cap Bytes), `Größen-/Pixel-Limit` (Kantenlänge über `MAX_IMAGE_DIMENSION` **und** Pixelzahl über `MAX_IMAGE_PIXELS`), `MIME-Prüfung`, `nicht dekodierbar` und `Datei nicht verfügbar`.
- D-41 (Eigenschaften, nicht nur Darstellung): Der PDF-Contract zeigt je Foto nur den *primären* Grund — ein sekundärer Grund wäre dort grundsätzlich nicht sichtbar. Zwei Eigenschaften sind deshalb am **internen Grundsatz** gepinnt, über die Test-Naht `ModelContactSheetService::inspectRasterImage()` (protected) und `readImageSize()` (protected, zählt Header-Parses): (1) ein über-cap Foto trägt **genau einen** Grund (`byte`) — kein sekundäres `dimensions` aus dem Budget und kein Header-Parse auf 20 MiB; (2) die externe Variante zählt und erwähnt interne Fotos **nicht** — ein internes Foto, das an der MIME-Prüfung scheitert, erscheint nur im internen Bogen als Auslassung, im externen Bogen weder als Auslassung noch als Dateiname.

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
- **Weglassen hat mehrere Gründe, nicht nur das Byte-Budget:**
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
  alle vier Gründe. Kein Fallback auf ungeprüfte Bytes. Seit D-41 werden
  diese Gründe im PDF benannt (siehe unten) — die Auslassung ist nicht mehr
  still.
- **Legacy-Bestand:** Dateien, die vor diesen Grenzen gespeichert wurden,
  werden fail-closed ausgelassen (kein Fallback auf ungeprüfte Bytes).
- **Ausgelassene Fotos werden benannt (D-41) — kein stilles Weglassen mehr:**
  `ModelContactSheetService::selectPhotos()` zählt je gerendertem Bogen, **wie
  viele** Fotos entfielen und **warum**; die Blade-View rendert daraus einen
  sichtbaren Hinweis („Nicht enthaltene Fotos: N“ plus eine Zeile je Grund) in
  **beiden** Varianten. Ein Bogen, in dem jedes Kandidaten-Foto eingebettet
  wurde, zeigt **keinen** Hinweis (kein „0“, keine Leermeldung).
  - **Zwei getrennte Kategorien:** (1) Fotos, die als Kandidaten eine Prüfung
    nicht bestanden haben, und (2) Fotos, die wegen `MAX_PHOTOS = 12` nie
    Kandidat waren. Ein Foto hinter dem Limit ist **keine** gescheiterte
    Prüfung und zählt nie gegen die Prüfungsgründe. In der externen Variante
    sind Kandidaten nur `public`-Fotos — interne Fotos werden weder gezählt
    noch erwähnt (gepinnt: ein internes Foto, das an der MIME-Prüfung
    scheitert, erscheint nur im internen Bogen als Auslassung; im externen
    Bogen weder als Auslassung noch als Dateiname).
  - **Gründe und Präzedenz** (`ModelContactSheetService::OMISSION_PRECEDENCE`,
    erster Treffer gewinnt): `unavailable` (Datei fehlt im Store bzw. Inhalt
    leer) → `byte` (Bytes über `PhotoProcessingService::MAX_IMAGE_BYTES`) →
    `decode` (kein parsebares Raster bzw. GD-Decode schlägt fehl) → `mime`
    (erkannte MIME ≠ gespeicherte `mime_type` bzw. kein unterstützter Typ) →
    `dimensions` (Kantenlänge/Pixelzahl über dem Bild-Budget). Ein Foto mit
    mehreren Gründen zählt **genau einmal** unter seinem primären Grund;
    `byte` steht vorn, weil der Byte-Cap die erste Prüfung der Pipeline ist,
    `decode` vor `mime`, weil ein nicht parsebares Raster die MIME-Abweichung
    erst verursacht (der MIME-Befund wäre dann eine Folge, keine Ursache).
  - **Der Byte-Cap bricht sofort ab:** `inspectRasterImage()` prüft die
    Bytegröße **zuerst** und kehrt bei über-cap Bytes mit dem einzelnen Grund
    `byte` zurück — ohne Header-Parse auf 20 MiB und ohne den sekundären
    `dimensions`-Grund, den `PhotoProcessingService::isWithinImageBudget()`
    (das den Byte-Cap ebenfalls prüft) sonst zusätzlich liefern würde. Das
    entspricht der Prüf-Reihenfolge vor D-41; die restlichen Gründe werden
    weiterhin ohne Decoder-Aufruf ermittelt. Gepinnt ist das am **internen
    Grundsatz** (`inspectRasterImage()`), nicht an der Darstellung: der
    PDF-Contract zeigt je Foto ohnehin nur den primären Grund, ein
    sekundärer Grund wäre dort nicht sichtbar.
  - **PII-Freiheit:** Der Hinweis enthält ausschließlich Zählwerte und
    Grundbezeichnungen (`OMISSION_LABELS`) — keine Dateinamen, keine
    Kunden-Daten, keine Foto-Kennungen. Das gilt auch für die externe
    Variante, die ein PII-minimales Blatt bleibt.
  - **Datenvertrag der View:** `selectPhotos()` liefert `omissions` mit
    `total`, `checkTotal`, `limitTotal`, `title`, `checkLines` (eine Zeile je
    Prüfungsgrund) und `limitLine` (Zeile für das Foto-Limit). Die View rendert
    beide Kategorien als getrennte Listenpunkte unter der Überschrift
    „Nicht enthaltene Fotos: N“ — es gibt keinen Schlüssel, den die View
    nicht auswertet.
  - **Unveränderte Garantien:** Der Hinweis fügt **Text** hinzu, keine
    Data-URIs. Die Byte-Gates (10/20 MiB), die Vorprüfung **vor** dem
    Decoder (die „keine ungeprüften Bytes“-Garantie), das
    `MAX_PHOTOS`-Speicher-Limit und das DomPDF-Speicherprofil bleiben
    unverändert. Die Ermittlung der Gründe wertet die Prüfungen deshalb
    **ohne** Decoder-Aufruf aus: Bytes, die eine Vorprüfung nicht bestehen,
    erreichen GD nach wie vor nie.
- **GD als deklarierte Plattformvoraussetzung (D-42):**
  `imageDataUri()` gibt `null` zurück, wenn `imagecreatefromstring()` fehlt
  (und für WebP zusätzlich `imagejpeg()`), und JPEG/PNG werden nur **nach**
  einem erfolgreichen GD-Decode als Data-URI weitergereicht. Ohne GD werden
  die Fotos deshalb **nicht eingebettet** — sie fallen aus, werden aber seit
  D-41 im PDF **als ausgelassen ausgewiesen** („N Fotos ausgelassen: nicht
  dekodierbar“) und nicht mehr stillschweigend verworfen. Die Prod- und
  E2E-Images bringen GD mit (`grep -n 'gd' deployment/Dockerfile`;
  `deployment/Dockerfile.e2e` baut auf demselben `portal-base:8.5`).
  `backend/composer.json` deklariert `ext-gd` deshalb als
  Plattformvoraussetzung (`"ext-gd": "*"`, neben `ext-zip`): die
  Gallery-Pipeline (`app/Services/ImageProcessor.php`) nutzt GD bereits
  voraussetzungslos, und eine Installation ohne GD scheitert jetzt sofort
  statt zur Laufzeit. CI und Prod installieren mit
  `--ignore-platform-reqs`, die Deklaration greift dort also nicht in den
  Installationsweg ein — sie dokumentiert die Voraussetzung und macht sie für
  normale `composer install`-Läufe überprüfbar.
- `MAX_PHOTOS = 12` für beide Varianten (Speicher-Limit; interne „alle Fotos"-Zusage endet hier).
- `whereNotNull('invited_by')` in `notifyInviter()` wirkungslos (Spalte NOT NULL) — defensiv, kein Funktionsproblem.
- `Mail::queue()` nach Commit: Queue-/Transport-Fehler bei `sync`-Queue → 500 trotz gespeichertem Profil (konsistent mit bestehendem Deferred-Mail-Flow).
