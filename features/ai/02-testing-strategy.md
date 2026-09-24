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
- `generateMetadata()` returns 503 when AI is disabled or otherwise unavailable; the text endpoint has the same server-only availability rule.
- LM Studio is a browser-side vision fallback only; it is not a provider for the text-only endpoint.

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
