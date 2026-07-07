# Lightroom Plugin — Multi-Org Support Gap (R-16)

> **Status:** ✅ Gelöst (2026-07-02). Siehe Änderungen unten.
> Verknüpft mit `AGENTS.todo.md` → L-01.

## 1. Kontext

Das Portal-Backend wurde zu einem **Multi-Brand-/Single-Codebase-System** ausgebaut (Domains
`story.reisinger.pictures` = B2C und `reisinger.pictures` = B2B). Die Steuerung erfolgt zur Laufzeit über den
Hostnamen (siehe `06-multi-domain-branding.md`).

Das **Lightroom Classic Plugin** (`admin.lrplugin/`) ist an dieser Stelle **nicht** mitgewachsen:
Es ist ein **starrer Single-Org-Client**, hart verdrahtet auf `https://portal.reisinger.pictures`.

## 2. Status Quo (zusammengefasst)

Das Plugin ist ein **Single-Org-Client**, hart verdrahtet auf `https://portal.reisinger.pictures`:
- **API-Base-URL:** Hardcoded in `Api.lua:9` — nur Prod/Test-Umschalter, kein Brand-Switch
- **Upload-Payload:** Kein `brand`/`X-Brand`-Feld — nur `gallery_id`, `lr_uuid`, `file`
- **Galerie-Routing:** Rein `gallery_id`-basiert, kein Brand-Filter
- **Invite-Links:** Feste Domain `portal.reisinger.pictures`

Ein Treffer für `story.reisinger.pictures` / `srp` / `brand` / `Org` existiert im gesamten Plugin **nicht**.

## 3. Konkrete Eingriffspunkte für ein künftiges Refactoring

Für echten Multi-Org-Support müssen mindestens drei Dinge ergänzt werden:

1. **Brand-bezogene Base-URL ODER Brand-Header** — zentrale Stelle `Api.lua:7` `Api.getApiUrl()`
   bzw. `Api.lua:17` `Api.call(...)` (z. B. konfigurierbare Domain-Auswahl oder konstanter
   `X-Brand`-Header).
2. **Brand-Feld im Upload-Payload** — `ManagerCore.lua:362` (`postMultipart`).
3. **Brand-Filter bei der Galerie-Listung / Brand-Auswahl in der UI** — `ManagerCore.lua:102`
   (`reloadTree`) bzw. ein Brand-Selector im Login-/Galerie-Dialog.

Sowie flankierend:
- `GalleryDialog.lua:200` / `MetaGalleryDialog.lua:97` — `brand_id`/`Org` bei Create/Update.
- `InviteDialog.lua:43` — per-Brand-Einladungsdomain.

## 4. Risiko & Abhängigkeit

Solange das Backend Brand-Context aus dem **Host-/Origin-Header** ableitet (siehe
`06-multi-domain-branding.md`), kann das Plugin das B2C-Segment (`story.reisinger.pictures`) **nicht**
erreichen — Uploads landen immer im `reisinger.pictures`-Kontext. Für B2B-only-Workflows ist das
akzeptabel; für cross-brand Workflows ist das Plugin derzeit ungeeignet.

Keine Änderung am Backend erforderlich — diese Doku beschreibt ausschließlich die Plugin-Seite.

## 5. Gelöste Änderungen (L-01, 2026-07-02)

### 5.1 Referer-Header in Api.call

In `admin.lrplugin/Api.lua` wird in der Methode `Api.call` zwingend ein `Referer`-Header injiziert,
der den Wert von `Api.baseUrl` annimmt:

```lua
table.insert(headers, { field = "Referer", value = Api.baseUrl })
```

Damit kann die Laravel `BrandContextMiddleware` auch bei lokalen Plugin-Requests die korrekte Brand
ableiten (der Referer-Fallback in der Middleware greift). Ohne diesen Header fällt die Erkennung auf
Localhost immer auf B2B zurück.

### 5.2 Test-Strategie

Playwright-E2E-Tests für das Lightroom-Desktop-Plugin wurden gestrichen (Anti-Pattern für
Desktop-Plugins). Die Schnittstellen-Stabilität wird durch Backend-PHPUnit-Tests abgesichert.
