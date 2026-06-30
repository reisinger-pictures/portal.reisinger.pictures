# Lightroom Plugin — Multi-Tenant Support Gap (R-16)

> **Status:** 🏗️ In Arbeit (T-09). Implementierung gestartet am 2026-06-29.
> Verknüpft mit `AGENTS.todo.md` → T-09 und `features/infrastructure/06-multi-domain-branding.md`.

## 1. Kontext

Das Portal-Backend wurde zu einem **Multi-Brand-/Single-Codebase-System** ausgebaut (Domains
`story.reisinger.pictures` = B2C und `reisinger.pictures` = B2B). Die Steuerung erfolgt zur Laufzeit über den
Hostnamen (siehe `06-multi-domain-branding.md`).

Das **Lightroom Classic Plugin** (`admin.lrplugin/`) ist an dieser Stelle **nicht** mitgewachsen:
Es ist ein **starrer Single-Tenant-Client**, hart verdrahtet auf `https://portal.reisinger.pictures`.

## 2. Status Quo (verifiziert 2026-06-29)

### 2.1 API-Base-URL — hardcoded, kein Brand-Switch

Es existiert exakt eine Produktionsdomain; der einzige Umschalter ist Prod/Test:

| Stelle                              | Verhalten                                                         |
|-------------------------------------|-------------------------------------------------------------------|
| `admin.lrplugin/Api.lua:9`          | `prefs.useTestUrl and "https://portal.test" or "https://portal.reisinger.pictures"` |
| `admin.lrplugin/Api.lua:27`         | `Api.getApiUrl() .. endpoint` — alle Calls laufen hier zusammen   |
| `PluginInfoProvider.lua:17,21-22`   | UI-Toggle „portal.test" vs. „portal.reisinger.pictures"           |
| `ManagerCore.lua:76,89`             | derselbe Toggle im Login-Dialog                                   |

Ein Treffer für `story.reisinger.pictures` / `srp` / `brand` / `tenant` existiert im gesamten Plugin **nicht**.

### 2.2 Upload- & Metadata-Payload — ohne Brand-Context

Der Upload-Payload (`ManagerCore.lua:362-366`, `LrHttp.postMultipart`) enthält ausschließlich:
`gallery_id`, `lr_uuid`, `file`, `Authorization: Bearer <jwt>`. Weder ein `brand`-/`tenant`-Feld
noch ein `X-Brand`-/`X-Tenant`-/`Origin`-Header werden gesendet.

Galerie-Erstellung (`GalleryDialog.lua:180-198`) und Meta-Galerie-Anlage
(`MetaGalleryDialog.lua:87-95`) setzen ebenfalls nur galerie-spezifische Felder — kein Brand-Feld.

### 2.3 Galerie-Routing — rein `gallery_id`-basiert

- `ManagerCore.lua:102` — `GET /api/management/galleries?filter_type=<mode>` (mode =
  `selection` | `delivery`, **nicht** Brand).
- `ManagerCore.lua:230,254,308,416` — sämtliches Routing über `selectedGalleryId`.
- `InviteDialog.lua:43` — Invite-Link via `Api.getApiUrl() .. "/invite/" .. token` → Domain fest
  `portal.reisinger.pictures`, kein per-Brand-Einladungslink.

`DeliveryManager.lua` / `SelectionManager.lua` sind reine Dispatcher
(`ManagerCore("delivery")` / `ManagerCore("selection")`) und enthalten kein eigenes Routing.

**Fazit:** Die einzige implizite „Tenant-Auswertung" erfolgt serverseitig über die feste
Base-URL — das Plugin ist de facto ein reines `reisinger.pictures`-Tool.

## 3. Konkrete Eingriffspunkte für ein künftiges Refactoring

Für echten Multi-Tenant-Support müssen mindestens drei Dinge ergänzt werden:

1. **Brand-bezogene Base-URL ODER Brand-Header** — zentrale Stelle `Api.lua:7` `Api.getApiUrl()`
   bzw. `Api.lua:17` `Api.call(...)` (z. B. konfigurierbare Domain-Auswahl oder konstanter
   `X-Brand`-Header).
2. **Brand-Feld im Upload-Payload** — `ManagerCore.lua:362` (`postMultipart`).
3. **Brand-Filter bei der Galerie-Listung / Brand-Auswahl in der UI** — `ManagerCore.lua:102`
   (`reloadTree`) bzw. ein Brand-Selector im Login-/Galerie-Dialog.

Sowie flankierend:
- `GalleryDialog.lua:200` / `MetaGalleryDialog.lua:97` — `brand_id`/`tenant` bei Create/Update.
- `InviteDialog.lua:43` — per-Brand-Einladungsdomain.

## 4. Risiko & Abhängigkeit

Solange das Backend Brand-Context aus dem **Host-/Origin-Header** ableitet (siehe
`06-multi-domain-branding.md`), kann das Plugin das B2C-Segment (`story.reisinger.pictures`) **nicht**
erreichen — Uploads landen immer im `reisinger.pictures`-Kontext. Für B2B-only-Workflows ist das
akzeptabel; für cross-brand Workflows ist das Plugin derzeit ungeeignet.

Keine Änderung am Backend erforderlich — diese Doku beschreibt ausschließlich die Plugin-Seite.
