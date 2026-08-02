# Kanban-Board (Projekte & Bildbearbeitung) — SOLL-Zustands-Dokumentation

Status: SOLL (Target State)
Stand: 2026-08-02
Autor: Florian Reisinger (Senior Architekt)

Diese Datei ist die verbindliche Referenz für die Implementierung des Kanban-Features. Backend und Frontend müssen exakt gegen diese Definition alignen.

---

## 1. Überblick & Zweck

Zwei kanban-ähnliche Boards für Workflow-Transparenz im B2B-Bereich:

- **Projekte-Board** (kaufmännisch): `anfrage → angebot → beauftragt → rechnung → bezahlt` (+ terminal `storniert`).
- **Bildbearbeitungs-Board** (Produktion): `shooting → culling → bearbeitung → export → veroeffentlicht` (+ terminal `abgebrochen`).

Terminale Status (`storniert` / `abgebrochen`) und Endstatus (`bezahlt` / `veroeffentlicht`) unterliegen der Auto-Cleanup-Policy (§7).

Die Boards visualisieren den Fortschritt von eingehender Anfrage bis zur Auslieferung. Sie sind der zentrale operative Überblick für Admin (kaufmännisch) und Fotograf (Produktion).

---

## 2. Rollen- & Sichtbarkeits-Matrix (verbindlich)

| Board | Zugriff-Rollen | Sichtbarkeit |
|---|---|---|
| Projekte | `super_admin`, `admin` | Super-Admin: alle; Admin: nur eigene |
| Bildbearbeitung | `super_admin`, `photographer` | Super-Admin: alle; Fotograf: nur eigene |

### Owner-Modell

- `created_by` = Ersteller beim Anlegen; wird **automatisch** auf den aktuellen User gesetzt (nicht client-wählbar).
- Optionales `assignee_id` für Reassign / Zuweisung.

### Sichtbarkeits-Query (Backend)

- `super_admin` → alle Datensätze.
- sonst → `WHERE owner_id = me OR assignee_id = me`.

### Board-Gate

- **Projekte**: nur `admin` / `super_admin`.
- **Bildbearbeitung**: nur `photographer` / `super_admin` (Super-Admin greift immer durch).

---

## 3. Datenmodell (Migration V025, konsolidiert)

Drei Tabellen (siehe §1 AGENTS.md / Migrations-Policy; die Nicht-Produktions-Migrationen ≥ V025 werden vor dem Deployment zu EINER konsolidierten Migration zusammengefasst). Alle Tabellen nutzen `UUID`-Primärschlüssel (`HasUuids`), `foreignUuid`-Fremdschlüssel und eine `brand`-Spalte `string(4)` mit Index. Die Status-Enums (inkl. `storniert` / `abgebrochen`) sind in V025 erweitert.

### 3.1. `projects`

| Feld | Typ | Hinweis |
|---|---|---|
| `id` | uuid PK | `HasUuids` |
| `brand` | string(4), indexiert | über `BrandRegistry` gesetzt |
| `owner_id` | foreignUuid → users | Ersteller, Pflicht |
| `assignee_id` | foreignUuid → users, nullable | Reassign |
| `client_name` | string | |
| `email` | string | |
| `phone` | string, nullable | |
| `package` | string, nullable | |
| `price_cents` | int | Preis in Cent |
| `payment_status` | enum(`open\|partly_paid\|paid`) | |
| `status` | enum(`ProjectStatus`) | inkl. terminal `storniert` |
| `position` | int | Spalten-Position/Reihenfolge |
| `linked_photo_job_id` | foreignUuid → photo_jobs, nullable | Verknüpfung zur Produktion (Handoff, §5.1) |
| `created_at`, `updated_at` | timestamps | |

### 2. `photo_jobs`

