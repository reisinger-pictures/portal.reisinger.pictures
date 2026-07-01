<?php
namespace App\AI\Providers;

use App\AI\Contracts\AIProvider;

class OpenAIProvider implements AIProvider
{
    public function buildRequest(string $model, array $messages): array
    {
        return [
            'model' => $model,
            'messages' => $messages,
        ];
    }

    public function buildHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . config('services.ai.api_key'),
            'Content-Type' => 'application/json',
        ];
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
