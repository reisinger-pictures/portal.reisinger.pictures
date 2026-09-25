---
domain: gallery
topic: core-architecture
status: active
---

# Technical Concept: Gallery Architecture & Types

## 1. Structural Entities
- **GalleryGroup (Meta-Gallery):** Used for hierarchical organization. Groups can be nested infinitely (`parent_id`).
- **Gallery:** The actual container for photos. Must belong to a `GalleryGroup` or sit at the root level. 

## 2. Gallery Types (Strict Separation)
Galleries are strictly divided into two mutually exclusive workflows:

### A. Selection (Rating Workflow)
- **Purpose:** Client selects favorites for final editing.
- **Visibility:** MUST ALWAYS be private. Cannot be made public.
- **Available Features:** 5-star ratings, comments, PhotoSwipe full-screen rating UI.
- **DISABLED Features:** NO metadata editing. NO high-res downloads.
- **Security (DAU Protection):** Frontend implements basic protections (prevent right-click, `draggable={false}`) to deter downloading of unedited preview images.

### B. Delivery (Download Workflow)
- **Purpose:** Final delivery of high-res images.
- **Visibility:** Can be private (assigned users/Magic Link/Password) or public. Email domain mapping groups apply here.
- **Available Features:** Single downloads, ZIP downloads, IPTC metadata editing (if permitted). Live Mode (10s auto-refresh).
- **DISABLED Features:** NO ratings. NO client comments.

## 3. Caching
- The entire gallery tree structure is cached infinitely (`gallery_tree_admin`).
- Flushed via Eloquent Model Events (`booted` -> `saved`/`deleted`).


## 4. Gallery Routing & Breadcrumbs
- **Deep-Link Support:** Public galleries are directly addressable via their slug (e.g. `/:slug`). Breadcrumbs are rendered server-side for navigation through nested `GalleryGroup` parents.
- **Namespace Safety:** The breadcrumb resolver MUST use the fully qualified model class `\App\Models\GalleryGroup::find()`. An unqualified `AppModelsGalleryGroup::find` call caused a fatal `HTTP 500` on deeply nested galleries during a breadcrumb render.
- **Prevention:** Any route rendering breadcrumbs for nested galleries MUST have a PHPUnit test asserting HTTP 200 on a deep gallery URL.

## 5. Role & View Preview (Tab-Switcher)
- **Preview Capability:** Ein Tab-Switcher (implementiert über den URL-Parameter `?view=client`) erlaubt den fließenden Wechsel zwischen der Verwaltungsansicht (`ManagementGalleryView`) und der Kundenansicht (`ClientGalleryView`).
- **Strict Access Control:** Dieser Switcher wird **ausschließlich** angezeigt, wenn der eingeloggte Nutzer für diese spezifische Galerie sowohl Verwaltungsrechte (Fotograf/Admin) als auch Kundenrechte besitzt. Hat ein Fotograf über einen Gast-Link Zugriff auf eine fremde Galerie, bleibt er strikt in der Kundenansicht gefangen.

## 6. Hierarchy Traversal Budgets (`GalleryGroup.parent_id`)

- **Structural fact:** `gallery_groups.parent_id` is a self-referencing column **without** a database-level cycle constraint. Legacy imports, direct SQL and manual fixes can therefore persist cyclic (`A→B→A`) or arbitrarily deep/wide hierarchies.
- **Every descendant traversal MUST be cycle-safe** (visited-id set or a `UNION`-distinct recursive CTE). `UNION ALL` in a recursive CTE and self-referential `with(['children'])` eager loads do **not** terminate on such data — they exhaust memory.
- **Every descendant traversal MUST be explicitly bounded and chunked.** Canonical implementation: `App\Support\GalleryGroupSubtree` with `MAX_DEPTH = 10`, `MAX_LEVELS = 11`, `MAX_NODES = 5000`, `PARENT_ID_CHUNK = 500` (one statement per level, parent ids sent in chunks). `GalleryGroup::children()` loads direct children only; nested eager loads on that relation are forbidden — callers use `loadSubtree()` / `loadForest()` / `descendantIds()`.
- **Node budget is hard and counts the supplied roots — before any row is hydrated.** Root queries are capped at `MAX_NODES + 1` rows. Exceeding the node budget raises `GalleryGroupBudgetExceededException`; a partial hierarchy is never returned (a silently shortened tree would under-propagate brands and under-report authorizations). Seeds for authorization walks are verified with `GalleryGroupSubtree::existingIds()` (chunked, bound parameters) so an unknown id can never enter an authorization answer.
- **Depth budget is truncation, not error:** the levels below `MAX_DEPTH` are simply not exposed (empty result there) and the cut-off is audited via the `gallery_groups.traversal_depth_truncated` warning. Truncation can only narrow a result.
- **Fail-closed callers** (all logged, never a 500, never a cached denial):
  - `GalleryTreeService::getAdminTree` → returns `['groups' => [], 'root_galleries' => []]` and caches **nothing**, so the next request re-evaluates the budget.
  - `GalleryTreeService::getAllSubgroupIds` → `[]` (the group only, no descendants).
  - `AuthorizationService::getSubGroupIds` → the verified, directly granted seeds only; it never widens an authorization it cannot prove.
  - `GalleryController::showGroup` → the group renders without nested structure, therefore without descendant galleries.
