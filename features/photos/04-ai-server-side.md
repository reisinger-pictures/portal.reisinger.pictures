---
domain: photos
topic: ai-server-side
status: active
supersedes: photos/03-ai-batch-edit.md
reviewed: 2026-09-25
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

## 4. Authorization and target-lookup contract
The two generation endpoints intentionally use different existing policies. The canonical service contract is also maintained in [features/ai/01-ai-service-architecture.md](../ai/01-ai-service-architecture.md).

### `POST /api/ai/generate-metadata` (vision)
The requested photo must pass the `updateMetadata` **PhotoPolicy** gate:
- **Super-Admins/Admins/Photographers:** Allowed when `canManageGallery()` permits the target gallery.
- **Clients:** Allowed only when `can_edit_metadata = true`, the gallery has `allow_client_metadata_edit = true`, and the client can access that gallery.
- **Active invite identities:** Allowed only for an active, current-host metadata grant on the target gallery.
- **All others:** Denied (`403`). The `can_edit_metadata` column is a necessary client capability, never a stand-alone grant.

Target resolution and the coarse capability check are separate safeguards:

1. Authenticate first (`401` for a missing identity).
2. Check whether the actor is in any metadata-capable category. An actor without that coarse capability receives the same opaque `403` **without querying the submitted photo ID**.
3. For an actor who passes that check, resolve a submitted non-empty `photo_id`; target-level PhotoPolicy authorization cannot run before the `Photo` and its gallery are resolved.
4. For delegated client/invite capabilities, a missing, malformed, unknown, or inaccessible target all receive the same opaque `403`. This prevents a target-scoped actor from distinguishing an unknown ID from an inaccessible photo.
5. For role-capable actors, a missing or unknown ID remains on the normal validation path (`422` when AI is available). Only after target authorization is settled does the controller check AI availability (`503`) and then validate the request (`422`).
6. Call the provider only after those checks. Provider HTTP failures remain `502`; transport connection failures remain `503`.

The target lookup is used only to construct the policy subject; the endpoint never returns photo existence, metadata, gallery status, or authorization diagnostics in the `403` response.

### `POST /api/ai/generate-metadata-text` (text-only gallery defaults)
The text flow has no existing photo target. It is authorized by the Gallery `create` **GalleryPolicy** gate, exactly like gallery creation:
- **Photographers and Super-Admins:** Allowed.
- **Ordinary Admins:** Denied (`403`).
- **Clients, invite identities, and all others:** Denied (`403`).

This narrower role contract is intentional: the endpoint is used only to create defaults for a new gallery. Authorization runs before AI availability, validation, or provider work; the `401`/`403`/`503`/`422` and provider mappings above remain unchanged.

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
