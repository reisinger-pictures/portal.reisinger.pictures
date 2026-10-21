# 07 — Model Contact Sheet Export (PDF)

> **Current SOLL / implemented (reviewed 2026-09-24; Phase 3 + Phase 4 ergänzt
> 2026-10-21).** Phase 1+2 (Einzelprofil intern/extern), Phase 3
> (Bulk/Ergebnisliste) und Phase 4 (signierter Extern-Link) sind umgesetzt.
> Plan: `~/.opencode/plan/pdf-contact-sheet-export.md`.

## 1. API-Vertrag

```
GET /api/management/models/{id}/contact-sheet?variant=internal|external   (single)
POST /api/management/models/contact-sheet                                   (bulk)
POST /api/management/models/contact-sheet/share                             (share, Phase 4)
GET /api/public/model-contact-sheet/{token}                                (public, Phase 4)
```

### 1.1 Einzelprofil

- Auth: `AdminOnly` + Brand-Scope (`ModelProfile::forBrand($brand)->findOrFail()` → 404 bei fremder Brand, 403 für Nicht-Admin).
- `variant` Pflicht, nur `internal|external`, sonst 422.
- Response: PDF-Stream (`Content-Type: application/pdf`), `Content-Disposition: attachment; filename="model-<id>-<variant>-<Ymd>.pdf"`.
- Audit-Log `model.contact_sheet.export` mit `model_profile_id`/`customer_id`/`variant`/`user_id` — PII-frei.
- **Drosselung:** dieser Einzelbogen (Phase 1/2) ist **nicht** gedrosselt — die Management-Routen-Gruppe trägt keinen `throttle`. Die **neuen** Export-Routen der Phase 3/4 (Bulk §1.2, Share §1.3.1) sind dagegen mit `throttle:api` gedrosselt, der öffentliche Auslieferungs-Endunkt der Phase 4 ebenfalls (§1.3.2).
- `{id}` = Model-Profile-ID (`model.id`), nicht `customer_id`.

### 1.2 Sammlung (Phase 3)

```
POST /api/management/models/contact-sheet
Body: { "ids": ["<uuid>", …], "variant": "internal|external" }
```

