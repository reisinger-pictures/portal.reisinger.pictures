<?php

namespace Tests\Unit;

use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LMStudioProvider;
use App\AI\Providers\OpenAIProvider;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use App\Services\AIProviderFactory;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AIServiceTest extends TestCase
{
    use RefreshDatabase;

    private AIService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(AIService::class);
    }

    public function test_is_disabled_returns_true_when_enabled_is_false()
    {
        config(['services.ai.enabled' => false]);
        $this->assertTrue($this->service->isDisabled());
        $this->assertFalse($this->service->isAvailable());
        $this->assertFalse($this->service->isUnconfigured());
    }

    public function test_is_disabled_returns_false_when_enabled()
    {
        config(['services.ai.enabled' => true, 'services.ai.api_key' => 'test-key']);
        $this->assertFalse($this->service->isDisabled());
    }

    public function test_is_available_returns_false_when_key_missing()
    {
        config(['services.ai.enabled' => true, 'services.ai.api_key' => '']);
        $this->assertFalse($this->service->isAvailable());
    }

    public function test_is_available_returns_true_when_enabled_with_key()
    {
        config(['services.ai.enabled' => true, 'services.ai.api_key' => 'test-key']);
        $this->assertTrue($this->service->isAvailable());
    }

    public function test_is_available_returns_true_when_lmstudio()
    {
        config(['services.ai.enabled' => true, 'services.ai.type' => 'lmstudio', 'services.ai.api_key' => '']);
        $this->assertTrue($this->service->isAvailable());
    }

    public function test_is_unconfigured_returns_true_when_key_missing()
    {
        config(['services.ai.enabled' => true, 'services.ai.api_key' => '']);
        $this->assertFalse($this->service->isDisabled());
        $this->assertFalse($this->service->isAvailable());
        $this->assertTrue($this->service->isUnconfigured());
    }

    public function test_is_unconfigured_returns_false_when_available()
    {
        config(['services.ai.enabled' => true, 'services.ai.api_key' => 'test-key']);
        $this->assertFalse($this->service->isDisabled());
        $this->assertTrue($this->service->isAvailable());
        $this->assertFalse($this->service->isUnconfigured());
    }

    public function test_is_unconfigured_returns_false_when_disabled()
    {
        config(['services.ai.enabled' => false]);
        $this->assertTrue($this->service->isDisabled());
        $this->assertFalse($this->service->isUnconfigured());
    }

    public function test_factory_returns_openai_by_default()
    {
        config(['services.ai.type' => null]);
        $provider = app(AIProviderFactory::class)->make();
        $this->assertInstanceOf(OpenAIProvider::class, $provider);
    }

    public function test_factory_returns_anthropic()
    {
        config(['services.ai.type' => 'anthropic']);
        $provider = app(AIProviderFactory::class)->make();
        $this->assertInstanceOf(AnthropicProvider::class, $provider);
    }

    public function test_factory_returns_lmstudio()
    {
        config(['services.ai.type' => 'lmstudio']);
        $provider = app(AIProviderFactory::class)->make();
        $this->assertInstanceOf(LMStudioProvider::class, $provider);
    }

    public function test_openai_provider_builds_correct_request()
    {
        $provider = new OpenAIProvider;
        $request = $provider->buildRequest('gpt-4o', [
            ['role' => 'system', 'content' => 'You are a bot'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $this->assertEquals('gpt-4o', $request['model']);
        $this->assertCount(2, $request['messages']);
        $this->assertEquals('/chat/completions', $provider->getEndpoint());
        $this->assertTrue($provider->supportsJsonMode());
    }

    public function test_anthropic_provider_builds_correct_request()
    {
        $provider = new AnthropicProvider;
        $request = $provider->buildRequest('claude-3-opus-20240229', [
            ['role' => 'system', 'content' => 'You are a helpful assistant'],
            ['role' => 'user', 'content' => 'Tell me a story'],
        ]);

        $this->assertEquals('claude-3-opus-20240229', $request['model']);
        $this->assertEquals('You are a helpful assistant', $request['system']);
        $this->assertCount(1, $request['messages']);
        $this->assertEquals('user', $request['messages'][0]['role']);
        $this->assertEquals('/messages', $provider->getEndpoint());
        $this->assertFalse($provider->supportsJsonMode());
    }

    public function test_anthropic_provider_parses_response()
    {
        $provider = new AnthropicProvider;
        $responseData = new \stdClass;
        $responseData->content = [
            (object) ['text' => '{"title": "Test"}'],
        ];
        $content = $provider->parseResponse($responseData);
        $this->assertEquals('{"title": "Test"}', $content);
    }

    public function test_provider_parsers_reject_numeric_key_envelope_objects(): void
    {
        $content = '{"title":"T","description":"D","keywords":"k","location":"L"}';
        $openAiResponse = new \stdClass;
        $openAiResponse->choices = (object) [
            '0' => [(object) [
                'message' => (object) ['content' => $content],
            ]],
        ];
        $lmStudioResponse = clone $openAiResponse;
        $anthropicResponse = new \stdClass;
        $anthropicResponse->content = (object) [
            '0' => [(object) ['text' => $content]],
        ];

        $this->assertSame('', (new OpenAIProvider)->parseResponse($openAiResponse));
        $this->assertSame('', (new LMStudioProvider)->parseResponse($lmStudioResponse));
        $this->assertSame('', (new AnthropicProvider)->parseResponse($anthropicResponse));
    }

    public function test_lmstudio_provider_omits_auth_when_no_key()
    {
        config(['services.ai.api_key' => '']);
        $provider = new LMStudioProvider;
        $headers = $provider->buildHeaders();

        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertEquals('/chat/completions', $provider->getEndpoint());
        $this->assertFalse($provider->supportsJsonMode());
    }

    public function test_lmstudio_provider_includes_auth_when_key_set()
    {
        config(['services.ai.api_key' => 'lm-key']);
        $provider = new LMStudioProvider;
        $headers = $provider->buildHeaders();

        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertEquals('Bearer lm-key', $headers['Authorization']);
    }

    public function test_generate_metadata_from_text_makes_correct_api_call()
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "Test Title", "description": "Test Description", "keywords": "key1, key2", "location": "Vienna"}',
                    ],
                ]],
            ]),
        ]);

        $result = $this->service->generateMetadataFromText('A photo of a mountain', 'Summer 2024');

        $this->assertEquals('Test Title', $result['title']);
        $this->assertEquals('Test Description', $result['description']);
        $this->assertEquals('key1, key2', $result['keywords']);
        $this->assertEquals('Vienna', $result['location']);
        $this->assertEquals('', $result['detected_city']);

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'gpt-4o'
                && $body['temperature'] === 0.2
                && $body['response_format']['type'] === 'json_object'
                && count($body['messages']) === 2;
        });
    }

    public function test_generate_metadata_from_text_throws_on_http_error()
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);

        Http::fake([
            '*/chat/completions' => Http::response([], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI API Fehler: 401');

        $this->service->generateMetadataFromText('Test', '');
    }

    #[DataProvider('malformedProviderResponseProvider')]
    public function test_malformed_successful_provider_response_is_a_status_only_error(
        string $type,
        string $baseUrl,
        string $endpoint,
        mixed $responseBody,
    ): void {
        config(['services.ai' => [
            'enabled' => true,
            'type' => $type,
            'base_url' => $baseUrl,
            'api_key' => 'test-key',
            'model' => 'test-model',
        ]]);

        Http::fake([
            $endpoint => Http::response($responseBody, 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI API Fehler: 200');

        $this->service->generateMetadataFromText('PRIVATE-PROMPT', 'PRIVATE-PII');
    }

    public static function malformedProviderResponseProvider(): array
    {
        return [
            'HTML body' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                '<html><body>PRIVATE-PROVIDER-BODY</body></html>',
            ],
            'empty body' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                null,
            ],
            'JSON null body' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                'null',
            ],
            'scalar string body' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                '"PRIVATE-PROVIDER-SCALAR"',
            ],
            'scalar integer body' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                '42',
            ],
            'missing choices' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                [],
            ],
            'OpenAI numeric-key choices object' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                '{"choices":{"0":'.json_encode([
                    [
                        'message' => [
                            'content' => '{"title":"T","description":"D","keywords":"k","location":"L"}',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR).'}}',
            ],
            'LM Studio numeric-key choices object' => [
                'lmstudio',
                'http://127.0.0.1:1234/v1',
                '*/chat/completions',
                '{"choices":{"0":'.json_encode([
                    [
                        'message' => [
                            'content' => '{"title":"T","description":"D","keywords":"k","location":"L"}',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR).'}}',
            ],
            'Anthropic numeric-key content object' => [
                'anthropic',
                'https://api.anthropic.com/v1',
                '*/messages',
                '{"content":{"0":'.json_encode([
                    [
                        'text' => '{"title":"T","description":"D","keywords":"k","location":"L"}',
                    ],
                ], JSON_THROW_ON_ERROR).'}}',
            ],
            'missing content' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                ['choices' => [['message' => []]]],
            ],
            'wrong content type' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                ['choices' => [['message' => ['content' => ['PRIVATE-PROVIDER-SHAPE']]]]],
            ],
            'wrong metadata type' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                ['choices' => [['message' => ['content' => '"PRIVATE-PROVIDER-SHAPE"']]]],
            ],
            'empty metadata object' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                ['choices' => [['message' => ['content' => '{}']]]],
            ],
            'metadata JSON list' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                ['choices' => [['message' => ['content' => '["PRIVATE-PROVIDER-SHAPE"]']]]],
            ],
            'metadata missing required field' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                ['choices' => [['message' => ['content' => '{"title":"PRIVATE-PROVIDER-SHAPE"}']]]],
            ],
            'keywords object' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                ['choices' => [['message' => ['content' => '{"title":"T","description":"D","keywords":{},"location":"L"}']]]],
            ],
            'keywords mixed list' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                ['choices' => [['message' => ['content' => '{"title":"T","description":"D","keywords":["ok",7],"location":"L"}']]]],
            ],
            'keywords associative object' => [
                'openai',
                'https://api.openai.com/v1',
                '*/chat/completions',
                ['choices' => [['message' => ['content' => '{"title":"T","description":"D","keywords":{"first":"ok"},"location":"L"}']]]],
            ],
            'LM Studio wrong content type' => [
                'lmstudio',
                'http://127.0.0.1:1234/v1',
                '*/chat/completions',
                ['choices' => [['message' => ['content' => 1]]]],
            ],
            'Anthropic missing text' => [
                'anthropic',
                'https://api.anthropic.com/v1',
                '*/messages',
                ['content' => [[]]],
            ],
            'Anthropic wrong text type' => [
                'anthropic',
                'https://api.anthropic.com/v1',
                '*/messages',
                ['content' => [['text' => ['PRIVATE-PROVIDER-SHAPE']]]],
            ],
        ];
    }

    public function test_normalizes_keyword_lists_to_a_clean_bounded_string(): void
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);

        $content = json_encode([
            'title' => 'Test Title',
            'description' => 'Test Description',
            'keywords' => ['  alpha  ', '', 'alpha', "beta\nvalue", 'gamma'],
            'location' => 'Vienna',
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => $content],
                ]],
            ]),
        ]);

        $result = $this->service->generateMetadataFromText('Test', '');

        $this->assertSame('alpha, beta value, gamma', $result['keywords']);
    }

    public function test_rejects_keyword_count_and_length_over_the_explicit_bounds(): void
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);

        foreach ([
            array_map(static fn (int $index): string => 'keyword-'.$index, range(1, 31)),
            [str_repeat('x', 101)],
            [implode(', ', array_fill(0, 30, str_repeat('x', 70)))],
            str_repeat('x', 2001),
        ] as $keywords) {
            $content = json_encode([
                'title' => 'Test Title',
                'description' => 'Test Description',
                'keywords' => $keywords,
                'location' => 'Vienna',
            ], JSON_THROW_ON_ERROR);

            Http::fake([
                '*/chat/completions' => Http::response([
                    'choices' => [[
                        'message' => ['content' => $content],
                    ]],
                ]),
            ]);

            try {
                $this->service->generateMetadataFromText('Test', '');
                $this->fail('Expected bounded keyword validation to reject the provider response.');
            } catch (\RuntimeException $e) {
                $this->assertSame('AI API Fehler: 200', $e->getMessage());
            }
        }
    }

    public function test_generate_metadata_from_text_returns_status_only_error_on_invalid_json()
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'invalid json'],
                ]],
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI API Fehler: 200');

        $this->service->generateMetadataFromText('Test', '');
    }

    public function test_generate_metadata_uses_storage_and_returns_result()
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o',
        ]]);

        Storage::fake('photos');
        $sampleContent = file_get_contents(__DIR__.'/../Fixtures/sample.jpg');

        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $user = User::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'user_id' => $user->id,
        ]);
        Storage::disk('photos')->put($gallery->id.'/'.$photo->filename, $sampleContent);

        $this->assertTrue(Storage::disk('photos')->exists($gallery->id.'/'.$photo->filename));

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "Mountain View", "description": "Beautiful mountains", "keywords": "mountain, nature", "location": "Alps", "detected_city": "Innsbruck"}',
                    ],
                ]],
            ]),
        ]);

        $result = $this->service->generateMetadata($photo, 'Nature', 'A mountain');

        $this->assertEquals('Mountain View', $result['title']);
        $this->assertEquals('Beautiful mountains', $result['description']);
        $this->assertEquals('mountain, nature', $result['keywords']);
        $this->assertEquals('Alps', $result['location']);
        $this->assertEquals('Innsbruck', $result['detected_city']);

        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded, 'No HTTP requests were recorded');
    }

    public function test_generate_metadata_throws_when_image_not_found()
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'test-key',
        ]]);

        Storage::fake('photos');

        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $user = User::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image file not found on disk');

        $this->service->generateMetadata($photo, '', null);
    }
}