| Feld | Typ | Hinweis |
|---|---|---|
| `id` | uuid PK | `HasUuids` |
| `brand` | string(4), indexiert |
| `owner_id` | foreignUuid → users | Pflicht |
| `assignee_id` | foreignUuid → users, nullable | |
| `title` | string | |
| `lightroom_catalog` | string, nullable | **bleibt ein String** — kein FK; Auswahl aus den eigenen Katalogen des Users (siehe §7.1) |
| `total_count` | int | Gesamtanzahl Bilder |
| `selected_count` | int | selektierte Anzahl |
| `target_gallery_id` | foreignUuid → galleries, nullable | Ziel-Galerie |
| `is_private` | boolean | |
| `status` | enum(`PhotoJobStatus`) | inkl. terminal `abgebrochen` |
| `position` | int | |
| `created_at`, `updated_at` | timestamps | |

### 3. `workflow_logs`

| Feld | Typ | Hinweis |
|---|---|---|
| `id` | uuid PK | `HasUuids` |
| `item_type` | enum(`project` \| `photo_job`) | polimorphe Referenz |
| `item_id` | uuid | **ohne** FK-Constraint |
| `from_status` | string | Quell-Status |
| `to_status` | string | Ziel-Status |
| `user_id` | foreignUuid → users, nullable | wer hat gewechselt |
| `created_at` | timestamps | |

> **Item-Id ohne FK-Constraint**: bewusst, damit ein gelöschter Datensatz keinen Fehlschlag der Logschreibung verursacht. Die Log-Daten bleiben als Historie bestehen.

---

## 4. Enums

Alle als **PHP backed enums**, Namespace `App\Enums`. Das Board-Spalten-Layout ist stabil aus diesen Enums abgeleitet (eine Enum-Konstante = eine Spalte).

```php
enum ProjectStatus: string
{
    case ANFRAGE        = 'anfrage';
    case ANGEBOT        = 'angebot';
    case BEAUFTRAGT     = 'beauftragt';
    case RECHNUNG       = 'rechnung';
    case BEZAHLT        = 'bezahlt';
    case STORNIERT      = 'storniert';
}

enum PhotoJobStatus: string
{
    case SHOOTING        = 'shooting';
    case CULLING         = 'culling';
    case BEARBEITUNG     = 'bearbeitung';
    case EXPORT          = 'export';
    case VEROEFFENTLICHT = 'veroeffentlicht';
    case ABGEBROCHEN     = 'abgebrochen';
}

enum PaymentStatus: string
{
    case OPEN         = 'open';
    case PARTLY_PAID  = 'partly_paid';
    case PAID         = 'paid';
}
```

---

## 5. API-Vertrag (verbindlich — Backend & Frontend MÜSSEN alignen)

Alle Routen liegen in der Gruppe `['auth:api', 'management']`, Präfix `/api/management`. JSON-Item (beide Boards):

```json
{
  "id": "uuid",
  "status": "string",
  "position": "int",
  "owner": { "id": "uuid", "name": "string" },
  "assignee": { "id": "uuid", "name": "string" } | null,
  "created_at": "ISO-8601",
  "...board-spezifische Felder"
}
```

### Projekte

Route-Basis `/api/management/projects` (nur `admin` / `super_admin`):

| Methode | Pfad | Body / Verhalten | Status |
|---|---|---|---|
| GET | `/projects` | → `{ "projects": [] }` (visibilty-scoped, §2) | 200 |
| POST | `/projects` | `owner` = current User, `brand` via `BrandRegistry`, `status` = `anfrage`, `position` = Ende (max+1) | 201 |
| PUT | `/projects/{id}` | Update | 200 |
| PATCH | `/projects/{id}/move` | body `{ status, position }`; **neu ≠ alt-status** → schreibt `workflow_logs` | 200 |
| POST | `/projects/{id}/handoff` | **nur `super_admin`**; erzeugt `photo_job` und setzt `linked_photo_job_id` (§5.1) | 201 / 403 / 422 |
| DELETE | `/projects/{id}` | Löschen | 200/204 |

### Produktion / Bildbearbeitung

Route-Basis `/api/management/photo-jobs` (nur `super_admin` / `photographer`):

| Methode | Pfad | Verhalten |
|---|---|---|
| GET | `/api/management/photo-jobs` | → `{ "photo_jobs": [] }`, visibility-scoped |
| POST | `/api/management/photo-jobs` | anlegen |
| PUT | `/api/management/photo-jobs/{id}` | Update |
| PATCH | `/api/management/photo-jobs/{id}/move` | Status/Position, `workflow_logs` |
| DELETE | `/api/management/photo-jobs/{id}` | löschen |

