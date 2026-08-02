# Per-User Settings Pattern

**Status:** active  
**Tags:** `settings`, `per-user`, `user-scope`, `lightroom-catalog`  
**Related:** `20-setting-resolver.md`, `../b2b/11-kanban-board.md`

## 1. Context

The `SettingResolver` (see `20-setting-resolver.md`) is the single authority for reading and writing the **brand-scoped** key/value `settings` table (`settings` table + `brand` column). It resolves values along the **brand axis** (current brand → B2B fallback → default) and is deliberately NOT scoped to a user.

The brand axis is the wrong axis for **per-user data**:

- Brand-scoped values describe how a brand is configured (`bank_iban`, `term_editorial`, `watermark_opacity`).
- Per-user values describe how a single user wants their workspace to behave (their own Lightroom catalog list, their FTP slug, their default gallery).

Conflating the two axes breaks self-service: a photographer would have to be granted brand-level write access in order to maintain their own preferences, and every user's data would be globally visible. This document defines the per-user settings pattern and explicitly demarcates it from the brand `SettingResolver` axis.

## 2. Pattern Rules

**Rule A — Single-value per-user preferences → column on `users`.**

A single scalar preference belongs directly on the `users` table. Precedents:

- `ftp_slug` — a unique per-user slug (auto-generated, validated with `unique:users,ftp_slug`).
- `current_ftp_gallery_id` — a per-user "last selected" gallery reference (`foreignUuid → galleries, onDelete set null`).
- Managed through the profile endpoint `PUT /api/auth/profile` (`AuthController::updateProfile`), read via `/api/auth/me`.

No separate table, no resolver, no key/value indirection for a single value.

**Rule B — List-valued per-user preferences → owner-scoped child table with `user_id` FK.**

A user-maintained list (ordered, user-specific) belongs in a child table keyed by the owning user:

- `id` UUID PK (`HasUuids`)
- `user_id` `foreignUuid → users` (NOT a `brand` column)
- the list's value column(s)
- a `position` column for user-controlled ordering
- unique constraint on `(user_id, name)` (or equivalent natural key)

The precedent is `lightroom_catalogs(user_id, name, position)` — see §3.

**Rule C — Explicitly DO NOT reuse the brand `settings` table for user data.**

The `settings` table + `SettingResolver` remain the brand axis only. User data must never be written there; doing so would couple user preferences to brand configuration and require cross-user access to a brand-scoped store.

**Rule D — Explicitly DO NOT introduce a generic `user_settings` key/value resolver** unless multiple additional per-user preferences are actually needed. Decision 2026-08-02 (YAGNI): single values live on `users` (Rule A), lists live in owner-scoped child tables (Rule B). A generic resolver would add indirection without a current consumer.

**Rule E — Gating.**

Data is **owner-scoped**. The role gate (`super_admin` / `photographer`) only controls access to the *feature* (e.g. the Lightroom catalogs management UI), NOT cross-user visibility. Reads and writes are **self-only**:

- `super_admin` sees and manages only their OWN per-user data — the super-admin role does not grant read/write access to other users' preferences.
- A user's own data is readable/writable by that user via the feature route; any other user (including other super_admins) gets 404/empty for it.

## 3. Concrete Case: Lightroom Catalogs

`lightroom_catalogs` is the reference implementation of Rule B.

**Table shape:**

| Field | Type | Note |
|-------|------|------|
| `id` | uuid PK | `HasUuids` |
| `user_id` | foreignUuid → users | owning photographer |
| `name` | string(255) | |
| `position` | unsignedInteger default 0 | user-controlled order |
| `created_at`, `updated_at` | timestamps | |

Unique constraint: `(user_id, name)` — a catalog name is only unique per user.

**Self-scope (backend):**

- Model `LightroomCatalog`: `fillable = ['user_id', 'name', 'position']`, `owner()` relation, and a `ownedBy(User)` scope (`where('user_id', $user->id)`) replacing any brand scope.
- `LightroomCatalogController` at `/api/management/lightroom-catalogs`:
  - Gate: `super_admin` AND `photographer` may manage (GET + writes).
  - `scopedQuery = where('user_id', auth)`. `GET` → `{ lightroom_catalogs: [...] }`, only the caller's own catalogs, ordered by `position` then `created_at`.
  - `POST`: `user_id` = current user, uniqueness validated per user (`Rule::unique('lightroom_catalogs','name')->where('user_id', $user->id)`), `position = max(position of own scope) + 1`.
  - `PUT` / `DELETE`: self-only — `findOrFail` within the own scope, so foreign IDs yield 404. No `super_admin`-only writes.

**Position ordering:** each user's list is ordered independently by `position`; positions are scoped per user (max+1 is computed within the user's own rows).

## 4. Catalog-Name Privacy Rule (`lightroom_catalog_is_mine`)

`photo_jobs.lightroom_catalog` stays a plain string (the catalog name chosen at job creation time). It is NOT a foreign key. Because catalog names are now user-private (Rule E), a job's catalog string must not be shown to viewers who do not own that catalog.

Server-side rule (computed in `PhotoJobBoardController` on every serialization — index/store/update/move):

- `lightroom_catalog_is_mine` = true if the job's `lightroom_catalog` name ∈ the **viewer's own** catalog names (`LightroomCatalog::ownedBy(viewer)`).
- The raw `lightroom_catalog` string stays in the API payload — required for form round-trip (editing a job must not lose the stored value).
- The **UI** only renders the catalog line when `lightroom_catalog_is_mine` is true. Otherwise it shows only the responsible person's name (owner/assignee), never the catalog string.

This leaks no catalog-name information across users while keeping edit round-trips lossless. No schema change to `photo_jobs`.

## 5. Design Decisions

| Decision | Rationale |
|----------|-----------|
| Single-value prefs → `users` column (Rule A) | Existing precedent (`ftp_slug`, `current_ftp_gallery_id`); no indirection for one scalar; profile endpoint already exists |
| List-valued prefs → owner-scoped child table with `user_id` FK (Rule B) | Enables self-service CRUD + per-user ordering; unique `(user_id, name)` prevents per-user duplicates |
| Do NOT reuse brand `settings` table for user data (Rule C) | `SettingResolver` is the brand axis; mixing axes couples user prefs to brand config and breaks self-service |
| Do NOT introduce a generic `user_settings` resolver (Rule D) | YAGNI 2026-08-02; no current multi-pref resolver consumer; columns/child tables suffice |
| Role gate controls feature access, not cross-user visibility (Rule E) | `super_admin` sees only own per-user data; other users' data is 404/empty — prevents cross-user data leaks |
| `lightroom_catalog` stays a string + `lightroom_catalog_is_mine` flag (§4) | No FK/schema churn on `photo_jobs`; privacy enforced at the UI layer; round-trip stays lossless |
