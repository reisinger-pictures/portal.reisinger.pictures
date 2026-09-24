# Model-Registrierung über Einladungslink (CRM)

**Status:** Current SOLL / implemented (reviewed 2026-09-24). The base
registration schema is **V033**; **V034** adds encrypted profile storage,
person photos, lifecycle state, and profile-access tokens; **V035** makes the
act-manager reference nullable for succession. The complete current contract is
in [`06-model-profile-iteration.md`](06-model-profile-iteration.md) and
[`07-model-contact-sheet-export.md`](07-model-contact-sheet-export.md).

Ein Admin lädt eine Managerperson mit einem Einmal-Token ein. **Primärflow ist
der kopierbare Magic Link** (z. B. für WhatsApp); der E-Mail-Versand ist ein
optionales Extra (nur bei angegebener Adresse). Die Managerperson registriert
**ohne Account** einen **Act** mit 1..n **Personen**. Jede Person ist ein eigener
CRM-`Customer` + `ModelProfile` und kann optional ein passwortloses Portal-Konto
erhalten. Der Token ist danach verbraucht; der **einladende User** erhält eine
Erfolgsmail.

## Begriffe

| Begriff | Bedeutung |
|---|---|
| **Customer** | Bestehendes CRM-Objekt (`customers`), Basis für alles. |
| **Model** | Customer mit `model_profile` (`customers.is_model = true`). |
| **Act** | Registrierungs-/Buchungseinheit aus 1..n Personen + einer Managerperson. |
| **Manager** | Die Person, die den Act ausgefüllt hat (`act_members.role = manager`). |

## Datenmodell

### `customers` (erweitert)

| Spalte | Typ | Hinweis |
|---|---|---|
| `user_id` | uuid nullable | Optionales Portal-Konto (FK `users`, app-seitig gesetzt). |
| `is_model` | boolean, default false | Marker für Customer mit ModelProfile. |

### `model_profiles` (1:1 zu `customers`)

| Spalte | Typ | Hinweis |
|---|---|---|
| `id` | uuid PK | |
| `customer_id` | uuid unique FK | cascade delete. |
| `catalog_version` | string(20) | z. B. `v1`. |
| `answers` | text | Laravel `encrypted:array` snapshot `{scope,key,label,type,value}` (person-scope; ciphertext, not JSON text). |
| `gender` | string(20) nullable | `female\|male\|diverse\|null`. |
| `age_proof_required` | boolean | Bei jedem aktuellen Submit/Update `true`. |
| `age_proof_path` | string nullable | Pfad zu einer verschlüsselten Datei auf privater `local`-Disk. |
| `age_proof_uploaded_at` | timestamp nullable | |
| `submitted_at` | timestamp | |
| `last_confirmed_at` | timestamp nullable | Lifecycle-Anker; V034. |
| `last_reminder_stage`/`last_reminder_at` | string/timestamp nullable | Idempotente T+12/13/14-Reminder; V034. |
| `created_at`/`updated_at` | timestamps | |

### `acts`

| Spalte | Typ | Hinweis |
|---|---|---|
| `id` | uuid PK | |
| `brand` | string(20) | Brand-Isolation. |
| `manager_customer_id` | uuid nullable FK `customers` | Managerperson; V035 uses `nullOnDelete` so succession can run before deletion. |
| `act_type` | string(20) | `single\|couple\|group` — **abgeleitet** aus `person_count`. |
| `catalog_version` | string(20) | |
| `answers` | json nullable | Act-Snapshot. |
| `person_count` | int | |
| `submitted_at` | timestamp | |

### `act_members` (Pivot)

`id`, `act_id` (FK), `customer_id` (FK), `role` (`manager|member`), `position`,
`unique(act_id, customer_id)`, timestamps.

### `model_registration_invites`

