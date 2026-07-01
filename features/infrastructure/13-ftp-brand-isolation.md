# FTP-Upload Brand-Isolation & Defense-in-Depth (FT-01)

> **Status:** `active` — verbindlicher Soll-Zustand.
> Erstellt 2026-07-01.
>
> **Verknüpfter Task:** FT-01 in `AGENTS.todo.md`.

## 1. Kontext

`FtpController` war komplett brand-blind. Ein Photographer der Brand A konnte über die
setTarget-API eine Gallery der Brand B als FTP-Ziel setzen und via process() Bilder
dorthin importieren — sofern die gallery_id bekannt war oder erraten werden konnte.

Unter Policy A (U-02, Staff brand-bound) ist jeder Photographer brand-gebunden, aber
es gab keine explizite Prüfung im FTP-Controller. Dies schließt die Defense-in-Depth-Lücke.

## 2. Soll-Zustand

### 2.1 setTarget() — Brand-Isolation

- Nachdem `gallery_id` aus dem Request validiert wurde
- Wird `$user->getAllowedGalleryIds()` aufgerufen
- Ist die angefragte `gallery_id` **nicht** in den erlaubten IDs → **403 Forbidden**
- Der Check erfolgt **vor** dem Setzen des Targets

### 2.2 process() — Defense-in-Depth

- Vor dem Import wird `$user->current_ftp_gallery_id` gegen `$user->getAllowedGalleryIds()` geprüft
- Ist die ID nicht erlaubt → **403 Forbidden**
- Dies ist eine Defense-in-Depth-Maßnahme: selbst wenn setTarget() umgangen wird (z.B. via
  direktem DB-Eingriff), verhindert process() den Import in eine fremde Brand

### 2.3 Frontend — Visuelle Brand-Anzeige

- Im Gallery-Selection-Dropdown wird `gallery.brand` als `[Brand-Name]` hinter dem
  Gallery-Namen angezeigt
- Im aktiven Target-Badge wird die Brand als daisyUI `badge` dargestellt

## 3. Datenfluss

```
FTP setTarget Request
  → FtpController::setTarget()
    → Validation (gallery_id exists)
    → getAllowedGalleryIds() Check
      → gallery_id NOT in allowed → 403
    → update(current_ftp_gallery_id)

FTP process Request
  → FtpController::process()
    → is_photographer Check
    → current_ftp_gallery_id Check
    → getAllowedGalleryIds() Check (Defense-in-Depth)
      → current_ftp_gallery_id NOT in allowed → 403
    → Gallery::find() → Import
```

## 4. Tests

| Test | Beschreibung |
|------|-------------|
| `test_photographer_cannot_set_target_to_other_brand_gallery` | Photographer Brand B2B → SRP-Gallery = 403 |
| `test_photographer_can_set_target_to_own_brand_gallery` | Photographer Brand B2B → B2B-Gallery (allowed) = 200 |
| `test_photographer_cannot_process_other_brand_gallery` | current_ftp_gallery_id auf SRP-Gallery gesetzt → process() = 403 |
| `test_set_target_rejects_nonexistent_gallery` | Ungültige gallery_id → 422 |

## 5. Decision Log

| Datum | Eintrag |
|-------|---------|
| 2026-07-01 | FT-01 implementiert: setTarget() + process() Brand-Checks, Frontend-Brand-Badge, Spec. |
