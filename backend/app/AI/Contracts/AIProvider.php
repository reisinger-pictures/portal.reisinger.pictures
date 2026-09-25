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

    /**
     * Parse a decoded provider envelope. The top-level JSON object and nested
     * list/object distinction must be preserved for envelope validation.
     */
    public function parseResponse(\stdClass $responseData): string;

    public function supportsJsonMode(): bool;
}
