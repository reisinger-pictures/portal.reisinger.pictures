---
domain: photos
topic: ai-server-side
status: active
supersedes: photos/03-ai-batch-edit.md
reviewed: 2026-09-24
---

# Technical Concept: Server-Side AI Metadata Generation (OpenAI-compatible)

## 1. Architecture Overview
The system replaces the pure client-side LM Studio approach with a dual-mode architecture:
- **Server mode (preferred):** PHP Backend (`AIService`) calls OpenAI-compatible APIs. Images are loaded server-side from disk — no Base64 transfer over the network.
- **Local fallback:** If the server-side status is unconfigured or the status
  request fails, the frontend may use direct browser-to-LM-Studio vision calls.
  An explicit disabled status never falls back.

## 2. Configuration

> **Deployment note:** Production does NOT use a `.env` file — configuration is injected via
> environment variables. All values below are read from the environment regardless of source.

| Variable | Default | Description |
|----------|---------|-------------|
| `AI_ENABLED` | `false` | Master switch for server-side AI (boolean `true`/`false` via Laravel DotEnv). |
| `AI_TYPE` | `openai` | Provider type: `openai`, `anthropic`, or `lmstudio`. |
| `AI_BASE_URL` | `https://api.openai.com/v1` | API base URL for the selected provider. |
| `AI_API_KEY` | — | API key for the AI endpoint. |
| `AI_MODEL` | `gpt-4o` | Model identifier. |

### State model (centralised in `App\Services\AIService`)

`AIService` exposes three effective status states. `AI_ENABLED` is falsey when
disabled; `AI_TYPE=lmstudio` is the one provider that does not need
`AI_API_KEY`:

| Helper | Meaning | UI/status effect |
|--------|---------|-------------------|
| `isDisabled()` | resolved `AI_ENABLED` config is falsey | AI buttons hidden, **no warning banner** |
| `isUnconfigured()` | raw helper for an enabled service with an empty `AI_API_KEY`; it can also be true for LM Studio | After the disabled check, the status endpoint gives `isAvailable()` precedence, so an available LM Studio configuration is reported as `available`, not `unconfigured` |
| `isAvailable()` | enabled and (`type=lmstudio` **or** `AI_API_KEY` non-empty) | AI buttons enabled, no banner |

The three status values are the effective contract. The raw
`isUnconfigured()` helper is not itself a mutually exclusive fourth state.

### `AI_ENABLED=false` (default, disabled)

When `AI_ENABLED=false`:
- The `/api/ai/status` endpoint returns `enabled: false`, `status: 'disabled'`
- Buttons blend out entirely, no admin warning banner.
- The feature is hidden, as if it did not exist — for environments where AI is
  intentionally not part of the deployment.

### `AI_ENABLED=true` without key (unconfigured)

When `AI_ENABLED=true` but `AI_API_KEY` is empty (and type is not `lmstudio`):
- The `/api/ai/status` endpoint returns `enabled: false`, `status: 'unconfigured'`
- The AI Batch-Edit button is hidden in the gallery view
- An admin dashboard warning is displayed (similar to the impressum-missing alert)

## 3. API Endpoints
All endpoints are under `auth:api` middleware.

### `GET /api/ai/status`
Returns the availability status, provider type, and active model.
```json
{ "enabled": true, "status": "available", "type": "openai", "model": "gpt-4o" }
```

### `POST /api/ai/generate-metadata`
Generates metadata from a photo. Image is loaded server-side from the `photos` disk.
**Request:**
```json
{
  "photo_id": "uuid",
  "global_context": "optional global context",
  "specific_context": "optional per-image context"
}
```
**Response:**
```json
{
  "title": "SEO-optimized title",
  "description": "Journalistic description",
  "keywords": "keyword1, keyword2, ...",
  "location": "Detected location",
  "detected_city": "Detected city name"
}
```

### `POST /api/ai/generate-metadata-text`
Generates metadata from text input only (no image access needed).
**Request:**
```json
{
  "text_input": "Description of the image",
  "global_context": "optional context"
}
```

## 4. Authorization
Access is gated via the `updateMetadata` PhotoPolicy Gate:
- **Photographers/Admins/Super-Admins:** Always allowed (with gallery access)
- **Clients:** Allowed only if `can_edit_metadata = true` AND gallery has `allow_client_metadata_edit = true`
- **All others:** Denied (403)

## 5. Frontend (`useAI.ts`)
The `useAI` hook (replaces the old `useLMStudio`) implements the dual-mode strategy:
1. On mount, checks `/api/ai/status`.
2. If `status === 'disabled'`, it selects `unavailable` and does **not** probe
   LM Studio; an explicit disable is authoritative.
3. If the endpoint reports effective `enabled === true` (normally
   `status === 'available'`), it selects `server`.
4. If the status is `unconfigured` or the status request fails, it probes
   `/v1/models` at the configured localhost LM Studio URL and selects `local`
   when a model is found.
5. If neither path yields a model, it selects `unavailable`.

The local branch is vision-only. `AIGalleryDefaultsModal` always calls the
server text endpoint and has no LM Studio fallback. The `AIBatchEditModal`
component uses this hook and displays a mode indicator badge.

## 6. Versioning (Audit Trail)
Every metadata update (regardless of role) creates a `PhotoMetadataVersion` snapshot of the previous state. This replaces the previous behavior where only client edits were versioned. See `features/photos/02-metadata-versioning.md`.

## 7. Related Documents
- `features/photos/03-ai-batch-edit.md` — deprecated local-only approach
- `features/photos/02-metadata-versioning.md` — versioning for all roles
- [Licensing and downloads](../ecommerce/02-licensing-and-downloads.md) — metadata in licensing context
- `features/infrastructure/09-brand-context-queue-cli.md` — brand context patterns
