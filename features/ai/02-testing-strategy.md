# AI Testing Strategy (relocated to AGENTS.todo.md)

> **This file has been cleaned up per the features/ audit (2026-07-01).**
> The detailed test case tables have been moved to `AGENTS.todo.md` (section "AI Test Implementation Checklist").
> This file now contains only the verification criteria derived from the Soll-Zustand in `01-ai-service-architecture.md`.

## Verification Criteria (Soll-Zustand)

### Backend
- Three-state machine: `isDisabled()`, `isUnconfigured()`, `isAvailable()` per `AIProvider` config
- Provider Strategy Pattern: `AIProviderFactory::make()` returns correct provider per `AI_TYPE`
- `AIController::status()` returns `{enabled, status, type, model}`
- `generateMetadata()` returns 503 when AI is disabled or unconfigured
- `generateMetadataText()` returns 503 when AI is disabled or unconfigured

### Frontend
- `useAI()` hook resolves mode (`server` / `local` / `unavailable`) from `/api/ai/status`
- Disabled status does NOT trigger LM Studio fallback
- Unconfigured status triggers LM Studio fallback
- `AIBatchEditModal` renders correctly in all modes
- `AIGalleryDefaultsModal` renders correctly in all modes

### E2E
- Mocked AI responses appear correctly in the UI
- AI disabled → no banner, no badge
- AI unconfigured → admin warning banner visible
- AI available → no banner, buttons enabled
