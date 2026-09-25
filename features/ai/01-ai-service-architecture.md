# AI Service Architecture (Soll-Zustand)

**Status:** Accepted (Option A – Vision retained)

## 1. Overview

The AI subsystem generates photo metadata (title, description, keywords, location) via LLM-based services. It supports two generation methods and three operational states.

## 2. Three-State Machine

The system has exactly three effective states. `AI_ENABLED` is read as a
truthy/falsey environment value (normally the booleans `true`/`false`):

```
AI_ENABLED=falsey                         → disabled       → hidden, no admin banner
AI_ENABLED=true + AI_TYPE=lmstudio        → available      → key is not required
AI_ENABLED=true + non-lmstudio + no key   → unconfigured   → admin warning banner
AI_ENABLED=true + non-lmstudio + key      → available      → normal operation
```

- `AIService::isDisabled()` uses the resolved config value, not a literal
  `DISABLED` string.
- `AI_TYPE` selects `openai` (default), `anthropic`, or `lmstudio`.
- `AI_TYPE=lmstudio` is available without `AI_API_KEY`; this is the only
  provider-specific exception to the key requirement.
- `isUnconfigured()` is currently a raw helper: it reports an enabled service
  with an empty key, including an `lmstudio` configuration. After the disabled
  check, the status endpoint gives `isAvailable()` precedence over the
  unconfigured label, so LM Studio is still reported as `status=available` even
  when that helper is true. This helper nuance does not create a fourth
  effective state.
- The `/api/ai/status` `enabled` field reports effective availability
  (`isAvailable()`), while `status` disambiguates `disabled`, `unconfigured`,
  and `available`.

## 3. Generation Methods – Zwei Flows, eine Engine

Das System hat genau zwei Generation-Flows, die sich durch die **Anwesenheit eines Bildes** unterscheiden:

| Merkmal | Vision-Flow | Text-Flow |
|---|---|---|
| Komponente | `AIBatchEditModal` | `AIGalleryDefaultsModal` |
| Hook-Methode | `generateMetadata(photoId, context...)` | `generateMetadataFromText(text, context?)` |
| Bild nötig? | **Ja** (Kern der Analyse) | **Nein** |
| Text-Kontext | **Immer optional** – global (alle Bilder) + spezifisch (pro Bild) | **Primäre Quelle** (required) |
| Lokaler Modus | Ja (LM Studio) | Nein (nur Server) |
| Verwendungszweck | Metadaten zu vorhandenen Fotos generieren | Default-Vorgaben für eine neue Galerie ohne Bilder |

### 3.1 Vision-Flow (primär – `AIBatchEditModal`)

```
AIBatchEditModal
  ├── global_context (optional)       – "Beschreibung des Events für alle Bilder"
  ├── pro Bild: specific_context      – "optionaler Hinweis zu diesem Bild"
  └── → useAI.generateMetadata(photoId, globalContext, specificContext)
        → Server mode: POST /api/ai/generate-metadata
             { photo_id, global_context?, specific_context? }
          → AIController::generateMetadata()
            → AIService::generateMetadata(Photo $photo)
              → loadAndCompressImage()    // resize 2048px, JPEG 80%, base64
              → POST {base_url}/v1/chat/completions mit image_url + text prompts
        → Local mode: fetch LM Studio /v1/chat/completions mit base64 image
```

**Text-Kontext im Vision-Flow:**
- `global_context`: Wenn gesetzt, wird er als "Globaler Kontext" in den Prompt eingebaut. Kann die Beschreibung des Events, Kundenwünsche etc. enthalten.
- `specific_context`: Pro Bild, als "Spezifischer Bild-Kontext". Z.B. "Bürgermeister bei der Eröffnungsrede".
- **Beide sind optional.** Leere/fehlende Werte werden im Prompt durch `"Keiner"` ersetzt.
- Die primäre Quelle der Metadaten ist **das Bild selbst** – der Text ist nur ein zusätzlicher Hinweis.

**E2E-Relevanz:** Der Batch-Edit-Test muss auch den Fall "kein Text-Kontext" abdecken (nur Bild → KI liefert trotzdem Titel, Beschreibung etc.).

