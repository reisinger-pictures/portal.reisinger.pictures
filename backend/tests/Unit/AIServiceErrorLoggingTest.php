<?php

namespace Tests\Unit;

use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regression: the full provider error body used to be persisted to the log,
 * which can store prompts/PII and unbounded amounts of data.
 */
class AIServiceErrorLoggingTest extends TestCase
{
    use RefreshDatabase;

    private AIService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AIService::class);
    }

    public function test_provider_error_body_is_truncated_before_logging(): void
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);

        $rawBody = str_repeat('SENSITIVE-PROVIDER-BODY-', 400); // ~10k chars
        Http::fake([
            '*/chat/completions' => Http::response($rawBody, 500),
        ]);

        Log::spy();

        try {
            $this->service->generateMetadataFromText('Test prompt', '');
            $this->fail('Expected a RuntimeException for the failed AI call.');
        } catch (\RuntimeException $e) {
            $this->assertSame('AI API Fehler: 500', $e->getMessage());
        }

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []) use ($rawBody): bool {
                return $message === 'AI API call failed'
                    && ($context['status'] ?? null) === 500
                    && is_string($context['body'] ?? null)
                    && mb_strlen($context['body']) <= 600
                    && ($context['body_length'] ?? null) === strlen($rawBody);
            })
            ->once();
    }
}