- **Query budget:** a subtree load costs at most one `gallery_groups` statement per level (plus one per chunk of `PARENT_ID_CHUNK` parent ids). The `saving` cycle guard answers with exactly **one** statement regardless of chain length.
- **Authorization is unchanged by the budgets.** Traversal is structural only; the brand chain checks (`BrandRegistry::galleryGroupTreeMatchesBrand`) and the permission/type/org filters still run on every node that survives the budget. A budget cut-off can only *hide* nodes, never expose foreign ones.

## 7. Status Column Guard (transition-only validation)

- `orders.status`, `projects.status` / `payment_status` and `photo_jobs.status` are validated **as a transition**: `ModelStatusGuard::assertTransitionAllowed()` only rejects an attribute that is actually written.
- Consequence: a row persisted with a **legacy** status value stays writable through unrelated saves (payment-failure bookkeeping, invoice archiving, board notes) instead of failing every write with `InvalidArgumentException` (HTTP 500).
- Writing any value outside the allow-list — including `null` — is still rejected; the guard never creates a new invalid state. Allow-lists: `Order::ALLOWED_STATUSES`, `Project::allowedStatuses()` / `allowedPaymentStatuses()`, `PhotoJob::allowedStatuses()` (enum-derived).

## 8. Brand Invariant: Gallery ⇄ Group (P1-M15, entschieden 2026-09-25)

**Invariant:** A gallery and its group always carry the **same** brand. The group is authoritative for the brand of a gallery that is attached to it. A gallery without a group keeps the request host brand.

### 8.1 Warum überhaupt
`BrandRegistry::galleryTreeMatchesBrand()` bewertet die **komplette** Kette (`galleries.brand` **und** jeder `gallery_groups.brand` bis zur Wurzel). Ein Paar mit unterschiedlichen Brands fällt damit in *jeder* brand-gescopten Liste weg (Management-Baum, `AuthorizationService::getAllowedGalleryIds()`, `showGroup`), taucht aber weiterhin im cross-brand Super-Admin-Baum (`gallery_tree_admin`) und in ungefilterten Queries auf. Das ist kein „nur unsichtbar", das ist ein Inkohärenz-Spike quer durch alle Brand-Grenzen.

### 8.2 Durchsetzung — `GalleryService` ist die autoritative Grenze
`GalleryService::storeGallery()` / `GalleryService::updateGallery()` (Re-Parenting) lösen die Brand **vor** dem Schreiben auf (`resolveAuthoritativeGroupBrand()`). Beide nehmen den Actor als **verpflichtendes** Argument entgegen (`storeGallery(array $data, ?User $user)`, `updateGallery(Gallery $gallery, array $data, User $user)`) — bei `updateGallery()` ist der Actor **nicht nullable und ohne Default**: ein vergessener Actor war vorher still ein Fail-open, weil `?User $user = null` jede Identity- und Brand-Prüfung übersprang (ein `srp`-Re-Parenting in eine `rp`-Gruppe adoptierte dann `rp`, statt abgelehnt zu werden). Der einzige actorlose Pfad ist `storeGallery()` ohne Actor (System-/Root-Galerie); er ist als eigener, bewusst schmaler Helper (`resolveGroupBrandForActorlessCreate()`) benannt und prüft nur die gruppeninternen Invarianten, statt die Identity-Gate implizit zu überspringen. Ein client-geliefertes `brand`-Feld wird **nirgends** gelesen: es ist nicht Teil des validierten Payloads (`StoreGalleryRequest`, `UpdateGalleryRequest`), `storeGallery()` schreibt die Brand aus einer expliziten Whitelist, und `updateGallery()` **entfernt** einen etwaigen `brand`-Key aus `$data`, bevor `$gallery->update($data)` läuft (sonst würde der Mass-Assignment-Pfad der `Gallery::$fillable` ihn sonst doch durchwinken). Die `FormRequest`-Regel `brandScopedExists('gallery_groups')` bleibt als schnelle, brand-gescopte Vorprüfung bestehen; der Service ist die zweite, Request-unabhängige Schicht (andere Caller, Re-Branding zwischen Validierung und Persistenz).

