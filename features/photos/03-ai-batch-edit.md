---
domain: photos
topic: ai-batch-edit
status: historical
superseded_by: photos/04-ai-server-side.md
---

# Historical Concept: Local AI Batch Edit (LM Studio)

> **Historical / superseded (2026-06-29):** This describes the former
> browser-only LM Studio workflow. It is not the current authorization, privacy,
> or fallback contract. The current dual-mode server/LM-Studio architecture,
> Boolean status semantics, and role gates are documented in
> [`04-ai-server-side.md`](04-ai-server-side.md) and
> [`../ai/01-ai-service-architecture.md`](../ai/01-ai-service-architecture.md).

## 1. Local AI Orchestration
- The system leverages a local LLM (e.g., via LM Studio) accessible at `http://127.0.0.1:1234/v1`.
- **Privacy First:** Images and context data never leave the photographer's local machine. No external rate limits or API costs apply.
- **Multimodal Pipeline:** The frontend scales down images (to max 2048px) and sends them as Base64 JPEG to the local Vision model alongside a global and specific context.

## 2. Security & Access Control
- **Photographer Only:** The feature is strictly restricted to users with the `is_photographer` role. Admins and clients are explicitly blocked from accessing the AI Batch Edit UI.
- **Delivery Galleries Only:** The tool is only available for `delivery` galleries. `selection` galleries bypass metadata extraction and editing entirely.

## 3. Fallback & UX
- **Meilisearch Integration:** If the AI detects a city from the image context (e.g., landmarks), the frontend queries the local Meilisearch index (`/api/search/locations`) to automatically resolve and populate the corresponding state, country, and ISO codes.
- **Row-level State:** The UI manages generation and saving states per image row to allow partial batch processing without locking the entire table.

## Related
- [IPTC Metadata Versioning](../photos/02-metadata-versioning.md) � AI-edited metadata follows the same versioning rules
- [Search & Discovery](../search/01-search-and-discovery.md) � AI-detected locations are resolved via the search index