### Middleware & Scoping

- **ManagementMiddleware** muss um Präfix `api/management/photo-jobs*` für `photographer` erweitert werden (Zugriff auf Produktions-Endpoints öffnen).
- Sichtbarkeits-Scoping: wie §6 (Owner nein → alle; sonst `owner_id`/`assignee_id`).

### 5.1 Projekt → Bildbearbeitung-Übernahme (Handoff)

`POST /api/management/projects/{id}/handoff` — **nur `super_admin`**.

- Erzeugt einen `photo_job`: `brand` = Project-Brand, `owner_id` = aktueller User, `title` = `client_name`, `status` = `shooting`, `position` = `max+1` (brand-weit).
- Setzt `project.linked_photo_job_id` auf die neue Photo-Job-ID.
- Ist bereits ein `linked_photo_job_id` gesetzt → **422** (`already_handed_off`, kein Doppel-Handoff).
- Antwort: `{ "photo_job": {...} }` (201).
- Der Projekt-Status wird beim Handoff **nicht** verändert.

---

## 6. Frontend

### View & Routing

- Zwei Views:
  - `ui/management/ManagementProjectsBoard.tsx` → Route `/admin-projects`
  - `ui/photographer/PhotographerProductionBoard.tsx` → Route `/production`
- Routing via `App.tsx`: lazy-loaded + `ProtectedRoute` mit `requiredFeature: 'b2b'`; Weiche in `ManagementDashboard` über `currentView`.

### Sidebar

- Projekte-Eintrag unter Sichtbarkeit `{isAdmin}`.
- Produktion-Eintrag unter Sichtbarkeit `{isPhotographer}`.

### Drag & Drop (DnD)

- Paket: **`@dnd-kit/react` (NUR dieses Paket)**.
- `DragDropProvider` + `useDroppable` (Spalten) + `useDraggable` / `useSortable` (Karten) + `DragOverlay`.
- Bei Status-/Positionsänderung → `PATCH .../move`.

### Formulare

- `react-hook-form` + `@hookform/resolvers/zod`.
- Pflichtfelder tragen `required`-Attribut; keine `(Optional)`-Labels (§3 Field-Label-Policy).

### Phase-2-UI

- **Handoff (Projekte-Karte, nur `super_admin`):** Button „In Bildbearbeitung übernehmen" → `handoff`-API → Toast + `mutate()`. Bei gesetztem `linked_photo_job_id` stattdessen Badge („Übernommen" / Link zur Produktion).
- **Lightroom-Katalog-Select (Photo-Job-Formular):** Optionen = **eigene** Kataloge des Users (`useLightroomCatalogs`). Beim Editieren fremder Kataloge bleibt der Rohwert erhalten (kein Clearen).
- **Katalog-Anzeige (Produktions-Board-Karte):** Katalogzeile nur rendern, wenn `lightroom_catalog_is_mine`; sonst nur die Personenzeile (owner/assignee).
- **Profil:** Card „Lightroom-Kataloge" in `UserProfileView` (nur Fotograf/Super-Admin) — add/edit/delete der eigenen Liste.

### Hooks & Permissions

- Hooks `useProjectsBoard` / `useProductionBoard` nach dem `usePayouts`-Pattern (SWR + `fetcher`/`apiMutate`).
- `usePermissions` erweitern:
  - `canAccessProjectsBoard = isAdmin`
  - `canAccessProductionBoard = isPhotographer`

---

## 7. Auto-Cleanup (Terminale Status + 30d-Grace)

Abgeschlossene oder abgebrochene Items werden **automatisch hart gelöscht**, um Board-Hygiene zu gewährleisten (freigegeben 2026-08-02).

**Command:** `app:cleanup-board-items` (registriert in `routes/console.php` via `->dailyAt('06:00')`).

**Lösch-Regeln:**

| Tabelle | Status (terminal/End) | Bedingung |
|---|---|---|
| `projects` | `bezahlt`, `storniert` | `updated_at` älter als Grace |
| `photo_jobs` | `veroeffentlicht`, `abgebrochen` | `updated_at` älter als Grace |

