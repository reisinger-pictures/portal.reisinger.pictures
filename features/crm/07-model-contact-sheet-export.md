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

## 6. Akzeptierte Grenzen

- `MAX_PHOTOS = 12` für beide Varianten (Speicher-Limit; interne „alle Fotos"-Zusage endet hier).
- `whereNotNull('invited_by')` in `notifyInviter()` wirkungslos (Spalte NOT NULL) — defensiv, kein Funktionsproblem.
- `Mail::queue()` nach Commit: Queue-/Transport-Fehler bei `sync`-Queue → 500 trotz gespeichertem Profil (konsistent mit bestehendem Deferred-Mail-Flow).
