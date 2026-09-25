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

    public function test_malformed_success_body_prompts_and_pii_are_not_logged(): void
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
        $rawBody = '<html>PRIVATE-PROVIDER-BODY</html>';
        Http::fake([
            '*/chat/completions' => Http::response($rawBody, 200),
        ]);

        Log::spy();

        try {
            $this->service->generateMetadataFromText($prompt, $personalContext);
            $this->fail('Expected a RuntimeException for the malformed successful AI response.');
        } catch (\RuntimeException $e) {
            $this->assertSame('AI API Fehler: 200', $e->getMessage());
        }

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []) use ($rawBody, $prompt, $personalContext): bool {
                $serializedContext = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'AI API call failed'
                    && $context === [
                        'status' => 200,
                        'body_length' => strlen($rawBody),
                    ]
                    && ! str_contains($serializedContext, 'PRIVATE-PROVIDER-BODY')
                    && ! str_contains($serializedContext, $prompt)
                    && ! str_contains($serializedContext, $personalContext);
            })
            ->once();
    }

    public function test_invalid_success_json_body_prompts_and_pii_are_not_logged(): void
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
        $rawBody = json_encode([
            'choices' => [[
                'message' => ['content' => 'PRIVATE-PROVIDER-CONTENT'],
            ]],
        ], JSON_THROW_ON_ERROR);
        Http::fake([
            '*/chat/completions' => Http::response($rawBody, 200),
        ]);

        Log::spy();

        try {
            $this->service->generateMetadataFromText($prompt, $personalContext);
            $this->fail('Expected a RuntimeException for invalid JSON in a successful AI response.');
        } catch (\RuntimeException $e) {
            $this->assertSame('AI API Fehler: 200', $e->getMessage());
        }

        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context = []) use ($rawBody, $prompt, $personalContext): bool {
                $serializedContext = json_encode($context, JSON_THROW_ON_ERROR);

                return $message === 'AI API call failed'
                    && $context === [
                        'status' => 200,
                        'body_length' => strlen($rawBody),
                    ]
                    && ! str_contains($serializedContext, 'PRIVATE-PROVIDER-CONTENT')
                    && ! str_contains($serializedContext, $prompt)
                    && ! str_contains($serializedContext, $personalContext);
            })
            ->once();
    }

    public function test_response_shape_guard_precedes_provider_parser_and_logging_is_status_only(): void
    {
        $source = file_get_contents(app_path('Services/AIService.php'));
        $this->assertIsString($source);

        $decodePosition = strpos($source, '$data = $response->object();');
        $guardPosition = strpos($source, 'if (! $data instanceof \stdClass)');
        $parserPosition = strpos($source, '$provider->parseResponse($data)');

        $this->assertNotFalse($decodePosition);
        $this->assertNotFalse($guardPosition);
        $this->assertNotFalse($parserPosition);
        $this->assertTrue($decodePosition < $guardPosition && $guardPosition < $parserPosition);
        $this->assertStringContainsString("'body_length' =>", $source);
        $this->assertStringNotContainsString("'body' =>", $source);
        $this->assertStringNotContainsString('AI response is not valid JSON', $source);
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
