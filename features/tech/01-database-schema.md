---
domain: technical
topic: database-schema
status: active
---

# Technical Concept: Database & Schema Management

## 1. Migration Strategy
- **UUIDs:** To prevent ID guessing and enumeration attacks, we use UUIDs (or ULIDs) as primary keys instead of Auto-Increment BIGINTs.
- **Migrations:** `V001__initial_portal_schema.php` is the historical starting point, not the current schema source of truth. **Production runs V035.** Everything above it — V036 Card-Testing defenses, V037 guest order ownership, V038 rating actor key, V039 contract signer identity, V040 payout natural keys — is the non-production repository frontier and may be consolidated where that is technically sound, provided the identity and payout invariants below are preserved. A new migration is only justified when an existing table, index or job contract cannot carry the change safely; the concrete schema, backfill and rollback decision must be documented before it is added. `down()` methods are never executed and may stay empty.
- **Rating actor key (V038):** `ratings.actor_key` is nullable and uses `user:<id>` or `guest:<id>` for identifiable owners; UUID identities are canonicalized to lowercase for MySQL/MariaDB case-insensitive collations. The portable unique index `ratings_photo_actor_key_unique (photo_id, actor_key)` avoids MySQL/MariaDB partial-index syntax while allowing multiple ownerless legacy rows with `NULL`; the historical `(photo_id, user_id, guest_id)` unique index remains in place. The migration normalizes legacy rows deterministically and **never deletes a row**: when two ratings share a `(photo_id, actor)` identity the migration reports the duplicates and aborts before any mutation, so an operator decides which rating survives. Ownerless rows are never touched.

### 1.1 V038 abort runbook (operator)

V038 aborts the deploy on purpose when duplicate `(photo_id, actor)` ratings exist, because silently deleting or merging customer ratings is not an acceptable default and `down()` is intentionally empty, so a wrong decision is unrecoverable.

The abort message is the work list. It reports `duplicate_groups` and `duplicate_rows` as **exact counts** while listing at most 20 groups with at most 20 ids each, so memory stays bounded on a large `ratings` table. The `NOTE:` lines state when the listing was truncated.

Procedure:

1. The deploy stays blocked. Do not re-run `migrate` in a loop and do not delete rows to make it pass.
2. Read the report from the exception or the `V038 rating actor-key preflight failed` log entry. Each line has the form `ratings identity=<photo_id> actor=<actor> count=<n> ids=<id,id,…>`.
3. Reconcile by business meaning, not by id order: for each group keep the rating that reflects the customer's actual latest intent, and record the decision in the audit trail before removing the superseded row. Ownerless rows (`user:<id>`/`guest:<id>` both absent) are not duplicates and are not reported.
4. If a group was truncated out of the listing, find the remainder with the same rule, e.g. `SELECT photo_id, actor_key, COUNT(*) FROM ratings WHERE actor_key IS NOT NULL GROUP BY photo_id, actor_key HAVING COUNT(*) > 1`.
5. Re-run the deploy. The preflight is idempotent and aborts again only if duplicates remain.

The preflight writes nothing before it has verified the whole table, so a blocked deploy leaves the schema exactly as it was.
- **Seed policy:** Every migration path (`migrate`, `migrate:fresh`, or the equivalent setup command) must be followed by the seed step (`--seed` or `php artisan db:seed`). The seed provisions the bootstrap admin and is part of the setup contract, not an optional cleanup.

## 2. Timestamps & Soft Deletes
- Many tables only use `created_at` to save space (Models must define `public const UPDATED_AT = null;`).
- **No Soft Deletes:** We perform hard deletes to comply with privacy rules. Denormalization in audit logs covers historical tracking.
