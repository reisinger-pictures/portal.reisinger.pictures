<?php
namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Photo;
use App\Models\Gallery;
use App\Models\User;
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

    public function test_is_available_returns_false_when_disabled()
    {
        config(['services.ai.enabled' => false]);
        $this->assertFalse($this->service->isAvailable());
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

    public function test_generate_metadata_from_text_makes_correct_api_call()
    {
        config(['services.ai' => [
            'enabled' => true,
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
