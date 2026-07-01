<?php
namespace App\AI\Contracts;

interface AIProvider
{
    public function buildRequest(string $model, array $messages): array;
    public function buildHeaders(): array;
    public function getEndpoint(): string;
    public function parseResponse(array $responseData): string;
    public function supportsJsonMode(): bool;
}
