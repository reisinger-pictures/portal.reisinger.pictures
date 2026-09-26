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
- Successful provider responses must preserve the provider envelope's JSON object/list distinction: OpenAI/LM Studio `choices` and Anthropic `content` must be lists, not numeric-key objects. Invalid JSON, non-object envelopes, wrong-shaped list/object members, missing/wrong-shaped fields, and `keywords` values other than a string or list of strings return the same status-only `502` contract; valid keyword lists are normalized to a bounded comma-separated string.
- Vision image preparation must enforce the 20 MiB / 15,000 px per side / 40,000,000 pixel budgets before GD decode or resize. Oversized or undecodable images return the documented `422` error, clean their temporary file, and send no provider HTTP request; real-image regressions run in the digest-pinned GD image.
- Server prompt-contract regressions must use adversarial text containing directive-like language and delimiter-closing attempts. They inspect the actual provider request and prove that `text_input`, `global_context`, and `specific_context` remain in the documented untrusted data blocks, the trusted system message carries the ignore-directives rule, delimiter characters are boundary-encoded, and the existing metadata response is still accepted. These are construction tests, not evidence that a model follows the instruction.

### Frontend
- `useAI()` resolves mode (`server` / `local` / `unavailable`) from `/api/ai/status`
- Explicit `status=disabled` does **not** trigger an LM Studio probe
- `status=unconfigured` or a failed status request may select `local` when `/v1/models` returns a model
- Effective `enabled=true` selects `server`, including `AI_TYPE=lmstudio` with no API key
- `AIBatchEditModal` renders correctly in all vision modes
- `AIGalleryDefaultsModal` remains server-only and renders its unavailable state without a local fallback
- Local prompt-contract Vitest coverage must inspect the actual `/v1/chat/completions` request body, not merely a mocked hook return: both context fields stay inside `<untrusted_context>` blocks, adversarial delimiter text is encoded, the system message contains the ignore-directives rule, and the image/metadata request fields remain intact.

### E2E
- A documented external AI provider/widget or explicitly documented AI-proxy stub (never an internal `/api/*` route mock) appears correctly in the UI.
- AI disabled → no banner, no badge
- AI unconfigured → admin warning banner visible
- AI available → no banner, buttons enabled

### Evaluation limits

The PHPUnit and Vitest prompt-contract tests are deterministic request-shape
regressions. They do not establish that an external provider/model is immune to
prompt injection, including instructions rendered inside an image, multimodal
attacks, delimiter mimicry after provider transformation, or model-specific
behavior. A provider/model evaluation with an adversarial corpus and review of
real outputs is required before relying on the boundary for deployment-level
resistance; generated metadata remains untrusted output.
