---
domain: photos
topic: ai-server-side
status: active
supersedes: photos/03-ai-batch-edit.md
---

# Technical Concept: Server-Side AI Metadata Generation (OpenAI-compatible)

## 1. Architecture Overview
The system replaces the pure client-side LM Studio approach with a dual-mode architecture:
- **Server mode (preferred):** PHP Backend (`AIService`) calls OpenAI-compatible APIs. Images are loaded server-side from disk — no Base64 transfer over the network.
- **Local fallback:** If the server-side endpoint is unavailable or misconfigured, the frontend falls back to direct browser-to-LM-Studio communication (preserving the original `03-ai-batch-edit` workflow).

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

`AIService` exposes three mutually-exclusive state helpers that the whole app reads from:

| Helper | Meaning | UI effect |
|--------|---------|-----------|
| `isDisabled()` | `AI_ENABLED` is falsy (`false`, empty) | AI buttons hidden, **no warning banner** |
| `isUnconfigured()` | `AI_ENABLED=true` but `AI_API_KEY` missing (except `type=lmstudio`) | AI buttons hidden, **admin warning banner shown** |
| `isAvailable()` | `AI_ENABLED=true` **and** (`type=lmstudio` **or** `AI_API_KEY` non-empty) | AI buttons enabled, no banner |

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
1. On mount, checks `/api/ai/status`
2. If server-side is available → mode `'server'` (preferred)
3. If not, falls back to LM Studio → mode `'local'`
4. If neither is available → mode `'unavailable'`

The `AIBatchEditModal` component uses this hook and displays a mode indicator badge.

## 6. Versioning (Audit Trail)
Every metadata update (regardless of role) creates a `PhotoMetadataVersion` snapshot of the previous state. This replaces the previous behavior where only client edits were versioned. See `features/photos/02-metadata-versioning.md`.

## 7. Related Documents
- `features/photos/03-ai-batch-edit.md` — deprecated local-only approach
- `features/photos/02-metadata-versioning.md` — versioning for all roles
- `features/ecommerce/01-licensing-and-cart.md` — metadata in licensing context
- `features/infrastructure/09-brand-context-queue-cli.md` — brand context patterns