### 3.2 Text-Flow (einziger non-Vision-Fall – `AIGalleryDefaultsModal`)

```
AIGalleryDefaultsModal → useAI.generateMetadataFromText(text_input, global_context?)
  → POST /api/ai/generate-metadata-text { text_input, global_context }
    → AIController::generateMetadataText()
      → AIService::generateMetadataFromText()
        → POST {base_url}/v1/chat/completions (text only, kein Bild)
```

- **Einziger Fall ohne Bild:** Wenn ein Photographer eine neue Galerie anlegt und Default-Vorgaben (Titel-Muster, Beschreibungsvorlage, Standard-Keywords) per KI generieren möchte. Zu diesem Zeitpunkt existieren noch keine Fotos in der Galerie.
- **Text ist required** (es gibt kein Bild als Quelle).
- `global_context` optional (z.B. "Hochzeitsreportage im Burggarten").
- **Kein LM-Studio-Lokal-Modus** (nur Server), da der Text-Flow im Backend via `generateMetadataFromText()` läuft.
- **Response:** Gleiches Schema wie Vision: `{title, description, keywords, location}` (ohne `detected_city`).

### 3.3 Local Mode (LM Studio)

- The browser probes LM Studio only when the server status is **unconfigured** or
  the status request fails. An explicit `status=disabled` is authoritative and
  never falls back to LM Studio.
- URL from `localStorage.lmstudio_url` or `VITE_LMSTUDIO_URL` env or
  `http://127.0.0.1:1234`; the configured URL is restricted to localhost HTTP.
- Vision only (same prompt structure as server mode). The text-only flow always
  uses the server endpoint and has no local-mode branch.
- Model ID is resolved via `GET {localUrl}/v1/models`.

## 4. Frontend Architecture

### 4.1 Hook: `useAI()`

```
interface UseAIReturn {
  isAvailable: boolean;
  mode: 'server' | 'local' | 'unavailable';
  modelId: string | null;
  generateMetadata(photoId, globalContext, specificContext, signal?, sessionId?): Promise<AIResponse>;
  generateMetadataFromText(textInput, globalContext?): Promise<AIResponse>;
  updateBaseUrl(url: string): void;
}
```

**`GET /api/ai/status` response** now includes a `status` field to disambiguate disabled vs unconfigured:

```json
{ "enabled": false, "status": "disabled",     "type": "openai", "model": "gpt-4o" }
{ "enabled": false, "status": "unconfigured",  "type": "openai", "model": "gpt-4o" }
{ "enabled": true,  "status": "available",    "type": "openai", "model": "gpt-4o" }
```

**Mode resolution** (on mount, via `useEffect`):
1. Call `GET /api/ai/status`.
2. `status === 'disabled'` → `mode='unavailable'`, `isAvailable=false`, and
   **no** LM Studio probe (an explicit disable is authoritative).
3. Effective `enabled === true` (normally `status === 'available'`) →
   `mode='server'`, `isAvailable=true`.
4. `status === 'unconfigured'` or a status-request/HTTP error → probe
   `GET {localUrl}/v1/models`; a returned model selects `mode='local'`.
5. No usable server or local model → `mode='unavailable'`, `isAvailable=false`.

The status endpoint's `enabled` value is the effective server availability,
not merely the raw `AI_ENABLED` setting.

**Zod validation** (`aiResponseSchema`): All responses are validated client-side.

### 4.2 Consumer Components

| Component | Uses | Methode | Bild nötig? | Text-Kontext |
|---|---|---|---|---|
| `AIBatchEditModal` | `generateMetadata` | Vision (server/local) | **Ja** (primär) | Optional (global pro Batch + spezifisch pro Bild) |
| `AIGalleryDefaultsModal` | `generateMetadataFromText` | Text-only (server) | **Nein** | Required (`text_input`) |

## 5. Backend Architecture

### 5.1 AI Provider Strategy Pattern

The AI subsystem uses a Strategy Pattern via `AIProvider` interface to support multiple LLM backends.

### 5.2 Interface: `AIProvider`

```php
interface AIProvider
{
    public function buildRequest(string $model, array $messages): array;
    public function buildHeaders(?string $sessionId = null): array;
    public function sessionHeaderName(): ?string;
    public function getEndpoint(): string;
    public function parseResponse(array $responseData): string;
    public function supportsJsonMode(): bool;
}
```

