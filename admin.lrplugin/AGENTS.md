# AGENTS.md — admin.lrplugin (Lightroom Classic Lua Plugin)

Module-scoped doc for the Lightroom plugin in `admin.lrplugin/`. This is a **separate module**
from `frontend/` and `backend/`: it is plain Lua running inside Lightroom Classic (SDK 8.0) and
talks to the portal REST API over `LrHttp`. See the module `AGENTS.md` files in `frontend/` and
`backend/` for their own rules.

## Scope

Export-menu plugin ("Reisinger Foto Portal") that lets photographers manage portal galleries
directly from Lightroom. Two export-menu items launch the selection and delivery managers;
both go through `ManagerCore`.

## Key Files

| File | Role |
|------|------|
| `Info.lua` | Plugin manifest (`LrSdkVersion`, version, export-menu items) |
| `PluginInfoProvider.lua` | Plugin info provider for the Lightroom Plugin Manager |
| `ManagerCore.lua` | Single entry point for all managers; resolves the base URL and runs a `LrTasks.startAsyncTask` |
| `SelectionManager.lua` | "Bewertungs-Galerien verwalten…" — calls `ManagerCore("selection", baseUrl)` |
| `DeliveryManager.lua` | "Delivery-Galerien verwalten…" — calls `ManagerCore("delivery", baseUrl)` |
| `GalleryDialog.lua` | Gallery create/edit dialog (name, slug, visibility, group, password, expiry, …) |
| `MetaGalleryDialog.lua` | Meta-gallery (group) create/edit dialog |
| `InviteDialog.lua` | Invite / share-link dialog |
| `RatingStatusDialog.lua` | Rating-status dialog |
| `Api.lua` | Thin REST client over `LrHttp` with JSON (local `json.lua`); base URL override via `Api.setBaseUrl` |
| `Utils.lua` | Shared helpers |
| `json.lua` | Bundled JSON encoder/decoder |

## Lua Conventions

- Lua 5.1 / Lightroom SDK 8.0: `import 'LrXxx'` for SDK modules, `require "Xxx"` for local modules.
- Modules return a function or a table — entry modules return `function(mode, baseUrl) ... end`.
- Auth is a JWT passed as `Authorization: Bearer <jwt>` (see `Api.call`).
- Local dev points at `http://localhost:4321` when the `useLocal` pref is set (`LrPrefs.prefsForPlugin()`), production at `https://portal.reisinger.pictures`.

## Rules

- Code, comments, and commit messages in English; UI strings in German.
- Keep `ManagerCore("<mode>", baseUrl)` as the single entry path for new manager modes.
- Changes here do not affect `frontend/` or `backend/` build/test pipelines.