`id`, `token` (unique, 64 Zeichen), `email` (nullable), `label` (nullable,
Name/Notiz zur Einladung, z. B. „Maria Muster / IG"), `brand`, `invited_by`
(FK `users`), `expires_at`, `used_at` nullable, `act_id` nullable, `customer_id`
nullable, `created_at`. (`updated_at` = null.)

### `model_photos` (V034)

`id`, `customer_id`/`model_profile_id` (FKs), `path` (random filename on the
encrypted private `local` disk), `original_name`, `mime_type`, `size_bytes`,
`visibility` (`public|internal`, default `internal`), `is_primary`, `position`,
timestamps. At most five photos are accepted per person. A primary photo must
be `public`; the owner can clear the primary flag.

### `model_access_tokens` (V034)

`id`, `customer_id` (FK), `token` (unique, 64 characters), `expires_at`
(24-hour TTL), `last_used_at`, `revoked_at`, `created_by`, timestamps. Issuing a
new link revokes the customer's previous active link.

## Fragenkatalog (`App\Services\ModelQuestionnaire`)

- **Code-first, ein Katalog:** `CURRENT = 'v1'` (Single-Katalog). Neue Fragen
  erfordern einen `CURRENT`-Bump + eine eingefrorene Altdefinition;
  `catalog_version` wird im Answers-Snapshot festgehalten.
- **Fragefelder:** `key`, `label`, `type` (`text/textarea/date/select/multiselect/number/checkbox/file/url/tel/email`),
  `required`, `options?`, `option_labels?`, `description?`, `searchable?`, `scope` (`act|person`), `visible_if?`.
- **`visible_if`-Bedingungen:** `is_manager`, `multiple_persons`, `answer_equals`.
- **Sektionen:** A Basisdaten, B Aussehen & Maße (minimal, **kein Gewicht**),
  C Erfahrung pro Shooting-Kategorie + Portfolio, G Einwilligungen, H Sonstiges
  (Act-/Person-Notizen, keine Frage-Prompts).
- **Shooting-Kategorien:** portrait, fashion, business, boudoir, bikini*, akt*,
  sport, couple_family (* = `requires_age_proof`-Metadatum; **kein** Erotik).
- **Bereitschaft:** pro Kategorie `willingness_<key>` (5 Stufen, required) **vor**
  der Erfahrung, zusätzlich `willingness_stock`.
- **Altersnachweis:** für **jede** Person Pflicht (`age_proof`, ohne
  `visible_if`) — unabhängig von Kategorie und Alter.
- **Agentur:** `agency_name` (text) + `agency_link` (url), beide optional.
- **Snapshot:** Beim Submit werden `catalog_version` und die sichtbaren Antworten
  als vollständiger `answers`-Snapshot (inkl. `label`) gespeichert. **Datei-Fragen
  (`age_proof`)** werden bewusst **nicht** als Answer (`value => null`) abgelegt —
  der Upload lebt ausschließlich in den Upload-Metadaten (`age_proof_path`,
  `age_proof_uploaded_at`).
- **Kein Meilisearch im öffentlichen Flow:** `ModelProfile` ist bewusst **nicht**
  `Searchable`; die Customer-Anlage im Submit läuft in
  `Customer::withoutSyncingToSearch(...)`. Der login-freie Endpoint hängt damit
  nicht von der Verfügbarkeit von Meilisearch ab.

## API-Vertrag (eingefroren für das Frontend)

### Öffentlich (Token = Credential, `throttle:model-registration`)

| Methode | Pfad | Body | Antwort |
|---|---|---|---|
| `GET` | `/api/model-registration/{token}` | – | `200 {brand, status:"open", email, person_count:0, expires_at, catalog_version, categories[], sections{}}`; `404` unbekannt; `410` abgelaufen/verbraucht. |
| `POST` | `/api/model-registration/{token}` | multipart (s. u.) | `201 {success:true, act_id, person_count}`; `404`/`410`; `409` Race (bereits verbraucht); `422` Katalog-Validierung. |

**Submit-Body (multipart):**

```
persons[<i>][answers][<key>]     Person-scope Antworten (Katalog v1)
persons[<i>][create_account]     Portal-Konto-Checkbox (optional). Akzeptierte
                                 Werte: true/false, "true"/"false", 1/0, "1"/"0",
                                 "on"/"off", "yes"/"no" (Auswertung via
                                 FILTER_VALIDATE_BOOLEAN). Fehlend/"false" ⇒ kein Konto.
persons[<i>][age_proof]          Datei (jpg/jpeg/png/webp/pdf, max 10 MB)
                                 Pflicht für jede Person (immer)
persons[<i>][photos][<j>][file]          Foto (jpg/jpeg/png/webp, max 10 MB)
persons[<i>][photos][<j>][visibility]   public|internal, Default internal
persons[<i>][photos][<j>][is_primary]  Boolean; ein Primary muss public sein
act[answers][act_notes]          Act-Notizen
manager_index                    Index der Managerperson (default 0)
```

`persons[i][photos]` ist auf maximal 5 Einträge begrenzt (server-autoritativ;
422-Feld-Key `persons.<i>.photos`). Nicht-sequelle Foto-Indizes sind erlaubt;
Metadaten und Datei werden über denselben Index verknüpft.

Validierungsfehler kommen mit präfixierten Keys zurück, z. B.
`persons.0.answers.last_name`, `persons.1.age_proof`,
`persons.0.photos`, `persons.0.photos.0.is_primary`.

### Management (auth + `management`-Middleware, Gate `isAdmin`)

| Methode | Pfad | Beschreibung |
|---|---|---|
| `GET` | `/api/management/model-invites` | Liste (open/redeemed/expired), brand-gescoped. Pro Eintrag: `id`, `email` (nullable), `label`, `link` (Magic Link inkl. Token), `brand`, `status`, `expires_at`, `used_at`, `act_id`, `customer_id`, `invited_by`, `created_at`. |
| `POST` | `/api/management/model-invites` | `{email?, label?}` → `201 {success, link, invite}`. `email` und `label` sind je optional (`nullable`, max 255); `email` muss gültig sein. Token 64 Zeichen, `expires_at = now + 7 Tage`. **Mail geht nur raus, wenn `email` vorhanden ist.** |
| `DELETE` | `/api/management/model-invites/{id}` | Revoke (brand-gescoped). |
| `GET` | `/api/management/models` | Model-Suche. Filter: `q`, `gender`, `city`, `country`, `age_min`, `age_max`, `category`, `act_type`. |
| `GET` | `/api/management/models/{id}/age-proof` | auth-gated Download aus privater Disk. |

> **Filter-Implementierung:** `q`, `gender`, `city`, `country`, `age_min/max`,
> `act_type` filtern per DB. `category` wird nach dem DB-Load in-memory gegen den
> Answers-Snapshot geprüft (versionsübergreifend stabil, ohne JSON-DB-Query).
> Skalierungsgrenze: bei sehr vielen Models wächst der `category`-Filter linear
> mit der Ergebnismenge — bei Bedarf später auf eine denormalisierte
> `categories`-Spalte/Scout umstellen.

Admin-Liste liefert pro Model u. a. `display_name`, `birthdate`, `age`,
`gender`, `city`, `country`, `categories[]`, `act_types[]`, `catalog_version`,
`age_proof_required`, `age_proof_uploaded_at`, `submitted_at`, `answers[]`.

## Frontend (React/Vite)

**Öffentliche Route** `/model-registrierung/:token` (Gast-Layout, kein Login) —
`src/ui/ModelRegistrationView.tsx`:

- `GET /api/model-registration/{token}` über `useModelRegistration`
  (`src/logic/useModelRegistration.ts`), dann dynamisches Rendern aus
  `sections{}`/`categories[]` — **keine hartcodierten Fragen**.
- **Wiederholbare Personenblöcke** (`useFieldArray`): hinzufügen/entfernen;
  `manager_index` per Radio wählbar (wird beim Entfernen geclampt).
- Sektionen A/B/C/G/H in Katalogreihenfolge; Fragetypen
  `text/textarea/date/select/multiselect/number/checkbox/file/url/tel/email`.
- **`visible_if`** (`is_manager`, `multiple_persons`, `answer_equals`) wird
  client-seitig gespiegelt (`isQuestionVisible`); der Altersnachweis ist
  bedingungslos Pflicht.
- **Zod-Schema-Factory** `createRegistrationSchema(catalog)`
  (`src/logic/modelRegistration.ts`) — Factory wegen der
  Lingui-Module-Scope-Regel; `superRefine` wertet `visible_if` und
  Manager-Scope gegen die aktuellen Werte aus.
- **Multipart-Submit** über `buildRegistrationFormData()` (`apiUpload` in
  `src/api.ts`): Booleans als `"1"`/`"0"`, Multiselect als `key[]`,
  Altersnachweis als `persons[i][age_proof]`, Fotos als
  `persons[i][photos][j][file|visibility|is_primary]`, `manager_index` als String.
- **Fehlerzustände:** 404 (unbekannt), 410 (verbraucht/abgelaufen) und
  409 (Race) führen **sowohl bei `check` (GET) als auch beim `submit` (POST)
  auf dieselbe Fehlerseite** — das Formular wird dann nicht mehr angeboten.
  422 wird feldweise gemappt
  (`persons.1.age_proof` → `persons.1.age_proof`, `act.answers.*` →
  `act_answers.*`); andere Status (z. B. 500) bleiben ein Inline-Fehler am
  Formular.

**Admin-UI** (Management-Dashboard, `requiredFeature="b2b"`):

- `/admin-model-invites` — `ManagementModelInvitesView.tsx`: Anlage per
  **Name/Notiz (`label`, optional) und E-Mail (optional)** (Zod + RHF).
  Der kopierbare **Magic Link ist der Primary Flow**: nach dem Anlegen wird der
  Link prominent angezeigt (Read-only-Input + Kopieren-Button, Clipboard-API mit
  `execCommand`-Fallback) und in der Liste pro Zeile kopierbar. Wurde eine
  E-Mail angegeben, zeigt der Erfolgs-Hinweis „Zusätzlich per E-Mail versendet".
  Liste mit Status (offen/eingelöst/abgelaufen) und Revoke (`useModelInvites`;
  defensives Link-Lesen `data.link ?? data.invite.link` via
  `inviteLinkFromCreate`).
- `/admin-models` — `ManagementModelsView.tsx` + `ModelDetailModal.tsx`:
  Filter `q, gender, city, country, age_min, age_max, category, act_type`
  (`useModels` → `/api/management/models`), Snapshot-Rendering der `answers[]`
  und Banner **„Profil aktualisieren"**, wenn
  `catalog_version < CURRENT_MODEL_CATALOG_VERSION` (`v1`).
  Altersnachweis nur als Status + Link auf den auth-gated Download-Endpunkt.
- **Label-Auflösung (i18n):** Kategorien/Geschlecht/`act_type` werden über
  `modelFilterCategories()`, `modelGenderLabel()`, `modelCategoryLabel()`,
  `modelActTypeLabels()` (`src/logic/modelRegistration.ts`) in deutsche Labels
  übersetzt statt roh gerendert; unbekannte Keys fallen auf den Rohwert zurück.
  Die Funktionen sind **Factories** (kein Modul-Scope-`t`).

Tests: `src/logic/__tests__/modelRegistration.test.ts` (Schema-Factory +
Label-Resolver), `ModelRegistrationForm.test.tsx` (Personen-State,
`manager_index`, `visible_if`, 422-Mapping, **409/410/404 → Fehlerseite**),
`useModelRegistration.test.ts` (Hooks/API inkl. `buildCreateInviteBody` /
`inviteLinkFromCreate`) sowie `tests/e2e/crm/model-registration.spec.ts`
(`@feature:model-registration` + `@smoke` Admin-Gate): Mail-Flow **und
No-Mail-Magic-Link-Flow** (Einladung nur mit Label → Link aus der UI lesen →
als Gast öffnen).

## Sicherheit / DSGVO

- Token: 64 Zeichen random, **Einmal-Nutzung atomar** über
  `UPDATE ... WHERE token/id = ? AND used_at IS NULL` (0 Zeilen ⇒ `409`), Ablauf 7 Tage.
- Rate-Limit auf den öffentlichen Endpunkten über den **benannten** Limiter
  `model-registration` (`MODEL_REGISTRATION_THROTTLE_LIMIT`, Default 10/min, Key = IP).
  Bewusst **kein** positional `throttle:<max>,<decay>`: dessen Key `sha1(domain|ip)`
  wird über alle positional-throttled Routen geteilt (Auth-Gruppe, Invite-Redeem).
  Ein Burst von Login-/Invite-Traffic (E2E mit parallelen Workern, oder NAT/Mobilfunk
  mit geteilter IP) würde sonst das Model-Budget verbrauchen und den ersten öffentlichen
  Aufruf mit 429 abweisen. Der benannte Limiter hat einen eigenen, namensgebundenen Key
  (`md5('model-registration'.$ip)`) und bleibt in Prod mit 10/min begrenzt; in E2E/CI
  wird er über `MODEL_REGISTRATION_THROTTLE_LIMIT=1000` angehoben.
- **Admin-Gate** `AuthorizationService::isAdmin`; Brand-Isolation über die Marke
  des Admins (brand-gebundene Admins sind auf ihre Marke beschränkt).
- **Token im Admin-Listing:** `GET /api/management/model-invites` enthält den
  vollständigen `link` (inkl. Einmal-Token), damit der Admin den Magic Link
  jederzeit erneut kopieren kann. Bewusst admin-only; der Token bleibt ein
  64-Zeichen-Secret mit 7 Tagen TTL und Einmal-Nutzung.
- **Keine Account-Enumeration:** Portal-Konten entstehen nur per expliziter Checkbox;
  ein vorhandener fremd-brand Account wird nicht verknüpft.
- Altersnachweise und Personen-Fotos liegen **verschlüsselt** auf der privaten
  `local`-Disk (`storage/app/private`), Download nur über auth-gated Endpunkte.
  `model_profiles.answers` wird als Laravel `encrypted:array` gespeichert. Kein
  `public`-Storage.
- Customer-Dedupe per E-Mail **innerhalb der Marke**; fremd-brand Kunden werden nicht
  wiederverwendet.
- **Erfolgs-/Aktivierungsmails nutzen den Invite-Brand** (nicht den Request-Host),
  damit das Branding auch beim Öffnen des Links auf einem fremden Host stimmt.
- **Orphan-Cleanup:** Schlägt die Submit-Transaktion nach dem Altersnachweis-Upload
  fehl, werden die frisch gespeicherten Dateien im `catch` wieder gelöscht
  (keine verwaisten Ausweisdaten auf der Disk).

### Akzeptierte Risiken

- **404 vs. 410:** Für die UX bewusst unterschieden (unbekannter vs. abgelaufener/
  verbrauchter Token). Das ist ein bewusst akzeptiertes Informationsleck über die
  Token-Gültigkeit — Tokens sind 64-Zeichen-Secrets mit 7 Tagen TTL.

## Offene Punkte / später

- **Löschkonzept Altersnachweise** (Aufbewahrungsfrist, automatische Löschung nach
  Verifikation) — vor Go-live festlegen.
- Profilaktualisierung: **umgesetzt** über den 24h-Profile-Magic-Link
  (`GET/POST /api/model-profil/{token}`) und `GET /api/me/models`; der Owner
  darf Answers, Altersnachweis (bei Bedarf) sowie Sichtbarkeit/Primary-Flag
  bestehender Fotos aktualisieren. Management-Admin-CRUD bleibt separat.
- Bestätigungs-/Bestätigungslink je Person (fremde personenbezogene Daten) als
  rechtlicher Ausbau.
- Formular-Performance bei sehr vielen Personen (clientseitiges Sanity-Limit).
