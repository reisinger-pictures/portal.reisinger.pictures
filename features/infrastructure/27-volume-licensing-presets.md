# Volume-Licensing-Presets — Konfigurierbare Preisstaffeln (Soll-Zustand)

> **Status:** Current (2026-08-13). Ersetzt die Flach-Settings `srp_price_per_image_tier1/2/3` +
> `srp_tier_threshold1/2` als Konfigurationsweg für das Volume-Licensing-Preismodell.
> Verknüpft: `features/infrastructure/17-pricing-strategy-pattern.md`,
> `features/infrastructure/16-srp-volume-pricing.md` (historical).
> Migration: `V029__volume_licensing_presets`.

## 1. Kontext

Die Volume-Licensing-Preisstruktur (Mengenrabatt-Staffeln) war bisher nur als flache
`settings`-Keys je Brand konfigurierbar (`srp_price_per_image_tier*`, `srp_tier_threshold*`),
und **ohne** Admin-UI. Das Frontend zeigte zudem hartcodierte 30/25/20 €-Staffeln
(`DEFAULT_VOLUME_PRICING`), die von der server-autoritativen Berechnung abweichen konnten.

**SOLL:** Pro Brand konfigurierbare **Presets** mit **beliebiger Staffelanzahl**; Galerien können
einem Preset zugeordnet werden (Default = Brand-Standard); Lizenzmodus pro Galerie bedienbar;
Frontend-Anzeige liest die effektiven Staffeln aus der API.

**Entscheidungen (2026-08-13):**
1. **Retroaktive Semantik** beibehalten: Die Gesamtmenge der Nicht-Quote-Items bestimmt einen
   Einheitspreis für ALLE Bilder (keine progressiven Staffelblöcke).
2. **Per-Gallery-Zuordnung**: `galleries.volume_preset_id` (null = Brand-Default). Die bestehende
   Spalte `galleries.licensing_mode` (scope/volume, F2) wird im Galerie-Dialog bedienbar.
3. **Migration der Alt-Settings**: Beim ersten Zugriff erzeugt
   `VolumePresetService::ensureDefaultPresetForBrand()` ein „Standard"-Preset je Brand und übernimmt
   vorhandene `srp_*`-Werte (falls gesetzt), sonst Defaults (0/10/20 → 3000/2500/2000).

## 2. Datenmodell (Migration V029)

- `volume_presets`: `id, brand, name, is_default, timestamps` — genau **ein** Default je Brand
  (Invariante im Service erzwungen; MySQL kann keine partiellen Indizes).
- `volume_preset_tiers`: `id, volume_preset_id (FK cascade), position, min_quantity, price_cents`
  — `min_quantity` = ab wie vielen Bildern die Staffel gilt, `price_cents` = Einheitspreis in Cent.
  Unique `(volume_preset_id, position)`. Die erste Staffel beginnt bei `min_quantity = 0`.
- `galleries.volume_preset_id` nullable FK (`nullOnDelete`).

## 3. Backend

- **`VolumePresetService`** (`App\Services\VolumePresetService`):
  - `ensureDefaultPresetForBrand(Brand|string)` — idempotent, migriert `srp_*`-Keys.
  - `resolveForGallery(?Gallery)` — Gallery-Preset, sonst Brand-Default.
  - `create/update/delete/setDefault` — ein Default je Brand; Default kann nicht gelöscht werden;
    Löschen setzt referenzierende Galerien auf `null`; erstes Preset einer Brand wird automatisch Default.
- **`VolumeLicensingStrategy`**: Konstruktor `(VolumePreset, ?CouponService)`. Alle Nicht-Quote-Items
  werden zum **Basis-Tier** (erstes Tier) bepreist; die Retroaktiv-Diffs werden als
  `tier_breakdown`-Zeilen (eine pro Stufe unterhalb des qualifizierenden Tiers) subtrahiert.
- **`CheckoutService`**: Gruppiert nach `(licensing_mode, preset)` (`mode|presetKey`); Volume-Gruppen
  laufen IMMER über `calculateMultiStrategyCart` (Gallery-Preset schlägt Brand-Default auch bei
  Single-Mode-Cart).
- **API** (`super_admin` für Schreiben, `management` für GET — Fotografen brauchen Lese-Zugriff):
  - `GET/POST /api/management/settings/volume-presets`
  - `PUT/DELETE /api/management/settings/volume-presets/{id}`
  - `POST /api/management/settings/volume-presets/{id}/default`
  - `GET /api/settings/license-terms` liefert `volume_pricing: {preset_id, preset_name, tiers[]}`
    (Brand-Default, bzw. Gallery-Preset bei `?gallery_id=`).
- **Gallery**: `GalleryRequest`/`StoreGalleryRequest` validieren `volume_preset_id`
  (`nullable|numeric|exists:volume_presets,id`); `GalleryService::assertPresetForBrand()` lehnt
  fremd-Brand-Presets mit 422 ab. `galleries.volume_preset_id` ist in `$visible`/`$fillable`.

## 4. Frontend

- **Settings**: `PricingSettingsTabs` (daisyUI `tabs tabs-boxed`) gruppiert „Lizenz-Katalog" und
  „Volume-Pricing" als zwei Tabs. `VolumePresetSettingsCard` bietet Preset-CRUD mit Staffel-Editor
  (add/remove, `min_quantity` + Preis in €), „Als Standard" und Lösch-Confirm.
- **Gallery-Dialog** (`GalleryModal`): „Lizenzierungsmodus" (Brand-Standard/Scope/Volume) + bei
  Volume ein „Volume-Preset"-Dropdown (Brand-Standard oder konkretes Preset).
- **Warenkorb-Anzeige**: `useVolumeLicensing` liest die effektiven Staffeln aus
  `license-terms.volume_pricing` (Fallback `DEFAULT_VOLUME_PRICING` beim Laden). `VolumeTierResult`
  nutzt `tierIndex`/`isMaxTier` statt hartcodierter 3 Staffeln; `VolumeLicensingCard`/
  `CartItemList` rendern die Staffeln dynamisch.

## 5. Grenzen / bekannte Punkte

- Die alten `srp_*`-Settings-Keys werden bei der Migration gelesen, aber **nicht gelöscht**
  (harmloser Alt-Ballast; Cleanup folgt, wenn SRP-Referenzen getilgt sind — siehe `16-srp-volume-pricing.md`).
- Ein Preset ohne Staffeln ist nicht erlaubt (Validierung `tiers` min:1).
- Preise müssen mit steigender Menge nicht zwingend fallen (keine Preis-Ordnungsvalidierung) —
  die retroaktive Logik rechnet strikt nach `min_quantity`.
