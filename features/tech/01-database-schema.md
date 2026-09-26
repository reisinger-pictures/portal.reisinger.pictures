---
domain: technical
topic: database-schema
status: active
---

# Technical Concept: Database & Schema Management

## 1. Migration Strategy
- **UUIDs:** To prevent ID guessing and enumeration attacks, we use UUIDs (or ULIDs) as primary keys instead of Auto-Increment BIGINTs.
- **Migrations:** `V001__initial_portal_schema.php` is the historical starting point, not the current schema source of truth. `V038__add_rating_actor_key.php` is the current repository frontier; `V037__add_guest_order_ownership.php` is the preceding guest-ownership migration, `V036__card_testing_defenses.php` remains the Card-Testing migration, and `V035` is the last migration recorded as deployed. Every new schema change must be a separate `V039+` migration. Migrations through V038 are immutable and must not be amended or replaced.
- **Rating actor key (V038):** `ratings.actor_key` is nullable and uses `user:<id>` or `guest:<id>` for identifiable owners; UUID identities are canonicalized to lowercase for MySQL/MariaDB case-insensitive collations. The portable unique index `ratings_photo_actor_key_unique (photo_id, actor_key)` avoids MySQL/MariaDB partial-index syntax while allowing multiple ownerless legacy rows with `NULL`; the historical `(photo_id, user_id, guest_id)` unique index remains in place. Migration normalization deterministically retains the lowest rating UUID for duplicate identifiable legacy rows and never deletes ownerless rows.
- **Seed policy:** Every migration path (`migrate`, `migrate:fresh`, or the equivalent setup command) must be followed by the seed step (`--seed` or `php artisan db:seed`). The seed provisions the bootstrap admin and is part of the setup contract, not an optional cleanup.

## 2. Timestamps & Soft Deletes
- Many tables only use `created_at` to save space (Models must define `public const UPDATED_AT = null;`).
- **No Soft Deletes:** We perform hard deletes to comply with privacy rules. Denormalization in audit logs covers historical tracking.
