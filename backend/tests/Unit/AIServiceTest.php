<?php
namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Photo;
use App\Models\Gallery;
use App\Models\User;
use App\AI\Contracts\AIProvider;
use App\AI\Providers\OpenAIProvider;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LMStudioProvider;
use App\Services\AIProviderFactory;
use App\Services\AIService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        $provider = new OpenAIProvider();
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
        $provider = new AnthropicProvider();
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
        $provider = new AnthropicProvider();
        $content = $provider->parseResponse([
            'content' => [
                ['text' => '{"title": "Test"}']
            ]
        ]);
        $this->assertEquals('{"title": "Test"}', $content);
    }

    public function test_lmstudio_provider_omits_auth_when_no_key()
    {
        config(['services.ai.api_key' => '']);
        $provider = new LMStudioProvider();
        $headers = $provider->buildHeaders();

        $this->assertArrayNotHasKey('Authorization', $headers);
        $this->assertEquals('/chat/completions', $provider->getEndpoint());
        $this->assertFalse($provider->supportsJsonMode());
    }

    public function test_lmstudio_provider_includes_auth_when_key_set()
    {
        config(['services.ai.api_key' => 'lm-key']);
        $provider = new LMStudioProvider();
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
                        'content' => '{"title": "Test Title", "description": "Test Description", "keywords": "key1, key2", "location": "Vienna"}'
                    ]
                ]]
            ])
        ]);

        $result = $this->service->generateMetadataFromText('A photo of a mountain', 'Summer 2024');

        $this->assertEquals('Test Title', $result['title']);
        $this->assertEquals('Test Description', $result['description']);
        $this->assertEquals('key1, key2', $result['keywords']);
        $this->assertEquals('Vienna', $result['location']);
        $this->assertEquals('', $result['detected_city']);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
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

    public function test_generate_metadata_from_text_throws_on_invalid_json()
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
                    'message' => ['content' => 'invalid json']
                ]]
            ])
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI response is not valid JSON');

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
        $sampleContent = file_get_contents(__DIR__ . '/../Fixtures/sample.jpg');

        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $user = User::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'user_id' => $user->id,
        ]);
        Storage::disk('photos')->put($gallery->id . '/' . $photo->filename, $sampleContent);

        $this->assertTrue(Storage::disk('photos')->exists($gallery->id . '/' . $photo->filename));

        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"title": "Mountain View", "description": "Beautiful mountains", "keywords": "mountain, nature", "location": "Alps", "detected_city": "Innsbruck"}'
                    ]
                ]]
            ])
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
