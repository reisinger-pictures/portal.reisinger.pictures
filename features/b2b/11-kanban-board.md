# Kanban-Board (Projekte & Bildbearbeitung) — SOLL-Zustands-Dokumentation

Status: SOLL (Target State)
Stand: 2026-09-24
Autor: Florian Reisinger (Senior Architekt)

Diese Datei ist die verbindliche Referenz für die Implementierung des Kanban-Features. Backend und Frontend müssen exakt gegen diese Definition alignen.

---

## 1. Überblick & Zweck

Zwei kanban-ähnliche Boards für Workflow-Transparenz im B2B-Bereich:

- **Projekte-Board** (kaufmännisch): `anfrage → angebot → beauftragt → rechnung → bezahlt` (+ terminal `storniert`).
- **Bildbearbeitungs-Board** (Produktion): `importiert → culling → bearbeitung → exportiert` (+ terminal `abgebrochen`).

Terminale Status (`storniert` / `abgebrochen`) und Endstatus (`bezahlt` / `exportiert`) unterliegen der Auto-Cleanup-Policy (§7).

Die Boards visualisieren den Fortschritt von eingehender Anfrage bis zur Auslieferung. Sie sind der zentrale operative Überblick für Admin (kaufmännisch) und Fotograf (Produktion).

---

## 2. Rollen- & Sichtbarkeits-Matrix (verbindlich)

| Board | Zugriff-Rollen | Sichtbarkeit |
|---|---|---|
| Projekte | `super_admin`, `admin` | Super-Admin: alle; Admin: nur eigene |
| Bildbearbeitung | `super_admin`, `photographer` | Super-Admin: alle; Fotograf: nur eigene |

### Owner-Modell

- `owner_id` = Ersteller beim Anlegen; wird **automatisch** auf den aktuellen User gesetzt (nicht client-wählbar).
- Optionales `assignee_id` für Reassign / Zuweisung.
- Die Position ist owner-scoped: Reindex-/Cleanup-Operationen verändern nur die
  Status-Spalte des betroffenen Eigentümers und niemals die Positionen eines
  fremden Eigentümers.

### Sichtbarkeits-Query (Backend)

- `super_admin` → alle Datensätze.
- sonst → `WHERE owner_id = me OR assignee_id = me`.

### Board-Gate

- **Projekte**: nur `admin` / `super_admin`.
- **Bildbearbeitung**: nur `photographer` / `super_admin` (Super-Admin greift immer durch).

---

## 3. Datenmodell (historische V025–V027; V038 Frontier)

`V025__consolidated_after_v024.php` is the historical migration that creates
the board tables (`photo_jobs`, `projects`, `workflow_logs`, and
`lightroom_catalogs`). `V026` adds the board notes and removes the obsolete
`is_private` flag; `V027` migrates the photo-job workflow to the current status
values in §4. These migrations are historical/deployed inputs and are not
rewritten. The current repository frontier is `V038` (V037 Guest-Ownership,
V036 Card-Testing); every new schema change is a separate `V039+` migration.
No already deployed migration (`V001`–`V035`) may be amended.

The board tables use UUID primary keys (`HasUuids`), `foreignUuid` foreign keys,
and an indexed `brand` column (`string(4)`). The current status enums, including
`storniert` and `abgebrochen`, are the result of the V025 schema plus the V027
photo-job status migration.

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
| `notes` | text, nullable | interne Notiz |
| `linked_photo_job_id` | foreignUuid → photo_jobs, nullable | Verknüpfung zur Produktion (Handoff, §5.1) |
| `created_at`, `updated_at` | timestamps | |

### 3.2 `photo_jobs`

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
| `notes` | text, nullable | interne Notiz |
| `status` | enum(`PhotoJobStatus`) | inkl. terminal `abgebrochen` |
| `position` | int | |
| `created_at`, `updated_at` | timestamps | |

### 3.3 `workflow_logs`

| Feld | Typ | Hinweis |
|---|---|---|
| `id` | integer PK (auto-increment) | Workflow-Log-ID |
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
    case IMPORTIERT      = 'importiert';
    case CULLING         = 'culling';
    case BEARBEITUNG     = 'bearbeitung';
    case EXPORTIERT      = 'exportiert';
    case ABGEBROCHEN     = 'abgebrochen';

    public static function initial(): self
    {
        return self::IMPORTIERT;
    }
}