- **Grace:** `env('BOARD_CLEANUP_GRACE_DAYS', 30)` Tage.
- Löschung ist **hart** (kein Soft-Delete), mit `Log::info`-Eintrag pro Item + Konsolen-Zählung.
- Nur Items in den gelisteten Status sind betroffen — aktive Items bleiben unabhängig vom Alter erhalten.
- Brand-Isolation gilt: Es werden Items aller Brands bereinigt (Command ist nicht user-/brand-gebunden).

> **Wichtig für Datenkonsistenz:** Terminale Status (`storniert`, `abgebrochen`) UND Endstatus (`bezahlt`, `veroeffentlicht`) sind deshalb **nicht** für längere Retrospektiven verfügbar. `workflow_logs` bleiben als Historie erhalten (§9).

---

## 8. Lightroom-Kataloge pro Fotograf (NICHT globale Settings)

Lightroom-Kataloge sind **pro Fotograf** verwaltet — kein Brand-Scope, keine globalen Settings. Pattern-Doku: `features/infrastructure/26-per-user-settings.md` (Per-User-Settings-Pattern; `SettingResolver` bleibt Brand-Achse).

### 8.1 Tabelle & API

- `lightroom_catalogs(id, user_id → users, name, position)`, Unique `(user_id, name)`.
- Endpoints `/api/management/lightroom-catalogs` (Gate: `super_admin` + `photographer`):
  - **Self-Only:** `scopedQuery = where('user_id', auth)` — jeder User liest/schreibt **nur eigene** Kataloge.
  - `GET` → `{ lightroom_catalogs: [...] }` (eigene, nach `position`).
  - `POST`: `user_id` = aktueller User, Unique pro User, `position = max+1` (eigener Scope).
  - `PUT`/`DELETE`: self-only — fremde IDs → 404 (auch für `super_admin`).
- Verwaltung in **„Mein Profil"** (`UserProfileView`, Card „Lightroom-Kataloge"): add/edit/delete der eigenen Liste. Die Photo-Job-Formular-Selectbox liest aus den **eigenen** Katalogen des Users.

### 8.2 Datenschutz-Anzeigeregel

- `photo_jobs.lightroom_catalog` **bleibt ein String** (kein FK, kein Schema-Change).
- Der Server setzt pro Photo-Job-Serialisierung (index/store/update/move) `lightroom_catalog_is_mine` = (Job-Katalog ∈ eigene Katalog-Namen des Viewers).
- Der Rohwert bleibt im Payload (Form-Roundtrip ohne Edit-Datenverlust) — die **UI** rendert die Katalogzeile nur bei `is_mine`; sonst erscheint nur der Name der verantwortlichen Person (owner/assignee). Kein Katalogname-Leak über User-Grenzen.

---

## 9. Phase 2 (Ausblick — wichtig für Datenkonsistenz)

Die gesammelten `workflow_logs` ermöglichen langfristig:

- **durchschnittliche Bearbeitungszeit** pro Board & Status,
- **Engpass-Analyse** (wo bleiben Items hängen),
- **Umsatz-Pipeline** (kaufmännische Auswertung).

**Regel:** Neue Board-Spalten dürfen nur über die Enums erweitert werden. **Kein Status-Freeze** und keine freien Status-Spalten außerhalb der Enums.

---

## 10. Konventionen & Achtung (verbindlich)

- **Feld-Label-Policy** (§3 AGENTS.md): Pflichtfelder tragen `required`; der `*` wird via `index.css` angehängt; `(Optional)` ist verboten.
- **Kein `any` / `@ts-ignore` / `eslint-disable`**.
- **Keine Tailwind-Dynamic-Classes** (z.B. `btn-${color}`), statischer Tailwind-Only-Einsatz.
- **Kein `.style`-Attribut** für statische Werte (nur dynamische Laufzeitwerte).
- **DnD:** File-Drop (Invoice/Upload) bleibt **native `dataTransfer`** — wird **nicht** mit `@dnd-kit/react` gelöst.