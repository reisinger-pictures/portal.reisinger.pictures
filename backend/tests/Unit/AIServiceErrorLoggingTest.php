<?php

namespace Tests\Unit;

use App\Services\AIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regression: the full provider error body used to be persisted to the log,
 * which can store prompts/PII and unbounded amounts of data.
 */
class AIServiceErrorLoggingTest extends TestCase
{
    private AIService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AIService::class);
    }

    public function test_provider_error_body_prompts_and_pii_are_not_logged(): void
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);

        $prompt = 'PRIVATE-PROMPT-MARKER';
        $personalContext = 'PRIVATE-PII-MARKER';
        $rawBody = str_repeat('SENSITIVE-PROVIDER-BODY-', 400); // ~10k chars
        Http::fake([
            '*/chat/completions' => Http::response($rawBody, 500),
        ]);

        Log::spy();

        try {
            $this->service->generateMetadataFromText($prompt, $personalContext);
            $this->fail('Expected a RuntimeException for the failed AI call.');
        } catch (\RuntimeException $e) {
            $this->assertSame('AI API Fehler: 500', $e->getMessage());
        }

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []) use ($rawBody, $prompt, $personalContext): bool {
                $serializedContext = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'AI API call failed'
                    && $context === [
                        'status' => 500,
                        'body_length' => strlen($rawBody),
                    ]
                    && ! str_contains($serializedContext, 'SENSITIVE-PROVIDER-BODY')
                    && ! str_contains($serializedContext, $prompt)
                    && ! str_contains($serializedContext, $personalContext);
            })
            ->once();
    }
}
