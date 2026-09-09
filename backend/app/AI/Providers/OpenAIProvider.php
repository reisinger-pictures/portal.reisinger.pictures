<?php
namespace App\AI\Providers;

use App\AI\Contracts\AIProvider;
use App\AI\Concerns\HasSessionHeader;
use App\AI\Concerns\HasUserAgent;

class OpenAIProvider implements AIProvider
{
    use HasSessionHeader;
    use HasUserAgent;

    public function buildRequest(string $model, array $messages): array
    {
        return [
            'model' => $model,
            'messages' => $messages,
        ];
    }

    public function buildHeaders(?string $sessionId = null): array
    {
        return $this->withUserAgent($this->withSessionHeader([
            'Authorization' => 'Bearer ' . config('services.ai.api_key'),
            'Content-Type' => 'application/json',
        ], $sessionId));
    }

    public function getEndpoint(): string
    {
        return '/chat/completions';
    }

    public function parseResponse(array $responseData): string
    {
        return $responseData['choices'][0]['message']['content'] ?? '{}';
    }

    public function supportsJsonMode(): bool
    {
        return true;
    }
}