- Route-Name: `api.management.models.contact-sheet.bulk`.
- Auth: `AdminOnly` + Brand-Scope — identisch zum Einzelbogen.
- Validierung:
  - `variant` nur `internal|external`, sonst 422.
  - `ids` muss ein **nicht leeres Array von Strings** sein, sonst 422
    („Es muss mindestens eine Model-ID angegeben werden.").
  - Die **eindeutige** Anzahl (`array_unique`) der IDs darf
    `ModelContactSheetService::MAX_BULK_PROFILES = 25` nicht übersteigen,
    sonst 422 („Maximal 25 Models pro Contact Sheet."). Duplikate zählen
    einmalig — das Limit gilt für die eindeutige Auswahl, nicht für die
    Rohliste.
- **Fail closed:** jede ID muss zu einem `ModelProfile` der Brand des Akteurs
  auflösen. Löst eine ID nicht auf (fremde Brand, unbekannt, gelöscht), bricht
  der **gesamte** Export mit **404** ab — ein fremdes Profil wird nie
  stillschweigend aus der Auswahl entfernt.
- `authorizeSensitiveProfile()` gilt **je Profil**: ein inaktives Profil
  (13–15 Monate ohne Bestätigung) ergibt für einen Regular Admin **403**; ein
  Super-Admin darf es exportieren.
- Response: PDF-Stream (`Content-Type: application/pdf`),
  `Content-Disposition: attachment; filename="models-contact-sheet-<variant>-<Ymd>.pdf"`.
- **Drosselung:** `throttle:api` — derselbe benannte Limiter wie der öffentliche
  Auslieferungs-Endpunkt (§1.3.2): `Limit::perMinute(config('app.throttle_api',
  120))` je Akteur (sonst je IP). Der Bulk-Export rendert bis zu 25 Profile mit
  bis zu 32 MiB eingebetteten Bytes und ist damit der teuerste
  Management-Endpunkt — er ist deshalb ausdrücklich gedrosselt (die
  Bestandsrouten der Phase 1/2 bleiben unangetastet).
- Audit-Log (PII-frei): eine Zeile `model.contact_sheet.export` je Profil
  (`model_profile_id`/`customer_id`/`variant`/`user_id`) **plus** eine Zeile
  `model.contact_sheet.export_bulk` mit `count` (Anzahl der Profile),
  `variant` und `user_id`.

### 1.3 Signierter Extern-Link (Phase 4)

Der Extern-Link ist der **login-freie** Weg, mit dem ein Kunde/Agentur den
Bogen bekommt: der Admin erzeugt einen signierten, ablaufenden Link, der
Empfänger öffnet ihn ohne Portal-Login und lädt das PDF. Der Token im Pfad
ist das **Bearer-Credential** — es gibt keine Session, keinen zweiten
Auth-Mechanismus.

#### 1.3.1 Ausstellung (Admin)

```
POST /api/management/models/contact-sheet/share
Body: { "ids": ["<uuid>", …] }        # optional: "variant": "external"
```

- Route-Name: `api.management.models.contact-sheet.share`.
- Auth: `AdminOnly` + Brand-Scope — identisch zum Einzel- und Bulk-Bogen
  (403 für Nicht-Admin, 404 für ein fremdes/unbekanntes Profil).
- Validierung:
  - `ids` muss ein **nicht leeres Array von Strings** sein, sonst 422
    („Es muss mindestens eine Model-ID angegeben werden.").
  - Die **eindeutige** Anzahl der IDs darf
    `ModelContactSheetService::MAX_BULK_PROFILES = 25` nicht übersteigen,
    sonst 422 („Maximal 25 Models pro Contact Sheet.").
  - `variant` ist **optional** und nur `external` erlaubt. Eine andere
    Variante (insbesondere `internal`) ergibt **422** — die interne Variante
    enthält PII und ist nie teilbar.
- **Fail closed:** jede ID muss zu einem `ModelProfile` der Brand des Akteurs
  auflösen, sonst bricht der **gesamte** Vorgang mit **404** ab.
- `authorizeSensitiveProfile()` gilt **je Profil**: ein inaktives Profil
  (13–15 Monate ohne Bestätigung) ergibt für einen Regular Admin **403**; ein
  Super-Admin darf es teilen.
- **Brand-Voraussetzung (fail closed):** die Ausstellung ist nur für Brands mit
  einem `Brand`-Enum-Fall möglich — also für Brands, die das Portal unterstützt.
  Sonst **422** („Für diese Brand kann kein Extern-Link erstellt werden. Der
  Link ist nur für Brands verfügbar, die das Portal unterstützt.") und **kein**
  Token. Grund: die Auslieferung prüft die Token-Brand gegen die **Host-Brand**
  (`BrandRegistry::currentIdOrNull()`), und die ist im Request-Pfad immer ein
  Enum-Fall (`BrandContextMiddleware` → `BrandRegistry::fromHost()` →
  `Brand::tryFrom($config->id) ?? Brand::B2B`). Eine Brand-ID außerhalb des
  Enums (Legacy, z. B. `srp`) kann deshalb **nie** die Host-Brand eines Links
  sein — der Token bekäme immer 404. Besser ein abgelehnter Vorgang als ein
  Link, der nie funktioniert. Die Meldung nennt dieses Kriterium bewusst
  **nicht** als „eigene Portalinstanz": eine Legacy-Brand wie `srp` hatte
  eine eigene Instanz, aber keinen Enum-Fall — die alte Formulierung
  beschrieb also ein anderes Kriterium als das durchgesetzte.
- **Drosselung:** `throttle:api` — derselbe benannte Limiter wie der öffentliche
  Auslieferungs-Endpunkt (§1.3.2): `Limit::perMinute(config('app.throttle_api',
  120))` je Akteur (sonst je IP).
- Response (**201**): `{ "url": "<frontend-url>/kontaktbogen/<token>",
  "expires_at": "<ISO-8601>", "variant": "external", "count": <Anzahl> }`.
  Die `url` zeigt auf die öffentliche Frontend-Route (siehe §4.2).
  - **Auflösung der Frontend-URL** (`BrandRegistry::frontendUrl()`): **Vorrang
    hat die Umgebungs-URL** (`FRONTEND_URL` / `config('app.frontend_url')`) —
    sie ist per Default gesetzt und damit in aller Regel die Frontend, auf die
    der Link zeigt. Erst **ohne** Umgebungs-URL greift die Brand-Auflösung über
    `configForBrand()` (dort die `frontend_url` der Brand, sonst deren erster
    Hostname); eine Brand **ohne** eigenen Config-Eintrag fällt auf den
    allgemeinen Default (`config('app.url')`) zurück. Eine garantiert
    brand-spezifische Frontend gibt es damit **nicht**.
- Audit-Log (PII-frei): eine Zeile `model.contact_sheet.share_link.created` mit
  `link_id` (UUID), `count`, `brand` und `user_id`. **Der Token steht in
  keinem Log der Anwendung, keinem Audit-Kontext und keiner Fehlermeldung** —
  er ist das Credential, die `link_id` ist der PII-freie Audit-Griff. Er ist
  Bestandteil der **URL** und steht damit im **Access-Log des Webservers
  bzw. eines vorgelagerten Proxys**, sobald dieser Zugriffe protokolliert —
  dieselbe Abwägung wie bei den bestehenden Token-Routen
  (`/api/model-profil/{token}`, `/api/contracts/join/{token}`).

#### 1.3.2 Auslieferung (öffentlich)

```
GET /api/public/model-contact-sheet/{token}
```

- Route-Name: `api.public.model-contact-sheet.show`.
- **Ohne Authentifizierung** (`auth:api` läuft nicht), gedrosselt über
  `throttle:api` — wie die übrigen öffentlichen Lese-Endpunkte und wie die
  Management-Export-Routen (§1.2, §1.3.1): `Limit::perMinute(config('app.throttle_api',
  120))` je IP (der Endpunkt ist anonym — es gibt keinen Akteur).
- **Brand-Bindung:** der Token trägt die Brand bei der Ausstellung; der
  Endpunkt prüft sie gegen die **Host-Brand** (`BrandRegistry::currentIdOrNull()`,
  gesetzt durch `BrandContextMiddleware`). Eine fremde Brand → **404**.
- **Fail closed / kein Token-Orakel:** jeder Fehlerfall (ungültig, manipuliert,
  abgelaufen, falsche Brand, kein Profil mehr lieferbar) antwortet mit
  **404** und derselben generischen Meldung („Dieser Link ist ungültig oder
  abgelaufen."). Es gibt keinen unterscheidbaren Fehlergrund.
- Geliefert wird **immer die externe Variante** — die interne Variante ist
  nie teilbar und wird auch nicht gerendert.
- **Brand-Scope der Auswahl:** die Profile werden mit
  `ModelProfile::forBrand($brand)` geladen. Profile, die inzwischen gelöscht
  oder verschoben wurden, werden **übersprungen und im PDF benannt**
  (Anzahl, PII-frei) — ein stilles Verengen der Auswahl ist nicht erlaubt.
  Ist **kein** Profil mehr lieferbar, antwortet der Endpunkt mit 404.
- Response: PDF-Stream (`Content-Type: application/pdf`),
  `Content-Disposition: attachment; filename="models-contact-sheet-external-<Ymd>.pdf"`.
- Audit-Log (PII-frei): eine Zeile
  `model.contact_sheet.share_link.accessed` mit `link_id`, `count` (gelieferte
  Profile) und `brand` — **ohne** `user_id` (es gibt keine Session) und
  **ohne** den Token.

## 2. Datenselektion

**Intern** (Admin/Casting): alle Fotos (public + internal, max. 12 — bewusstes Speicher-Limit), Name/Künstlername, Kontakt (E-Mail/Telefon/Adresse), Geburtsdatum/Alter, Kunden-ID, Altersnachweis-Status (`age_proof_uploaded_at`, nie das Dokument), voller `answers`-Snapshot gruppiert in Formular-Sektionen, Erfahrungs-/Bereitschafts-Matrix inkl. Stock.

**Extern** (Kunden-Weitergabe): **nur `visibility = public`**; Anzeigename = `stage_name` oder generisch `Model` (**nie** Realname-Fallback); Ort; Kategorien + Erfahrung/Bereitschafts-Matrix. **Explizit ausgeschlossen:** interne Fotos, `original_name`-Captions (stattdessen generisch `Foto 1..N`), Geburtsdatum, Ausweis-Status, Kontakt-/Adressdaten, Agentur, Notizen, interne Keys.

**Wasserzeichen (nur extern):** wiederholtes Diagonal-Wasserzeichen mit Brand-Name + `Nur zur Ansicht`-Marker, auf jeder Seite (DomPDF `position: fixed`). Intern ohne Wasserzeichen.

**Auslassungs-Hinweis (beide Varianten):** Fotos, die nicht im Bogen landen,
werden benannt (Anzahl + Grund) statt stillschweigend verworfen — Details in
§6. Der Hinweis ist PII-frei (nur Zählwerte und Grundbezeichnungen). In der
externen Variante zählen ausschließlich `public`-Fotos als Kandidaten; interne
Fotos werden weder gezählt noch erwähnt.

**Extern-Link (Phase 4):** die Auslieferung über den signierten Link nutzt
**ausschließlich die externe Datenselektion** — Realname, E-Mail, Telefon,
Adresse, Geburtsdatum, Altersnachweis-Status und interne Fotos erreichen den
Empfänger nicht. Der Bogen trägt das Wasserzeichen, und Profile, die nicht
mehr lieferbar sind, werden **benannt** (Anzahl, PII-frei) statt
stillschweigend weggelassen.

## 3. Technik

- Service `App\Services\ModelContactSheetService` (Datenselektion je Variante + Bild-Auflösung + Render).
- Bilder via `ModelFileStore::getDecrypted()` → `data:<mime>;base64,…` (keine öffentliche URL, keine Klartext-Ablage). WebP → GD-JPEG-Normalisierung; `enable_remote => false` (keine externen Requests). PDFs mit `output(['compress' => 0])` (Content-Stream inspizierbar).
- Labels/Codes ausschließlich aus `ModelQuestionnaire` (keine duplizierte Farb-/Label-Logik).
- View `resources/views/pdf/model-contact-sheet.blade.php` (A4, Brand-Header, Kachel-Grid, Fakten, Matrix, Answers-Sektionen).
- Auslassungs-Zählung: `ModelContactSheetService::selectPhotos()` liefert die Fotos **und** die Auslassungs-Statistik (`OMISSION_*`, `OMISSION_PRECEDENCE`, `OMISSION_LABELS`) an die View; der Hinweis steht direkt unter dem Foto-Block.

### 3.1 Sammlungs-Contact-Sheet (Phase 3)

- `ModelContactSheetService::renderCollection(array $profiles, string $variant): string` rendert die Auswahl als **ein** Dokument (A4) mit einem Kachel-Grid (2 Spalten). `render()` (Einzelprofil) bleibt unverändert.
- Die Kacheln nutzen **dieselbe** Datenselektion wie der Einzelbogen (`internalData()` / `externalData()`) — die Privacy-Trennung ist damit pro Variante identisch:
  - **intern:** Realname, Ort, E-Mail (kompakt) und das **Hauptfoto unabhängig von seiner Sichtbarkeit** (ersatzweise das erste Foto). Zusätzlich die kompakte Erfahrungs-/Bereitschafts-Matrix.
  - **extern:** **nur** der Künstlername (`stage_name`, **niemals** ein Realname-Fallback — ohne Künstlername bleibt der generische Platzhalter `Model`), Ort, **keine** Kontaktdaten und **ausschließlich öffentliche Fotos**: das Hauptbild, sofern es `public` ist, sonst das erste `public`-Foto, sonst **kein** Foto. Ein internes Foto ist nie Kandidat und wird weder eingebettet noch gezählt noch erwähnt.
- **Foto-Budget:** **ein** Foto je Profil
  (`ModelContactSheetService::COLLECTION_MAX_PHOTOS_PER_PROFILE = 1`) — ein
  bewusstes Memory-Limit. Damit enthält das Dokument höchstens
  `MAX_BULK_PROFILES = 25` Fotos. Die Einbettung läuft über **denselben**
  Prüfpfad wie der Einzelbogen (`inspectRasterImage()` → Byte-Cap → Decode →
  MIME → Dimensions-Budget → GD-Decode); es gibt keinen zweiten, schwächeren
  Bildpfad.
- **Byte-Budget der Sammlung:** das Foto-Limit begrenzt die *Anzahl* der
  Bilder, nicht ihre Größe — eingebettet werden die **Originalbytes**
  (JPEG/PNG unverändert). Deshalb gibt es zusätzlich
  `ModelContactSheetService::COLLECTION_MAX_TOTAL_PHOTO_BYTES = 32 * 1024 * 1024`
  (32 MiB) als Budget für die **eingebetteten Bytes** eines Dokuments. Ohne
  dieses Budget trägt eine Sammlung bis zu 25 Fotos mit je bis zu 20 MiB
  (~500 MiB Rohbytes / ~670 MiB Base64) in **einem** HTML-String — gegen das
  Prod-`memory_limit` von 1024M. Das Budget ist eine **zusätzliche** Grenze,
  keine schwächere Prüfung: der Foto-Prüfpfad bleibt unverändert. Ein Foto,
  das das Restbudget überschreitet, wird **nicht** eingebettet (die Data-URI
  entsteht gar nicht) und auf **Dokumentebene** benannt („N Foto(s)
  ausgelassen: Byte-Budget der Sammlung überschritten", PII-frei) — dasselbe
  Muster wie bei den nicht mehr lieferbaren Profilen. Der Grenzwert ist über
  die `protected`-Naht `ModelContactSheetService::collectionMaxTotalPhotoBytes()`
  erreichbar, damit der Test ohne 32-MiB-Fixture auskommt.
- **Auslassungs-Hinweis je Kachel (D-41):** fällt das Foto einer Kachel aus,
  nennt die Kachel Anzahl und Grund (PII-frei, `OMISSION_LABELS`). Die zwei
  Kategorien bleiben getrennt: gescheiterte Prüfung (`byte`/`decode`/`mime`/
  `dimensions`/`unavailable`) und das Foto-Limit der Kachel
  (`Foto-Limit (max. 1)`). In der externen Variante zählen nur `public`-Fotos
  — interne Fotos werden weder gezählt noch erwähnt.
- View `resources/views/pdf/model-contact-sheet-collection.blade.php` (A4,
  Brand-Header mit Logo, Wasserzeichen nur extern, zweispaltiges
  Kachel-Grid, Kachel-Index „Profil N von M" je Kachel).

### 3.2 Signierter Extern-Link (Phase 4)

- Service `App\Services\ModelContactSheetLinkService` — Ausstellung und
  Prüfung des Links. Es gibt **bewusst keine zweite** HMAC-/Base64-
  Eigenimplementierung: signiert und geprüft wird über den bestehenden
  `App\Services\OfferTokenService` — dasselbe HS256-Secret und derselbe
  Verifikationspfad wie der Login-Token.
- **Payload** (in `offer`): `type = 'model_contact_sheet'`,
  `model_profile_ids` (Array der Profil-IDs), `variant = 'external'`,
  `brand` (Brand bei der Ausstellung) und `link_id` (UUID). Keine Modelldaten,
  keine PII.
- **Ablauf:** `ModelContactSheetLinkService::TTL_DAYS = 7`. Der Ablauf steckt
  im `exp`-Claim des Tokens; der Server ist die einzige Autorität.
- **Strikte Payload-Prüfung** (`verify()`): Typ, Variante, nicht leere
  ID-Liste (höchstens `MAX_BULK_PROFILES`), ID-Format (String, nicht leer,
  höchstens 255 Zeichen), keine Duplikate, Brand (und — beim öffentlichen
  Endpunkt — die Übereinstimmung mit der Host-Brand) sowie eine gültige
  `link_id` (UUID). **Jede** Abweichung liefert `null` — es gibt keine
  teilweise akzeptierte Nutzlast.
- **Privacy-Regel:** die Variante ist **immer** `external`. Die interne
  Variante (Realname, Kontaktdaten, Altersnachweis-Status, interne Fotos) ist
  nicht teilbar; `verify()` lehnt einen Token mit anderer Variante ab, und der
  öffentliche Endpunkt rendert **nie** die interne Variante.
- **Der Token ist ein Bearer-Credential:** er erscheint **nur** in der
  Antwort an den Admin und in der URL. Er steht in keinem **Log der
  Anwendung**, in keinem Audit-Kontext und in keiner Fehlermeldung. Die
  Audit-Zeilen nennen stattdessen die `link_id` (eine UUID, selbst kein
  Credential). Da der Token Bestandteil der URL ist, steht er im
  **Access-Log des Webservers bzw. eines vorgelagerten Proxys**, sobald dieser
  Zugriffe protokolliert — dieselbe Abwägung wie bei den bestehenden
  Token-Routen (`/api/model-profil/{token}`, `/api/contracts/join/{token}`).
- Controller `App\Http\Controllers\ModelContactSheetPublicController` — der
  öffentliche Endpunkt ohne Authentifizierung. Fail-closed: Host-Brand muss
  passen, jeder Fehlerfall ergibt denselben 404, und nur die externe Variante
  wird gerendert.
- Das PDF entsteht über **denselben** Service und **dieselbe** Datenselektion
  wie der Bulk-Export (`renderCollection()`), Phase 3 mit dem
  Zusatzparameter `$omittedProfiles` — die ausgelassenen (gelöschten oder
  verschobenen) Profile werden **benannt**: „N Profil(e) nicht mehr
  verfügbar" (PII-frei, nur ein Zählwert). Die Datenselektion der externen
  Variante ist damit unverändert PII-frei (kein Realname, keine E-Mail, kein
  Telefon, keine Original-Dateinamen), inklusive Wasserzeichen.

## 4. Frontend

- `ModelDetailModal`: zwei Aktionen „Contact Sheet (intern)" / „Contact Sheet (extern)" in der `modal-action`-Leiste (Danger-Zone unberührt), nur für Admin (`isAdmin`), Toast bei Erfolg/Fehler.
- Download: `apiDownload()` (gleicher 401-Refresh-/Fehlerpfad wie `fetcher`, `credentials: 'include'`) → Blob → `<a download>` + `revokeObjectURL`. Filename aus `Content-Disposition`, Fallback `model-<id>-<variant>-contact-sheet.pdf`.
- Tests: Vitest (URL/Blob/Filename/Fehler, Button-Rendering Admin/Nicht-Admin) + Playwright `@feature:model-export` (Download-Event, Status 200, Content-Type/Disposition, Dateiname `-internal-<Ymd>.pdf` / `-external-<Ymd>.pdf`).

### 4.1 Auswahl in der Ergebnisliste (Phase 3)

- `ManagementModelsView`: jede Model-Karte trägt eine Auswahl-Checkbox
  (`data-testid="model-select-<id>"`). Die Karte ist dafür kein `<button>`
  mehr, sondern ein Wrapper-`div` mit dem Button darin — interaktiver Inhalt
  im `<button>` wäre ungültiges HTML und würde den Klick auf den
  Detail-Dialog schlucken.
- Auswahl-Zustand: echter `useState` (`selectedIds`), kein abgeleiteter State.
- Auswahl-Leiste (sichtbar ab 1 ausgewähltem Model): Anzahl der Auswahl,
  „Alle auswählen" (alle Modelle der aktuellen Filterauswahl) / „Auswahl
  aufheben" sowie „Ergebnis als Contact Sheet (intern)" und „… (extern)".
- Über dem Limit (`MODEL_CONTACT_SHEET_MAX_BULK = 25`, Spiegel des
  Server-Limits) sind die Export-Buttons deaktiviert, und ein Hinweis/Toast
  nennt die Grenze („Maximal 25 Models pro Contact Sheet."). Toast bei Erfolg
  und Fehler des Downloads.
- Logik `frontend/src/logic/modelContactSheet.ts`:
  `MODEL_CONTACT_SHEET_MAX_BULK`, `buildModelContactSheetBulkUrl()`,
  `fallbackContactSheetBulkFilename()` und
  `downloadModelContactSheetBulk(ids, variant)` — der Bulk-Export ist ein
  `POST` mit JSON-Body (`{ ids, variant }`); die IDs stehen bewusst **nicht**
  in der URL.
- Tests: Vitest (Bulk-URL, POST-Payload, Fallback-Dateiname, Limit-Konstante,
  Fehlerpfad) + Playwright `@feature:model-export` (zwei Modelle in der
  Liste auswählen → „Ergebnis als Contact Sheet (extern)" → Download-Event,
  Status 200, Content-Type `application/pdf`, Dateiname
  `models-contact-sheet-external-<Ymd>.pdf`).

### 4.2 Extern-Link (Phase 4)

- **Admin-UI:** zwei Erzeugungsstellen, beide nur für Admins:
  - `ManagementModelsView` — „Extern-Link erstellen" in der Auswahl-Leiste
    (gilt für die markierte Auswahl, 1..25 Modelle). Das Ergebnis erscheint
    unter der Leiste: die **URL** (read-only-Input), ein **Kopieren**-Button
    und das **Ablaufdatum** („Gültig bis: …").
  - `ModelDetailModal` — „Extern-Link erstellen" in der Aktionsleiste für das
    einzelne Profil; darunter der Block „Extern-Link" mit URL, Ablauf
    („Läuft ab: …") und Kopieren-Button.
  - Eine geänderte Auswahl verwirft die angezeigte URL — der Link gilt für
    die Auswahl, für die er erzeugt wurde. Das gilt auch für das **Leeren**
    der Auswahl („Auswahl aufheben"): `clearSelection()` läuft über
    `applySelection([])` und verwirft den Link mit — ein Link für eine
    Auswahl, die es nicht (mehr) gibt, wird nicht angezeigt.
  - **Der Dialog-Block gehört zum gezeigten Profil.** `ManagementModelsView`
    rendert `ModelDetailModal` mit `key={selected?.id ?? 'none'}`: ein
    Profilwechsel (und das Schließen) baut den Dialog neu auf und verwirft
    damit **jeden** profilbezogenen State — insbesondere den angezeigten
    Extern-Link. Ohne diesen Remount überlebte der `shareLink`-State des
    Dialogs den Profilwechsel (der Dialog unmountet beim Schließen nicht,
    `if (!model) return null` rendert nur null): der Block „Extern-Link"
    zeigte dann weiterhin die URL **und** das Ablaufdatum des ersten Profils,
    während die Kachel die Daten des zweiten zeigte — wer die URL kopierte,
    schickte das **falsche** Blatt (Künstlername, Ort, Foto eines anderen
    Models) an den Empfänger. Ein `useEffect` im Dialog statt des `key` hätte
    denselben Zweck, aber mit einem Frame, in dem die Daten des neuen Profils
    neben dem Link des alten stehen — also genau dem Zustand, der das falsche
    Blatt verschickt.
- **Öffentliche Route:** `/kontaktbogen/:token`
  (`frontend/src/ui/ModelContactSheetShareView.tsx`, ohne Login, ohne
  `ProtectedRoute`) — die Seite ist bewusst **datenfrei**: sie zeigt weder
  Namen noch Fotos noch Profildaten, nur den Download-Button und den
  Ablaufhinweis. Der Text weist darauf hin, dass der Link geheim zu halten
  ist.
- **Download:** `downloadModelContactSheetShare()` nutzt ein **einfaches**
  `fetch` mit `credentials: 'omit'` — der Endpunkt braucht weder Session noch
  einen 401-Refresh-Pfad, und ein ambienter Session-Cookie soll den
  credential-losen Aufruf nicht begleiten. Dateiname aus
  `Content-Disposition`, Fallback
  `models-contact-sheet-external-contact-sheet.pdf`.
- **Logik** `frontend/src/logic/modelContactSheet.ts`:
  `buildModelContactSheetShareCreateUrl()`,
  `buildModelContactSheetShareUrl(token)` (token URL-kodiert),
  `createModelContactSheetShare(ids)` (POST ohne `variant` — ein Extern-Link
  ist **immer** extern), `downloadModelContactSheetShare(token)`,
  `fallbackContactSheetShareFilename()` und `shareLinkExpiry(token)`.
  `shareLinkExpiry()` liest das `exp`-Claim des Tokens **lokal** — das ist
  nur ein **Anzeigehinweis** (Datum auf der öffentlichen Seite), die
  Gültigkeit entscheidet ausschließlich der Server. Der Clipboard-Helfer
  `copyTextToClipboard()` wurde aus `ModelDetailModal` hierher verschoben,
  damit beide Erzeugungsstellen ihn nutzen.
- Tests: Vitest (Share-URL mit kodiertem Token, POST-Payload ohne Variante,
  plain `fetch` ohne Credentials, Fallback-Dateiname, Fehlerpfad, Ablauf aus
  den Claims, kaputter Token) + Playwright `@feature:model-export`
  („Admin erstellt einen Extern-Link, der ohne Login zum Download führt" — ein
  **eigener Browser-Kontext** ohne Cookies und ohne Session öffnet die URL und
  lädt das PDF) + der **Dialog-Pfad** („Admin erstellt den Extern-Link im
  Detail-Dialog; der Gast lädt das Blatt ohne Login" — Dialog öffnen →
  „Extern-Link erstellen" → die URL erscheint → Gast-Kontext ohne Session →
  Download; danach schließt der Spec den Dialog und öffnet ein **anderes**
  Profil: der Link-Block darf dort **nicht** mehr den alten Link zeigen).
- **Vitest-Dateien** (Phase 3/4):
  - `frontend/src/logic/__tests__/modelContactSheet.test.ts` — Bulk-URL,
    POST-Payload, Fallback-Dateiname, Limit-Konstante, Share-URL mit kodiertem
    Token, POST ohne Variante, plain `fetch` ohne Credentials, Fallback-Dateiname
    des Share-Downloads, Fehlerpfad, Ablauf aus den Claims, kaputter Token.
  - `frontend/src/ui/management/__tests__/ManagementModelsView.test.tsx` — die
    Aktionen der Auswahlleiste (`offers the export and share actions to an
    admin`, `hides the export and share actions from a non-admin`), die
    eindeutige Test-ID der Model-Karte (`keeps the card wrapper outside the
    \`model-card-\` test id prefix`) und das **Verwerfen** des Extern-Links beim
    Leeren der Auswahl (`discards the share link when the selection is cleared`)
    und das **Verwerfen** beim **Profilwechsel** im Detail-Dialog
    (`discards the share link when another profile is shown` — der Deep-Link-
    Parameter `model` wechselt auf ein anderes Profil; der Link-Block zeigt
    danach keinen Link mehr).
  - `frontend/src/ui/__tests__/ModelContactSheetShareView.test.tsx` — die
    öffentliche Seite: Download-Button und Ablaufhinweis (`shows the download
    button and the expiry hint`) und ihre **Datenfreiheit** (`shows no model
    data at all` — weder Name noch E-Mail noch Profil-ID noch Foto im
    `<main>`-Bereich).

## 5. Tests (DoD-Referenz)

- PHPUnit (`ModelContactSheetTest`): 403 Nicht-Admin · Brand-Isolation 404 · 422 fehlend/ungültig · intern enthält PII + beide Fotos + Geburtsdatum · extern enthält public-Foto/`stage_name`/Ort und **nicht** internal-Foto, Realname, E-Mail, Telefon, Adresse, Geburtsdatum, Agentur, Notiz, Original-Dateinamen · Wasserzeichen extern (wiederholt) / intern nicht · Content-Type + Disposition.
- Siehe auch: Profil-Update-Mail (`ModelProfileUpdatedMail`, Inviter via Customer→Act→Invite, Multi-Act-Auflösung über alle Acts, stiller Skip ohne Invite).
- Byte-/Decode-Budget: `ModelContactSheetTest` pinnt, dass der Cap die **persistierbare** Grenze ist (ein Foto zwischen Quelle-Gate und Budget erreicht noch den Decoder) und dass ein Legacy-Foto über dem Budget vor dem Decoder ausgelassen wird; `ModelFileImageBudgetTest` pinnt das Gate in `ModelFileStore::storeUpload()` (Re-Encode über Budget → 422, nichts auf Disk) und dass `size_bytes` die kodierte Größe ist.
- D-41 (Auslassungs-Hinweis): `ModelContactSheetTest` pinnt je Variante den sichtbaren Hinweis mit Anzahl und Grund, die Trennung von Prüfungs-Auslassung und `MAX_PHOTOS`-Limit, die Einmal-Zählung bei mehreren Gründen, das Fehlen des Hinweises ohne Auslassung und die PII-Freiheit (kein Dateiname im Hinweis). Die **Label-Zuordnung** ist je Grund gepinnt: `Byte-Limit` (über-cap Bytes), `Größen-/Pixel-Limit` (Kantenlänge über `MAX_IMAGE_DIMENSION` **und** Pixelzahl über `MAX_IMAGE_PIXELS`), `MIME-Prüfung`, `nicht dekodierbar` und `Datei nicht verfügbar`.
- D-41 (Eigenschaften, nicht nur Darstellung): Der PDF-Contract zeigt je Foto nur den *primären* Grund — ein sekundärer Grund wäre dort grundsätzlich nicht sichtbar. Zwei Eigenschaften sind deshalb am **internen Grundsatz** gepinnt, über die Test-Naht `ModelContactSheetService::inspectRasterImage()` (protected) und `readImageSize()` (protected, zählt Header-Parses): (1) ein über-cap Foto trägt **genau einen** Grund (`byte`) — kein sekundäres `dimensions` aus dem Budget und kein Header-Parse auf 20 MiB; (2) die externe Variante zählt und erwähnt interne Fotos **nicht** — ein internes Foto, das an der MIME-Prüfung scheitert, erscheint nur im internen Bogen als Auslassung, im externen Bogen weder als Auslassung noch als Dateiname.
- PHPUnit (`ModelContactSheetBulkTest`, Phase 3): 403 Nicht-Admin · 404 bei einer ID der fremden Brand **und** bei einer ID, die nicht auflöst (Fail closed, kein stilles Weglassen) · 422 bei leerer ID-Liste, bei Nicht-String-IDs, bei ungültiger Variante und bei mehr als 25 eindeutigen IDs · Duplikate zählen einmalig (26× dieselbe ID → eine Kachel) · 403 für ein inaktives Profil beim Regular Admin, 200 beim Super-Admin · eine Kachel je ausgewähltem Profil (Kachel-Index „Profil N von M") · intern enthält Realname, Ort und E-Mail · extern enthält **weder** Realname, E-Mail, Telefon, Original-Dateinamen **noch** das interne Foto (über die Bildmaße im PDF belegt) und trägt das Wasserzeichen · D-41 je Kachel: die Prüf-Auslassung mit Anzahl und Grund (PII-frei), das Foto-Limit der Kachel (`Foto-Limit (max. 1)`) und — extern — **keine** Auslassung, weil interne Fotos keine Kandidaten sind · Audit: eine `model.contact_sheet.export`-Zeile je Profil und eine `model.contact_sheet.export_bulk`-Zeile mit Anzahl und Variante · **Byte-Budget der Sammlung** (über die `protected`-Naht `collectionMaxTotalPhotoBytes()`): `test_collection_budget_omits_a_photo_and_names_it_on_document_level` (das Budget reicht exakt für das erste Foto — das zweite wird nicht eingebettet und auf **Dokumentebene** benannt, die Kachel meldet **keine** gescheiterte Prüfung) und `test_collection_budget_omits_a_single_photo_that_alone_exceeds_the_budget` (Budget von einem Byte: das **einzige** Foto der Kachel überschreitet das Gesamtbudget — es wird nicht eingebettet, die Auslassung erscheint auf Dokumentebene mit Anzahl und Grund, PII-frei).
- PHPUnit (`ModelContactSheetShareTest`, Phase 4): 403 Nicht-Admin · 422 bei leerer ID-Liste, bei einer ID-Liste, die kein Array ist, bei mehr als 25 IDs und bei `variant ≠ external` · 404 bei einer ID der fremden Brand **und** bei einer ID, die nicht auflöst (Fail closed) · 403 für ein inaktives Profil beim Regular Admin, 201 beim Super-Admin · 201 mit `{url, expires_at, variant, count}`, `variant = external` und einem Ablauf im 7-Tage-Fenster · ein **neuer** Link invalidiert keinen älteren (kein Widerruf vor dem Ablauf) · der öffentliche Endpunkt liefert **ohne Session** das externe Blatt (Content-Type, Disposition, Künstlername + Ort, **kein** Realname, **keine** E-Mail, **kein** Telefon, **kein** Original-Dateiname, Wasserzeichen wiederholt) · 404 mit derselben generischen Meldung für einen manipulierten, einen abgelaufenen und einen fremd-brandeten Token sowie für einen Link, dessen Profile alle entfallen sind · die ausgelassenen Profile werden **benannt** („1 Profil nicht mehr verfügbar"), die verbliebenen Kacheln zeigen „Profil 1 von 1" · die Audit-Zeilen `model.contact_sheet.share_link.created` und `model.contact_sheet.share_link.accessed` nennen die `link_id` (UUID), die Anzahl und die Brand — und der Token steht in **keiner** Log-Zeile (negativ über einen Log-Recorder geprüft, nicht über `Log::spy()`).
- **Strikte Payload-Prüfung** (Unit-Test `backend/tests/Unit/Services/ModelContactSheetLinkServiceTest.php`, Phase 4): die Nutzlast wird feldweise geprüft — Typ, Variante, ID-Liste (leer, mehr als 25 IDs, Duplikate, ungültige ID), Brand und `link_id` (UUID). **Jede** Abweichung liefert `null` (keine teilweise akzeptierte Nutzlast); ein Quote-Token wird nicht als Contact-Sheet-Token akzeptiert; ein abgelaufener und ein fremd-brandeter Link werden abgelehnt.
- **Manipulierte Nutzlast** (`ModelContactSheetShareTest::test_public_endpoint_rejects_a_token_with_a_mutated_payload`): der Token bleibt wohlgeformt (drei Segmente), aber die Signatur deckt den veränderten Payload nicht mehr ab → 404 mit derselben generischen Meldung.
- **Brand-Frontend-URL** (`ModelContactSheetShareTest::test_share_link_url_points_at_the_own_brand_frontend`): die URL zeigt auf die Frontend der **eigenen** Brand — nicht auf den Default (`app.url`). Der Test schaltet dafür die Umgebungs-URL (`FRONTEND_URL`) ab; ohne sie hätte sie Vorrang (§1.3.1).
- **Brand ohne Enum-Fall** (`ModelContactSheetShareTest::test_share_link_is_refused_for_a_brand_outside_the_enum`): eine Brand-ID außerhalb des `Brand`-Enums ergibt **422** und **kein** Token — der Link-Service wird gar nicht angesprochen (fail closed, §1.3.1). Die Meldung nennt das **durchgesetzte** Kriterium („Brands, die das Portal unterstützt") — nicht „Brands mit eigener Portalinstanz" (das war ein anderes Kriterium: eine Legacy-Brand wie `srp` hatte eine eigene Instanz, aber keinen Enum-Fall).
- **Vitest (Frontend, Phase 3/4):** `frontend/src/logic/__tests__/modelContactSheet.test.ts` (Bulk-URL, POST-Payload, Fallback-Dateiname, Limit-Konstante, Share-URL mit kodiertem Token, POST ohne Variante, plain `fetch` ohne Credentials, Fallback-Dateiname des Share-Downloads, Fehlerpfad, Ablauf aus den Claims, kaputter Token) · `frontend/src/ui/management/__tests__/ManagementModelsView.test.tsx` (die Aktionen der Auswahlleiste sind Admin-only, die Test-ID der Model-Karte ist eindeutig, und das **Leeren der Auswahl verwirft den angezeigten Extern-Link**; ebenso der **Profilwechsel im Detail-Dialog** — `discards the share link when another profile is shown`) · `frontend/src/ui/__tests__/ModelContactSheetShareView.test.tsx` (Download-Button und Ablaufhinweis, und die **Datenfreiheit** der öffentlichen Seite: `shows no model data at all` — weder Name noch E-Mail noch Profil-ID noch Foto im `<main>`-Bereich).
- **Playwright (Frontend, Phase 3/4):** `frontend/tests/e2e/crm/model-contact-sheet.spec.ts` (`@feature:model-export`) — Einzelbogen intern/extern (Download-Event, Status 200, Content-Type/Disposition, Dateiname `-internal-<Ymd>.pdf` / `-external-<Ymd>.pdf`) · Bulk aus der Ergebnisliste · Extern-Link aus der **Auswahlleiste** (Gast-Kontext ohne Session → Download) · Extern-Link aus dem **Detail-Dialog** (der Dialog-Pfad: die URL erscheint, der Gast lädt das Blatt ohne Login, und nach dem Schließen + dem Öffnen eines **anderen** Profils zeigt der Link-Block **keinen** alten Link mehr).

## 6. Akzeptierte Grenzen

- **Extern-Link (Phase 4):**
  - **Kein Widerruf vor dem Ablauf.** Der Link ist bis zum Ablauf
    (`ModelContactSheetLinkService::TTL_DAYS = 7`) gültig; es gibt keine
    Widerrufs-Funktion und keinen Server-Status, in dem der Token verworfen
    werden könnte. Ein neu erzeugter Link invalidiert einen älteren **nicht** —
    beide bleiben bis zu ihrem eigenen Ablauf nutzbar.
  - **Der Link liefert, was die Signatur abdeckt — keine erneute
    Berechtigungsprüfung.** Die Auslieferung prüft **nur** Signatur, Ablauf
    und Host-Brand. Es gibt **keinen** Lifecycle-Gate bei der Auslieferung:
    ein Profil, das seit der Ausstellung nur `inactive` oder `expired`
    geworden ist, wird **weiterhin geliefert**. Grund: die externe Variante
    ist PII-frei, und ein Gate würde die geteilte Auswahl stillschweigend
    verkürzen, ohne die Auslassung zu benennen. Ein Profilstatus „gesperrt"
    existiert nicht — die Lifecycle-Zustände sind `active`, `inactive` und
    `expired`.
  - **Gelöschte Profile fallen heraus — und werden benannt.** Ein Profil, das
    seit der Ausstellung gelöscht oder in eine andere Brand verschoben wurde,
    ist nicht mehr lieferbar; der Bogen nennt die Anzahl dieser Auslassung
    („N Profil(e) nicht mehr verfügbar", PII-frei, nur ein Zählwert). Ist
    **kein** Profil mehr lieferbar, antwortet der Endpunkt mit 404.
  - **Nur die externe Variante.** Ein Extern-Link liefert **immer** die
    externe Variante. Die interne Variante (Realname, Kontaktdaten,
    Altersnachweis-Status, interne Fotos) ist nicht teilbar und lässt sich
    auch nicht über eine manipulierte Anfrage erzwingen — `variant ≠ external`
    wird schon bei der Ausstellung mit 422 abgelehnt, ein Token mit anderer
    Variante wird nicht akzeptiert.
  - **Der Token ist ein Bearer-Credential.** Wer den Link hat, kann das Blatt
    laden; es gibt keine Empfänger-Bindung, keine E-Mail-Verifikation und
    keine Zugriffszähler-Auswertung im Portal. Der Link ist deshalb
    entsprechend zu behandeln (Weitergabe nur an den Empfänger, Ablauf kurz
    halten).
  - **Brand-Bindung an den Host.** Der Link funktioniert nur auf dem Host der
    Brand, für die er erzeugt wurde; ein Token einer anderen Brand ergibt 404.
  - **Ein Token pro Auswahl, ein Blatt pro Token.** Der Token bündelt genau
    die IDs, die bei der Ausstellung übergeben wurden; die Auswahl selbst
    wird nicht gespeichert.

- **Bulk-Export (Phase 3):** höchstens `MAX_BULK_PROFILES = 25` Profile je
  Dokument (Server-Grenze; das Frontend spiegelt sie als
  `MODEL_CONTACT_SHEET_MAX_BULK`) und **ein** Foto je Profil
  (`COLLECTION_MAX_PHOTOS_PER_PROFILE = 1`). Beides sind bewusste
  Memory-Limits für das gerenderte Dokument — eine Sammlung ist ein
  Übersichtsblatt, kein Archiv. Die nicht eingebetteten Fotos werden je
  Kachel als Limit-Auslassung benannt (D-41), nicht stillschweigend
  verworfen.

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
