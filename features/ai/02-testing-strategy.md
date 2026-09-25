# AI Testing Strategy (relocated to AGENTS.todo.md)

> **This file has been cleaned up per the features/ audit (2026-07-01).**
> Detailed test-case tracking lives in `AGENTS.todo.md` under the current AI and
> E2E audit/verification sections; this file now contains only the verification
> criteria derived from the Soll-Zustand in `01-ai-service-architecture.md`.

## Verification Criteria (Soll-Zustand)

### Backend
- Three-state **effective status**: falsey `AI_ENABLED` is disabled; an enabled non-LM-Studio provider without a key is unconfigured; `AI_TYPE=lmstudio` is available without a key.
- `isUnconfigured()` is a raw enabled-plus-empty-key helper; after the disabled check, the status endpoint gives `isAvailable()` precedence, so an available LM Studio configuration is reported as `available` even when that helper is true.
- Provider Strategy Pattern: `AIProviderFactory::make()` returns correct provider per `AI_TYPE`
- `AIController::status()` returns `{enabled, status, type, model}`; `enabled` is effective availability, not the raw switch.
- Both generation endpoints authenticate and authorize before `isAvailable()`: vision uses a coarse capability pre-check followed by the target-level `updateMetadata` PhotoPolicy permission, while text uses the Gallery `create` permission. Only Photographers and Super-Admins pass the text permission; an ordinary Admin must receive `403` before availability or validation.
- Status precedence is part of the regression contract: unauthenticated `401`, unauthorized `403` regardless of provider state, authorized unavailable `503`, and authorized invalid input `422`. For an authorized actor, availability is checked before validation, matching the text flow. Provider/connection failure mappings remain unchanged (`502`/`503`).
- Vision authorization regression coverage must include disabled and unconfigured requests from a user without `updateMetadata` permission and assert that no provider request is sent. An actor with only `can_edit_metadata=true`, no target access, and an empty request must receive `403` before `isAvailable()`, validation, or provider work.
- Target-lookup regressions must prove that an actor without coarse metadata capability receives `403` without querying `photos`, and that a target-scoped client/invite actor receives the same opaque `403` for unknown and inaccessible photo IDs. LM Studio is a browser-side vision fallback only; it is not a provider for the text-only endpoint.

### Frontend
- `useAI()` resolves mode (`server` / `local` / `unavailable`) from `/api/ai/status`
- Explicit `status=disabled` does **not** trigger an LM Studio probe
- `status=unconfigured` or a failed status request may select `local` when `/v1/models` returns a model
- Effective `enabled=true` selects `server`, including `AI_TYPE=lmstudio` with no API key
- `AIBatchEditModal` renders correctly in all vision modes
- `AIGalleryDefaultsModal` remains server-only and renders its unavailable state without a local fallback

### E2E
- A documented external AI provider/widget or explicitly documented AI-proxy stub (never an internal `/api/*` route mock) appears correctly in the UI.
- AI disabled → no banner, no badge
- AI unconfigured → admin warning banner visible
- AI available → no banner, buttons enabled