### 5.3 Provider Implementations

| Provider | Endpoint | Auth Header | json_mode | Response Path |
|---|---|---|---|---|
| `OpenAIProvider` | `/chat/completions` | `Authorization: Bearer {key}` | true | `choices[0].message.content` |
| `AnthropicProvider` | `/messages` | `x-api-key: {key}` + `anthropic-version: 2023-06-01` | false | `content[0].text` |
| `LMStudioProvider` | `/chat/completions` | `Authorization: Bearer {key}` (optional) | false | `choices[0].message.content` |

`AnthropicProvider` extracts the system message from the messages array and sets it as a top-level `system` parameter. All other messages are converted to Anthropic's `{role, content: [{type: "text", text: "..."}]}` format. OpenAI-shaped `image_url` blocks are translated to Anthropic `{type: "image", source: ...}` blocks before transport; data URIs are split into `media_type` and Base64 `data`, while HTTP(S) URLs use Anthropic's URL source form.

`LMStudioProvider` follows the OpenAI-compatible format and only sends the `Authorization` header if `api_key` is configured.

### 5.4 Factory: `AIProviderFactory`

```php
class AIProviderFactory
{
    public function make(): AIProvider
    {
        return match (config('services.ai.type')) {
            'anthropic' => new AnthropicProvider(),
            'lmstudio'  => new LMStudioProvider(),
            default     => new OpenAIProvider(),
        };
    }
}
```

`AIService::callAI()` resolves the provider via `app(AIProviderFactory::class)->make()` and delegates request building, header construction, and response parsing to the provider. Cross-cutting concerns (`temperature`, `max_tokens`, `response_format` when supported) are set in `callAI()`.

For a non-success provider response, `callAI()` logs only the HTTP status and response-body length. It never persists the provider response body because providers may echo prompts, image-derived data, or other sensitive input. The public error contract remains status-only (`502` with `AI API Fehler: <status>`); transport connection failures remain `503`.

### 5.5 Controller: `AIController`

| Route | Method | Auth | Description |
|---|---|---|---|---|
| `GET /api/ai/status` | `status()` | auth:api | Returns `{enabled, status, type, model}` |
| `POST /api/ai/generate-metadata` | `generateMetadata()` | auth:api + coarse capability check + `updateMetadata` PhotoPolicy Gate | Photo vision analysis; the resolved target is authorized before service availability and request validation |
| `POST /api/ai/generate-metadata-text` | `generateMetadataText()` | auth:api + `create Gallery` Gate | Text-only metadata for photographers/super-admins; ordinary admins are denied; authorization is checked before service availability |

Both generation endpoints apply the same request-order contract: authenticate first, authorize the actor and any concrete target before evaluating provider availability, then validate the request and perform the provider call. For an authorized actor, availability is checked before request validation, matching the text flow's `503`/`422` precedence.

The vision endpoint needs two authorization stages because `updateMetadata` is target-scoped. First, a coarse category check rejects actors with no possible metadata capability before any photo query; their generic `403` cannot reveal whether a submitted ID exists. Photographers, Admins, and Super-Admins are role-capable; clients with `can_edit_metadata` and identities with active metadata invite grants are target-scoped. For an actor that passes the coarse check, resolving a submitted non-empty `photo_id` is necessary to construct the `Photo` policy subject and its gallery relationship before PhotoPolicy can run.

For target-scoped client/invite actors, missing, malformed, unknown, and inaccessible photo IDs all produce the same opaque `403`; `can_edit_metadata` is never treated as a stand-alone grant or as proof that any target exists. For role-capable actors, a missing or unknown ID remains a normal `422` validation result when AI is available. No target or gallery data is included in either the `403` or validation response. An unauthenticated request is `401`, an unauthorized request is `403` regardless of whether AI is disabled or unconfigured, and only an authorized unavailable request receives `503`. Provider and connection failures keep their existing `502`/`503` contracts.

### 5.6 Service: `AIService`

