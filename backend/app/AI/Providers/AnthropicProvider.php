<?php
namespace App\AI\Providers;

use App\AI\Contracts\AIProvider;
use App\AI\Concerns\HasSessionHeader;

class AnthropicProvider implements AIProvider
{
    use HasSessionHeader;
    public function buildRequest(string $model, array $messages): array
    {
        $system = null;
        $bodyMessages = [];

        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'system' && $system === null) {
                $system = $message['content'];
            } else {
                $bodyMessages[] = [
                    'role' => $message['role'] ?? 'user',
                    'content' => is_string($message['content'] ?? '')
                        ? [['type' => 'text', 'text' => $message['content']]]
                        : $message['content'],
                ];
            }
        }

        $body = [
            'model' => $model,
            'messages' => $bodyMessages,
            'max_tokens' => 2000,
        ];

        if ($system !== null) {
            $body['system'] = is_string($system) ? $system : (json_encode($system) ?: '');
        }

        return $body;
    }

    public function buildHeaders(?string $sessionId = null): array
    {
        return $this->withSessionHeader([
            'x-api-key' => config('services.ai.api_key'),
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ], $sessionId);
    }

    public function getEndpoint(): string
    {
        return '/messages';
    }

    public function parseResponse(array $responseData): string
    {
        return $responseData['content'][0]['text'] ?? '{}';
    }

    public function supportsJsonMode(): bool
    {
        return false;
    }
}
