<?php

namespace App\AI\Providers;

use App\AI\Concerns\HasUserAgent;
use App\AI\Contracts\AIProvider;

class LMStudioProvider implements AIProvider
{
    use HasUserAgent;

    public function buildRequest(string $model, array $messages): array
    {
        return [
            'model' => $model,
            'messages' => $messages,
        ];
    }

    public function sessionHeaderName(): ?string
    {
        // Local inference needs no session affinity / sticky routing.
        return null;
    }

    public function buildHeaders(?string $sessionId = null): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        $apiKey = config('services.ai.api_key');
        if (! empty($apiKey)) {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        }

        return $this->withUserAgent($headers);
    }

    public function getEndpoint(): string
    {
        return '/chat/completions';
    }

    public function parseResponse(\stdClass $responseData): string
    {
        $choices = $responseData->choices ?? null;
        if (! is_array($choices) || ! array_is_list($choices)) {
            return '';
        }

        $choice = $choices[0] ?? null;
        $message = $choice instanceof \stdClass ? ($choice->message ?? null) : null;
        if (! $message instanceof \stdClass) {
            return '';
        }

        $content = $message->content ?? null;

        return is_string($content) ? $content : '';
    }

    public function supportsJsonMode(): bool
    {
        return false;
    }
}
