# Kanban-Board (Projekte & Bildbearbeitung) — SOLL-Zustands-Dokumentation

Status: SOLL (Target State)
Stand: 2026-08-02
Autor: Florian Reisinger (Senior Architekt)

Diese Datei ist die verbindliche Referenz für die Implementierung des Kanban-Features. Backend und Frontend müssen exakt gegen diese Definition alignen.

---

## 1. Überblick & Zweck

Zwei kanban-ähnliche Boards für Workflow-Transparenz im B2B-Bereich:

- **Projekte-Board** (kaufmännisch): `anfrage → angebot → beauftragt → rechnung → bezahlt`.
- **Bildbearbeitungs-Board** (Produktion): `shooting → culling → bearbeitung → export → veroeffentlicht`.

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

## 3. Datenmodell (Migrationen V033 ff., NEUE separate)

Drei neue Tabellen (siehe §1 AGENTS.md / Migrations-Policy). Jede Migration wird als **neue, separate** Migration angelegt (nicht als Erweiterung einer bestehenden). Alle Tabellen nutzen `UUID`-Primärschlüssel (`HasUuids`), `foreignUuid`-Fremdschlüssel und eine `brand`-Spalte `string(4)` mit Index.

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
| `status` | enum(`ProjectStatus`) | |
| `position` | int | Spalten-Position/Reihenfolge |
| `linked_photo_job_id` | foreignUuid → photo_jobs, nullable | Verknüpfung zur Produktion |
| `created_at`, `updated_at` | timestamps | |

### 2. `photo_jobs`

| Feld | Typ | Hinweis |
|---|---|---|
| `id` | uuid PK | `HasUuids` |
| `brand` | string(4), indexiert |
| `owner_id` | foreignUuid → users | Pflicht |
| `assignee_id` | foreignUuid → users, nullable | |
| `title` | string | |
| `lightroom_catalog` | string, nullable | |
| `total_count` | int | Gesamtanzahl Bilder |
| `selected_count` | int | selektierte Anzahl |
| `target_gallery_id` | foreignUuid → galleries, nullable | Ziel-Galerie |
| `is_private` | boolean | |
| `status` | enum(`PhotoJobStatus`) | |
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
}

enum PhotoJobStatus: string
{
    case SHOOTING        = 'shooting';
    case CULLING         = 'culling';
    case BEARBEITUNG     = 'bearbeitung';
    case EXPORT          = 'export';
    case VEROEFFENTLICHT = 'veroeffentlicht';
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

### Hooks & Permissions

- Hooks `useProjectsBoard` / `useProductionBoard` nach dem `usePayouts`-Pattern (SWR + `fetcher`/`apiMutate`).
- `usePermissions` erweitern:
  - `canAccessProjectsBoard = isAdmin`
  - `canAccessProductionBoard = isPhotographer`

---

## 7. Phase 2 (Ausblick — wichtig für Datenkonsistenz)

Die gesammelten `workflow_logs` ermöglichen langfristig:

- **durchschnittliche Bearbeitungszeit** pro Board & Status,
- **Engpass-Analyse** (wo bleiben Items hängen),
- **Umsatz-Pipeline** (kaufmännische Auswertung).

**Regel:** Neue Board-Spalten dürfen nur über die Enums erweitert werden. **Kein Status-Freeze** und keine freien Status-Spalten außerhalb der Enums.

---

## 8. Konventionen & Achtung (verbindlich)

- **Feld-Label-Policy** (§3 AGENTS.md): Pflichtfelder tragen `required`; der `*` wird via `index.css` angehängt; `(Optional)` ist verboten.
- **Kein `any` / `@ts-ignore` / `eslint-disable`**.
- **Keine Tailwind-Dynamic-Classes** (z.B. `btn-${color}`), statischer Tailwind-Only-Einsatz.
- **Kein `.style`-Attribut** für statische Werte (nur dynamische Laufzeitwerte).
- **DnD:** File-Drop (Invoice/Upload) bleibt **native `dataTransfer`** — wird **nicht** mit `@dnd-kit/react` gelöst.