<?php
namespace App\AI\Providers;

use App\AI\Contracts\AIProvider;

class LMStudioProvider implements AIProvider
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
        $headers = [
            'Content-Type' => 'application/json',
        ];

        $apiKey = config('services.ai.api_key');
        if (!empty($apiKey)) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        return $headers;
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
        return false;
    }
}
