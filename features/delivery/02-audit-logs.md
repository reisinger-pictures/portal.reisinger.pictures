---
domain: delivery
topic: audit-logs
status: active
---

# Technical Concept: Audit Logs & GDPR

## 1. The `download_logs` Table
- Every download (single image or full ZIP) creates an immutable record in the `download_logs` table.
- This data is heavily utilized for generating statistics (Top Galleries, Top Photos, Domain usage) in the management dashboard.

## 2. Denormalization for Resilience
- Because we use hard deletes for users and galleries, foreign keys in the log table are set to `ON DELETE SET NULL`.
- To preserve the audit trail and statistics even after an entity is deleted, the log stores denormalized string snapshots at the time of the event (`gallery_name_snapshot`, `user_name_snapshot`).

## 3. GDPR Compliance
- **No IP Addresses:** To strictly adhere to data minimization principles, we do not track or store the user's IP address in the audit logs.

## Related
- [Licensing & Downloads](../ecommerce/02-licensing-and-downloads.md) — download event logging and scope
- [Legal Evidence & Disputes](../ecommerce/06-legal-evidence-and-disputes.md) — audit logs as evidence package for chargebacks
- [Roles & Access Management (Single Org)](../auth/03-roles-and-rbac.md) — permission model governing who can view audit data
- [Search & Discovery](../search/01-search-and-discovery.md) — download statistics used for search result ranking