| Method | Type | Depends on |
|---|---|---|
| `isDisabled()` | state | resolved `config('services.ai.enabled')` is falsey |
| `isUnconfigured()` | raw state helper | enabled with an empty `AI_API_KEY`; for `AI_TYPE=lmstudio`, the status endpoint still reports `available` because the availability check wins after the disabled check |
| `isAvailable()` | effective state | enabled and (`AI_TYPE=lmstudio` or a non-empty `AI_API_KEY`) |
| `generateMetadata(Photo, context, sessionId?)` | vision | Photo file on disk, `loadAndCompressImage` |
| `generateMetadataFromText(string, sessionId?)` | text | None |
| `loadAndCompressImage(Photo)` | helper | GD library, `Storage::disk('photos')` |
| `callAI(messages, sessionId?)` | transport | `AIProviderFactory::make()`, HTTP client, `config('services.ai.*')` |

### 5.7 Session-affinity header (prompt-cache routing)

Batch vision requests (`AIBatchEditModal` → "Alle generieren") share an identical
prefix (system prompt + global context) and differ only per image. To let
gateways reuse the cached prefix, the frontend generates **one UUID per batch
run** and sends it as `session_id`; the backend forwards it as an HTTP header:

```
AIBatchEditModal (handleGenerateAll → crypto.randomUUID())
  → useAI.generateMetadata(..., sessionId)
  → POST /api/ai/generate-metadata { ..., session_id }
  → AIController → AIService::generateMetadata(..., $sessionId)
  → AIProvider::buildHeaders($sessionId) → {session_header}: {prefix}{id}
```

- `AIProvider::sessionHeaderName(): ?string` returns the provider-specific
  header name, or `null` when the provider needs no session affinity
  (`LMStudioProvider`). `OpenAIProvider` and `AnthropicProvider` share the
  `HasSessionHeader` trait and default to `x-opencode-session` (OpenCode Go).
- The header name is configurable per deployment via `AI_SESSION_HEADER`
  (empty value disables the header); the value prefix via `AI_SESSION_PREFIX`
  (default `portal-`). Values are sanitized to `[A-Za-z0-9._-]` and clamped to
  128 chars.
- Without a `session_id` no session header is sent at all (no generated
  fallback); `generate-metadata-text` accepts an optional `session_id` but the
  UI does not send one (single text-only request, no batch prefix to reuse).
- Every provider request additionally carries a **hardcoded** `User-Agent:
  reisinger.pictures Portal` header, applied by all three providers in
  `buildHeaders()` via the shared `HasUserAgent` concern. The value is
  deliberately not configurable (no env/config key): OpenCode Go requires
  proper client identification and blocks generic defaults such as Guzzle's
  `GuzzleHttp/x.y`.

## 6. Configuration

```php
// config/services.php
'ai' => [
    'enabled'        => env('AI_ENABLED', false),           // falsey disables; true enables
    'type'           => env('AI_TYPE', 'openai'),            // openai|anthropic|lmstudio
    'base_url'       => env('AI_BASE_URL', 'https://api.openai.com/v1'),
    'api_key'        => env('AI_API_KEY'),
    'model'          => env('AI_MODEL', 'gpt-4o'),
    'session_header' => env('AI_SESSION_HEADER', 'x-opencode-session'), // '' disables
    'session_prefix' => env('AI_SESSION_PREFIX', 'portal-'),
],
```

`AI_TYPE` selects the active provider. `base_url` must point to the API root for the selected provider:
- OpenAI: `https://api.openai.com/v1`
- Anthropic: `https://api.anthropic.com/v1`
- LM Studio: `http://127.0.0.1:1234/v1`

The `User-Agent` header (`reisinger.pictures Portal`) is intentionally absent
from this config: it is hardcoded in `App\AI\Concerns\HasUserAgent` (OpenCode
Go identification) and must not become an env key.

### 6.1 Status endpoint (`GET /api/ai/status`)

```json
{ "enabled": false, "status": "disabled",     "type": "openai", "model": "gpt-4o" }
{ "enabled": false, "status": "unconfigured",  "type": "openai", "model": "gpt-4o" }
{ "enabled": true,  "status": "available",    "type": "openai", "model": "gpt-4o" }
```

`type` field reflects the current `AI_TYPE` value. The `status` field disambiguates disabled vs unconfigured.
