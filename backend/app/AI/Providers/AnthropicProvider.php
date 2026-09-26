<?php

namespace App\AI\Providers;

use App\AI\Concerns\HasSessionHeader;
use App\AI\Concerns\HasUserAgent;
use App\AI\Contracts\AIProvider;

class AnthropicProvider implements AIProvider
{
    use HasSessionHeader;
    use HasUserAgent;

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
                    'content' => $this->normalizeContent($message['content'] ?? ''),
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

    /**
     * Normalize an OpenAI-shaped content value into the Anthropic content-block
     * format. Anthropic's /messages endpoint expects images as
     * ["type" => "image", "source" => [...]] and rejects OpenAI's
     * ["type" => "image_url", "image_url" => ["url" => ...]] blocks with a 400.
     *
     * @param  mixed  $content
     * @return mixed
     */
    private function normalizeContent($content)
    {
        if (is_string($content)) {
            return [['type' => 'text', 'text' => $content]];
        }

        if (! is_array($content)) {
            return $content;
        }

        $normalized = [];

        foreach ($content as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'image_url') {
                $normalized[] = $this->normalizeImageBlock($block);

                continue;
            }

            $normalized[] = $block;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function normalizeImageBlock(array $block): array
    {
        $url = $block['image_url']['url'] ?? $block['image_url'] ?? '';

        if (is_string($url) && preg_match('#^data:(?<mime>[^;]+);base64,(?<data>.+)$#s', $url, $matches) === 1) {
            return [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $matches['mime'],
                    'data' => $matches['data'],
                ],
            ];
        }

        return [
            'type' => 'image',
            'source' => [
                'type' => 'url',
                'url' => (string) $url,
            ],
        ];
    }

    public function buildHeaders(?string $sessionId = null): array
    {
        return $this->withUserAgent($this->withSessionHeader([
            'x-api-key' => config('services.ai.api_key'),
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ], $sessionId));
    }

    public function getEndpoint(): string
    {
        return '/messages';
    }

    public function parseResponse(\stdClass $responseData): string
    {
        $contentBlocks = $responseData->content ?? null;
        if (! is_array($contentBlocks) || ! array_is_list($contentBlocks)) {
            return '';
        }

        $block = $contentBlocks[0] ?? null;
        if (! $block instanceof \stdClass) {
            return '';
        }

        $content = $block->text ?? null;

        return is_string($content) ? $content : '';
    }

    public function supportsJsonMode(): bool
    {
        return false;
    }
}
