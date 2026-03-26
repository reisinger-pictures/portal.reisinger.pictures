---
domain: technical
topic: database-schema
status: active
---

# Technical Concept: Database & Schema Management

## 1. Migration Strategy
- **UUIDs:** To prevent ID guessing and enumeration attacks, we use UUIDs (or ULIDs) as primary keys instead of Auto-Increment BIGINTs.
- **Migrations:** During active development, we use a single source of truth (`V001__initial_portal_schema.php`). Post-release, exactly *one* new migration file should be created per development iteration.

## 2. Timestamps & Soft Deletes
- Many tables only use `created_at` to save space (Models must define `public const UPDATED_AT = null;`).
- **No Soft Deletes:** We perform hard deletes to comply with privacy rules. Denormalization in audit logs covers historical tracking.