Abgelehnt mit **422** auf `gallery_group_id` (nie stillschweigend persistiert):
- unbekannte/nicht existierende Gruppe (kein `gallery_group_id` mit dangling FK mehr),
- Gruppe ohne Brand,
- Gruppe einer **fremden Brand für einen brand-gebundenen Actor** (Fotograf/Admin/Client) — dieser Pfad darf die Servicelehre gar nicht erst erreichen,
- transienter Gast und persistierter Null-Brand-Nicht-Super-Admin (`isTransientGuest()`, `isReservedNullBrandActor()`) — fail closed,
- Gruppe, deren **Parent-Kette nicht kohärent** in derselben Brand liegt (`BrandRegistry::galleryGroupTreeMatchesBrand()`). Solche Legacy-Bäume erzeugen sonst eine Galerie, die in keinem brand-gescopten Listing sichtbar wäre.

### 8.3 Gewählte Variante für den cross-brand Super-Admin: **Adopt** (nicht Reject)
Ein vertrauenswürdiger cross-brand Super-Admin (`brand === null`, persisted, Rolle `super_admin` — `AuthorizationService::isTrustedCrossBrandActor()`) behält seine dokumentierte All-Brand-Reichweite: **kein 422, HTTP 200 bleibt**, die UX ändert sich nicht. Die neue Galerie **übernimmt die Brand der Gruppe**.

Begründung:
- **UX erhalten:** Ein Reject würde den Super-Admin zwingen, auf den Host der Fremd-Brand zu wechseln, um dort eine Galerie anzulegen. Das bricht genau den bestehenden, dokumentierten Cross-Brand-Use-Case.
- **Isolation wird stärker, nicht schwächer:** Vorher landete die Galerie in der **Host**-Brand und war damit im Brand-Baum der Ziel-Brand unsichtbar (`galleryTreeMatchesBrand` = false) — ein Foreign-Brand-Objekt unter einer RP-Hülle. Nachher ist sie ein reguläres SRP-Objekt und erscheint nur dort. Die Tenant-Isolation wird also verschärft; es wird nichts *geöffnet*.
- **Entscheidend:** Die Autorisierung wird in der Ziel-Brand **neu geprüft**, nicht umgangen. Ein brand-gebundener Actor, der später in der Ziel-Brand auftaucht, sieht die Galerie regulär über seine eigene Brand; ein brand-gebundener Actor in der *fremden* Brand sieht sie nie, weil `galleries.brand` dort nicht existiert. Für den Super-Admin selbst ist `isTrustedCrossBrandActor()` der dokumentierte Weg, alle Brands zu sehen.

