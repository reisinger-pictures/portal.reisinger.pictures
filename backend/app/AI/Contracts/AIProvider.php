<?php
namespace App\AI\Contracts;

interface AIProvider
{
    public function buildRequest(string $model, array $messages): array;
    public function buildHeaders(?string $sessionId = null): array;
    /**
     * HTTP header name used for session affinity / prompt-cache routing,
     * or null when the provider does not support session headers.
     */
    public function sessionHeaderName(): ?string;
    public function getEndpoint(): string;
    public function parseResponse(array $responseData): string;
    public function supportsJsonMode(): bool;
}