enum PaymentStatus: string
{
    case OPEN         = 'open';
    case PARTLY_PAID  = 'partly_paid';
    case PAID         = 'paid';
}
```

### 4.1 Status-Validierung (Übergang, nicht Zustand)

Die Enum-Listen sind die einzige Quelle der Wahrheit; `ModelStatusGuard::assertTransitionAllowed()` validiert **ausschließlich ein tatsächlich geschriebenes Attribut**:

- Ein **unberührter** Status wird nie validiert. Eine Zeile mit einem Legacy-Wert (vor dem Enum geschrieben, per Import, direktes SQL) bleibt damit über Notes/Position/Uploads hinweg speicherbar, statt jeden Save mit `InvalidArgumentException` (HTTP 500) abzubrechen.
- Ein **geschriebener** Wert außerhalb der Enum-Liste — inklusive `null` — bleibt verboten. Der Guard erzeugt also nie einen neuen ungültigen Zustand; er schützt nur den Übergang.
- Erlaubnislisten: `Project::allowedStatuses()` / `allowedPaymentStatuses()` bzw. `PhotoJob::allowedStatuses()` (enum-abgeleitet). `orders.status` nutzt `Order::ALLOWED_STATUSES` (kein Enum, siehe [Galerie-/Core-Architektur](../gallery/01-core-architecture.md#7-status-column-guard-transition-only-validation)).

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
| POST | `/projects` | `owner` = current User, `brand` via `BrandRegistry`, `status` = `anfrage`, `position` = dichtes Ende der owner-scoped Spalte (`0..n-1`) | 201 |
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

- **ManagementMiddleware / Gates:** Die Produktions-Endpoints unter `api/management/photo-jobs*` sind für `photographer` zugänglich; die Sichtbarkeit bleibt zusätzlich auf den jeweiligen Owner/Assignee-Scope begrenzt.
- Sichtbarkeits-Scoping: wie §6 (Owner nein → alle; sonst `owner_id`/`assignee_id`).

### 5.1 Projekt → Bildbearbeitung-Übernahme (Handoff)

`POST /api/management/projects/{id}/handoff` — **nur `super_admin`**.

- Erzeugt einen `photo_job`: `brand` = Project-Brand, `owner_id` = aktueller User, `title` = `client_name`, `status` = `PhotoJobStatus::initial()` (`importiert`), `position` = dichtes Ende der owner-scoped Spalte.
- Setzt `project.linked_photo_job_id` auf die neue Photo-Job-ID.
- Ist bereits ein `linked_photo_job_id` gesetzt → **422** (`already_handed_off`, kein Doppel-Handoff).
- Antwort: `{ "photo_job": {...} }` (201).
- Der Projekt-Status wird beim Handoff **nicht** verändert.

---

## 6. Frontend

### View & Routing

- Zwei Board-Ansichten:
  - `ui/management/ManagementProjectsBoard.tsx` → `/admin-projects` bzw. eingebettet unter `/boards?tab=projects`
  - `ui/photographer/PhotographerProductionBoard.tsx` → eingebettet unter `/boards?tab=production`
- Routing via `App.tsx`: lazy-loaded + `ProtectedRoute` mit `requiredFeature: 'b2b'`; die Board-Seite wählt den Tab über die URL, die ältere Projekt-View bleibt unter `/admin-projects` erreichbar.

### Sidebar

- Projekte-Eintrag unter Sichtbarkeit `{isAdmin}`.
- Produktion-Eintrag unter Sichtbarkeit `{isPhotographer}`.

### Drag & Drop (DnD)

- Paket: **`@atlaskit/pragmatic-drag-and-drop` (NUR dieses Paket)** — migriert von `@dnd-kit/react` am 2026-08-04.
- `draggable()` + `dropTargetForElements()` (`/element/adapter`) für Karten, `dropTargetForElements()` für Spalten (Empty-State / Append), `monitorForElements()` als zentraler Drop-Handler im Board.
- Karten sind `draggable` UND Drop-Target (HitBox: Drop oberhalb/unterhalb der Zielkarte → exakte `position`). Spalten-Drop → Append ans Ende. `combine()` bündelt Cleanups; `disableNativeDragPreview()` unterdrückt das native Drag-Preview (Quelle wird per `opacity-30` gedimmt, wie bisher).
- Native-HTML5-DnD-Events (`dragstart`/`dragover`/`drop`) — kein DOM-Moving durch die Library (der dnd-kit `removeChild`-Workaround entfällt).
- Bei Status-/Positionsänderung → `PATCH .../move`.

### UX: Geräte- & Rollen-Support (SOLL)

- **Drag & Drop nur am Desktop (≥768px) und nur für `super_admin`.** `disallowDrag = !isSuperAdmin || !useIsDesktop()` (beide Boards). Auf Mobilgeräten (Viewport < 768px, `window.matchMedia('(min-width: 768px)')`) und für alle anderen Rollen werden Karten ohne Drag-Handler gerendert.
- **Status-Select in der Karte als Fallback (alle Geräte & Rollen):** Jede Karte trägt ein kompaktes `<select aria-label="Status ändern">` mit allen Spalten-Status. Die Auswahl ruft `move(id, status, position)` mit `position = Anzahl der Items der Zielspalte (ohne das verschobene Item)` auf → die Karte wird ans Ende der Zielspalte verschoben. Fehler → Toast. Dadurch bleibt die Statusänderung auch auf Mobile und für Nicht-Super-Admins vollständig nutzbar.

### Formulare

- `react-hook-form` + `@hookform/resolvers/zod`.
- Pflichtfelder tragen `required`-Attribut; keine `(Optional)`-Labels (§3 Field-Label-Policy).

### Phase-2-UI

- **Handoff (Projekte-Karte, nur `super_admin`):** Button „In Bildbearbeitung übernehmen" → `handoff`-API → Toast + `mutate()`. Bei gesetztem `linked_photo_job_id` stattdessen Badge („Übernommen" / Link zur Produktion).
- **Lightroom-Katalog-Select (Photo-Job-Formular):** Optionen = **eigene** Kataloge des Users (`useLightroomCatalogs`). Beim Editieren fremder Kataloge bleibt der Rohwert erhalten (kein Clearen).
- **Katalog-Anzeige (Produktions-Board-Karte):** Katalogzeile rendern, wenn `lightroom_catalog_is_mine` **oder** Viewer ist `super_admin`; sonst nur die Personenzeile (owner/assignee). Super-Admin hat als übergreifender Overseer immer die Katalog-Info sichtbar.
- **Profil:** Card „Lightroom-Kataloge" in `UserProfileView` (nur Fotograf/Super-Admin) — add/edit/delete der eigenen Liste.

### Hooks & Permissions

- Hooks `useProjectsBoard` / `useProductionBoard` nach dem `usePayouts`-Pattern (SWR + `fetcher`/`apiMutate`).
- `usePermissions` erweitern:
  - `canAccessProjectsBoard = isAdmin`
  - `canAccessProductionBoard = isPhotographer`

---

## 7. Auto-Cleanup (Terminale Status + 7d-Grace)

Abgeschlossene oder abgebrochene Items werden **automatisch hart gelöscht**, um Board-Hygiene zu gewährleisten (freigegeben 2026-08-02).

**Command:** `app:cleanup-board-items` (registriert in `routes/console.php` via `->dailyAt('06:00')`).

**Lösch-Regeln:**

| Tabelle | Status (terminal/End) | Bedingung |
|---|---|---|
| `projects` | `bezahlt`, `storniert` | `updated_at` älter als Grace |
| `photo_jobs` | `exportiert`, `abgebrochen` | `updated_at` älter als Grace |

- **Grace:** `env('BOARD_CLEANUP_GRACE_DAYS', 7)` Tage.
- Löschung ist **hart** (kein Soft-Delete), mit `Log::info`-Eintrag pro Item + Konsolen-Zählung.
- Nur Items in den gelisteten Status sind betroffen — aktive Items bleiben unabhängig vom Alter erhalten.
- **Referenzschutz (Handoff):** `photo_jobs`, die von einem noch existierenden Projekt via `linked_photo_job_id` referenziert werden (§5.1), werden **übersprungen** — die Handoff-Referenz schützt den Job vor hartem Löschen, auch wenn er bereits `exportiert`/`abgebrochen` ist und die Grace überschritten hat. Erst wenn das referenzierende Projekt selbst (z.B. via Cleanup oder manuell) gelöscht wurde, ist der Job wieder ein Kandidat.
- **Positions-Cleanup:** Nach jeder Löschung werden die betroffenen owner-scoped Statusspalten unter dem Board-Lock auf `0..n-1` reindexiert. Projekte aller Brands werden vor Photo-Jobs geprüft, damit eine freigegebene Cross-Brand-Handoff-Referenz im selben Lauf konsistent berücksichtigt wird.
- **Semantik von `updated_at`:** Ein position-only Move speichert die verschobene Karte mit einem normalen Model-Save und aktualisiert damit `updated_at`; die Cleanup-Grace misst daher die letzte Board-Mutation, nicht ausschließlich den letzten Statuswechsel. Reindex-Schreibvorgänge auf nicht betroffenen Karten verwenden dagegen `saveQuietly` und verändern deren Zeitstempel nicht. Eine business-age-treue Reihenfolge-Semantik wäre eine separate Vertragsentscheidung.
- Brand-Isolation gilt: Es werden Items aller Brands bereinigt (Command ist nicht user-/brand-gebunden).

> **Wichtig für Datenkonsistenz:** Terminale Status (`storniert`, `abgebrochen`) UND Endstatus (`bezahlt`, `exportiert`) sind deshalb **nicht** für längere Retrospektiven verfügbar. `workflow_logs` bleiben als Historie erhalten (§9).

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

### 8.2 Katalog-Anzeigeregel (Display-Layer Convenience — keine Server-Secrecy)

- `photo_jobs.lightroom_catalog` **bleibt ein String** (kein FK, kein Schema-Change) und wird **immer** im Payload serialisiert — für den Form-Roundtrip des Edit-Modals (Select-Optionen).
- Der Server setzt pro Photo-Job-Serialisierung (index/store/update/move) `lightroom_catalog_is_mine` = (Job-Katalog ∈ eigene Katalog-Namen des Viewers). Das Flag ist ein **Display-Convenience-Flag**, keine Sicherheitsgrenze: Es entscheidet nur, was die UI standardmäßig ausgibt.
- Die **UI** rendert die Katalogzeile für Fotografen nur bei `is_mine` (Fremdkataloge ausgeblendet); **`super_admin` sieht die Katalogzeile immer** (übergreifender Overseer, besitzt i.d.R. keine eigenen Kataloge).
- **Keine Server-Secrecy:** Da der Rohwert im Payload liegt, ist die Anzeigeregel nicht als Schutz vor Datenausleitung zu verstehen (ein modifizierter Client könnte den Namen stets auslesen) — sie ist reine UX-Konvention, damit fremde Katalog-Namen nicht ungewollt prominent auf der Karte erscheinen.

---

## 9. Phase 2 (Ausblick — wichtig für Datenkonsistenz)

Die gesammelten `workflow_logs` ermöglichen langfristig:

- **durchschnittliche Bearbeitungszeit** pro Board & Status,
- **Engpass-Analyse** (wo bleiben Items hängen),
- **Umsatz-Pipeline** (kaufmännische Auswertung).

**Regel:** Neue Board-Spalten dürfen nur über die Enums erweitert werden. **Kein Status-Freeze** und keine freien Status-Spalten außerhalb der Enums.

---

## 10. Konventionen & Achtung (verbindlich)

- **Feld-Label-Policy** (siehe `frontend/AGENTS.md`): Pflichtfelder tragen `required`; der `*` wird via `index.css` angehängt; `(Optional)` ist verboten.
- **Kein `any` / `@ts-ignore` / `eslint-disable`**.
- **Keine Tailwind-Dynamic-Classes** (z.B. `btn-${color}`), statischer Tailwind-Only-Einsatz.
- **Kein `.style`-Attribut** für statische Werte (nur dynamische Laufzeitwerte).
- **DnD:** File-Drop (Invoice/Upload) bleibt **native `dataTransfer`** — wird **nicht** mit `@dnd-kit/react` gelöst.

## 11. Verification boundary

Diese Datei definiert den SOLL-Vertrag, nicht den Abschlussstatus der
Implementierung. Der aktuelle E2E-Abdeckungs- und Rest-Task-Stand gehört in
`AGENTS.todo.md`; ein grüner Teil-Lauf (beispielsweise `@smoke`) ist keine
Aussage, dass alle Board-Pfade oder Rollen vollständig verifiziert sind.

Die vorhandenen Board-Specs (`frontend/tests/e2e/admin/projects-board.spec.ts`
und `frontend/tests/e2e/photographer/production-board.spec.ts`) verwenden wegen
ihres gemeinsamen dirty Board-Zustands einen seriellen Testmodus. Zusätzlich
laufen `project-clear-fields` und `brand-settings` seriell, während
`billing-details` wegen der globalen Settings im dedizierten CI-Shard isoliert
wird. Die Tests erzeugen und räumen ihre eigenen Fixtures dennoch in
`beforeEach`/`afterEach` auf. Neue Tests dürfen diese Ausnahme nicht als
Abhängigkeit zwischen Tests verstehen; die vollständige Auswahl steht in
`features/e2e-test-strategy.md`.