### 8.4 Folgekonsistenz
- **Brand-scoped Listing / Cache-Keys:** Das Ergebnis trägt die Gruppen-Brand, also greifen die bestehenden Mechanismen automatisch: `GalleryTreeService::getAdminTree()` cached pro Brand unter `gallery_tree_admin_<brand>` plus global `gallery_tree_admin`; `clearCache()` löscht die aus `config('brands')` bekannten Marken **und** die aus `galleries`/`gallery_groups` distinct gelesenen DB-Marken (Legacy-Brand-Ids wie `srp` sind dadurch ebenfalls abgedeckt).
- **Media-/Licensing-Queries — was der Guard garantiert und was nicht:** `assertPresetForBrand()` prüft eine **explizit gesetzte** `galleries.volume_preset_id` gegen die resultierende Galerie-Brand, nicht mehr gegen die Request-Host-Brand. Ein `srp`-Preset in einer `srp`-Gruppe ist damit auch dann erlaubt, wenn der Super-Admin vom `rp`-Host aus arbeitet — vorher wäre er fälschlich abgelehnt worden. `Gallery::getEffectiveLicensingModeAttribute()` liest `settings.pricing_strategy` ebenfalls über `$this->brand` (Host-Fallback nur für Legacy-Null-Brand-Rows).
  - **Nicht garantiert — der Fallback-Default bleibt host-gebunden:** Ist `licensing_mode = volume_licensing` **ohne** expliziten `volume_preset_id`, greift weiterhin der host-gebundene Default-Pfad (`CheckoutService::strategyForGroup()` mit `presetKey === 'default'` → `VolumePresetService::resolveDefaultForBrand(BrandRegistry::currentOrDefault())`, ebenso die `PricingStrategy`-Binding in `AppServiceProvider`). Da Host-Brand und Galerie-Brand nach §8.3 divergieren **dürfen** (Super-Admin legt eine `srp`-Galerie vom `rp`-Host aus an), kann die wirksame Preset-Auflösung damit die Brand der Galerie verfehlen. Für §8 selbst irrelevant (der Guard hat nichts mehr zu prüfen, wenn ohnehin kein Preset zugewiesen ist), aber eine echte Folgekonsistenz-Lücke.
  - **Follow-up:** Den Default-Preset-Pfad von `BrandRegistry::currentOrDefault()` auf die **Galerie-Brand** umstellen (analog zu `getEffectiveLicensingModeAttribute()`), damit die vollständige Licensing-Kette `gallery → mode → preset` durchgängig galerie-gebunden ist. Bewusst **nicht** Teil von P1-M15: es ist eine eigene Entscheidung mit Checkout-/Preis-Reichweite.
- **Re-Parenting:** `updateGallery()` erzwingt dieselbe Invariante. Weil die Brand wechseln kann, wird ein **bestehender** Preset beim markanten Brand-Wechsel mitgeprüft: ein RP-Preset auf einer nach `srp` verschobenen Galerie wird mit 422 abgelehnt, statt still als Fremd-Referenz zu stranden.

### 8.5 Bewusst *nicht* Teil dieser Entscheidung
Der **Gruppe ⇄ Gruppe**-Fall (`GroupRequest::parent_id` ohne Brand-Pflicht für cross-brand Super-Admins) ist eine eigene Entscheidung und bleibt offen. Bis dahin ist er durch §8.2 fail-closed **eindämmt**: eine Galerie in einem gemischten Brand-Baum wird abgelehnt, statt inkohärent persistiert zu werden.

## 9. Management UI Pattern
- **Explorer-Ansicht:** Die Struktur- und Galerieverwaltung befindet sich nicht in der Sidebar, sondern in einer eigenen, großzügigen Hauptansicht (`/galleries`).
- **Modulare Dialoge:** Um den Haupt-Erstellungsdialog für Galerien schlank zu halten, wurden die komplexen IPTC-Standard-Metadaten und Berechtigungen in einen separaten Dialog (`GalleryMetadataDefaultsModal`) ausgelagert. 
### Tree Editing Rules (learned)
- **Edit-Button Disambiguation:** The `TreeNode` component MUST split edit handlers into `onEditGroup` and `onEditGallery` instead of a single `onEdit` prop. A shared handler caused the parent Group's edit dialog to open when clicking "Edit" on a nested Gallery.
- **Inline Creation:** Inline `+` buttons (Folder/Gallery) on each `TreeNode` summary reduce UX friction. They MUST pass a `defaultGroupId` to the creation modal, so the folder dropdown is pre-filled with the correct parent group.
- **Dashboard:** Das Root-Dashboard (`/`) dient ausschließlich als "Activity-Hub" (FTP-Inbox Status, die 3 neuesten Galerien und 20 neuesten Bilder).

- [Search & Discovery](../search/01-search-and-discovery.md) � gallery discoverability and permission-filtered search
- [Roles & Access Management](../auth/01-roles-and-access.md) � role-based gallery visibility and permissions
- [Magic Links & Invites](../auth/02-magic-links.md) � transient gallery access through invite links
- [Downloads & Leak Tracing](../delivery/01-downloads-and-injection.md) � delivery gallery download behavior
- [Image Upload & Processing](../photos/01-upload-and-processing.md) � photo lifecycle tied to gallery types
- [IPTC Metadata Versioning](../photos/02-metadata-versioning.md) � metadata editing rules differ by gallery type
- [Search & Discovery](../search/01-search-and-discovery.md) � gallery discoverability and permission-filtered search
