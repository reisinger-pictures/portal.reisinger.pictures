# AI Service Architecture (Soll-Zustand)

**Status:** Accepted (Option A – Vision retained)

## 1. Overview

The AI subsystem generates photo metadata (title, description, keywords, location) via LLM-based services. It supports two generation methods and three operational states.

## 2. Three-State Machine

The system has exactly three states, controlled by `AI_ENABLED` env var (boolean via Laravel's DotEnv parser):

```
AI_ENABLED=false        → isDisabled()     → Feature hidden entirely, no admin banner
AI_ENABLED=true         → isUnconfigured() → Admin banner "please configure" shown (when api_key missing)
AI_ENABLED=true + key   → isAvailable()    → Normal operation
```

- Laravel's `env('AI_ENABLED', false)` returns boolean `false`/`true` from `.env` file.
- `AI_TYPE` selects the provider: `openai` (default), `anthropic`, or `lmstudio`.
- LM Studio bypasses the api_key check: `isAvailable()` returns `true` if `AI_TYPE=lmstudio` regardless of key.
- Admin banner (`isUnconfigured`) only appears when AI is neither disabled nor fully configured.

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

- Falls back when server AI is unavailable/unconfigured.
- URL from `localStorage.lmstudio_url` or `VITE_LMSTUDIO_URL` env or `http://127.0.0.1:1234`.
- Vision only (same prompt structure as server mode).
- Model ID resolved via `GET {localUrl}/v1/models`.

## 4. Frontend Architecture

### 4.1 Hook: `useAI()`

```
interface UseAIReturn {
  isAvailable: boolean;
  mode: 'server' | 'local' | 'unavailable';
  modelId: string | null;
  generateMetadata(photoId, globalContext, specificContext, signal?): Promise<AIResponse>;
  generateMetadataFromText(textInput, globalContext?): Promise<AIResponse>;
  updateBaseUrl(url: string): void;
}
```

**`GET /api/ai/status` response** now includes a `status` field to disambiguate disabled vs unconfigured:

```json
{ "enabled": false, "status": "disabled",     "model": null }
{ "enabled": false, "status": "unconfigured",  "model": null }
{ "enabled": true,  "status": "available",    "model": "gpt-4o" }
```

**Mode resolution** (on mount, via useEffect):
1. Call `GET /api/ai/status`
2. If `status === 'available'` → `mode='server'`, `isAvailable=true`, done
3. If `status === 'disabled'` → `mode='unavailable'`, `isAvailable=false`, **NO** LM Studio fallback (intentionally off)
4. Else (status = unconfigured or HTTP error) → try LM Studio fallback:
   - `GET {localUrl}/v1/models` → if model found → `mode='local'`, `isAvailable=true`
   - Else `mode='unavailable'`, `isAvailable=false`

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
    public function buildHeaders(): array;
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

`AnthropicProvider` extracts the system message from the messages array and sets it as a top-level `system` parameter. All other messages are converted to Anthropic's `{role, content: [{type: "text", text: "..."}]}` format.

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

### 5.5 Controller: `AIController`

| Route | Method | Auth | Description |
|---|---|---|---|---|
| `GET /api/ai/status` | `status()` | auth:api | Returns `{enabled, status, model}` |
| `POST /api/ai/generate-metadata` | `generateMetadata()` | auth:api + Gate | Photo vision analysis; returns 503 if disabled/unconfigured |
| `POST /api/ai/generate-metadata-text` | `generateMetadataText()` | auth:api | Text-only metadata; returns 503 if disabled/unconfigured |

### 5.6 Service: `AIService`

| Method | Type | Depends on |
|---|---|---|
| `isDisabled()` | state | `AI_ENABLED === 'DISABLED'` |
| `isUnconfigured()` | state | `!isDisabled() && !isAvailable()` |
| `isAvailable()` | state | `AI_ENABLED truthy && api_key present` |
| `generateMetadata(Photo, context)` | vision | Photo file on disk, `loadAndCompressImage` |
| `generateMetadataFromText(string)` | text | None |
| `loadAndCompressImage(Photo)` | helper | GD library, `Storage::disk('photos')` |
| `callAI(messages)` | transport | `AIProviderFactory::make()`, HTTP client, `config('services.ai.*')` |

## 6. Configuration

```php
// config/services.php
'ai' => [
    'enabled'  => env('AI_ENABLED', false),           // boolean (true|false)
    'type'     => env('AI_TYPE', 'openai'),            // openai|anthropic|lmstudio
    'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
    'api_key'  => env('AI_API_KEY'),
    'model'    => env('AI_MODEL', 'gpt-4o'),
],
```

`AI_TYPE` selects the active provider. `base_url` must point to the API root for the selected provider:
- OpenAI: `https://api.openai.com/v1`
- Anthropic: `https://api.anthropic.com/v1`
- LM Studio: `http://127.0.0.1:1234/v1`

### 6.1 Status endpoint (`GET /api/ai/status`)

```json
{ "enabled": false, "status": "disabled",     "type": "openai", "model": "gpt-4o" }
{ "enabled": false, "status": "unconfigured",  "type": "openai", "model": "gpt-4o" }
{ "enabled": true,  "status": "available",    "type": "openai", "model": "gpt-4o" }
```

`type` field reflects the current `AI_TYPE` value. The `status` field disambiguates disabled vs unconfigured.
