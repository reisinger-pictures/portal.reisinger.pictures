<?php

namespace Tests\Unit;

use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\OpenAIProvider;
use App\Models\Photo;
use App\Services\AIService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression for the Anthropic image format: /messages expects
 * {"type":"image","source":{...}} but the service builds OpenAI-shaped
 * {"type":"image_url","image_url":{"url":"data:..."}} blocks. Passing the
 * OpenAI shape through made every image call fail with a 400.
 */
class AnthropicProviderImagePayloadTest extends TestCase
{
    public function test_it_converts_data_uri_image_blocks_to_anthropic_format(): void
    {
        $base64 = base64_encode('fake-jpeg-bytes');

        $provider = new AnthropicProvider;
        $request = $provider->buildRequest('claude-3-5-sonnet-20241022', [
            ['role' => 'system', 'content' => 'You are a bot'],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Describe this image'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.$base64]],
            ]],
        ]);

        $this->assertCount(1, $request['messages']);
        $content = $request['messages'][0]['content'];

        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('Describe this image', $content[0]['text']);

        $this->assertSame('image', $content[1]['type']);
        $this->assertSame('base64', $content[1]['source']['type']);
        $this->assertSame('image/jpeg', $content[1]['source']['media_type']);
        $this->assertSame($base64, $content[1]['source']['data']);
        $this->assertArrayNotHasKey('image_url', $content[1]);
    }

    public function test_it_converts_plain_url_image_blocks_to_anthropic_format(): void
    {
        $provider = new AnthropicProvider;
        $request = $provider->buildRequest('claude-3-5-sonnet-20241022', [
            ['role' => 'user', 'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/photo.png']],
            ]],
        ]);

        $content = $request['messages'][0]['content'];
        $this->assertSame('image', $content[0]['type']);
        $this->assertSame('url', $content[0]['source']['type']);
        $this->assertSame('https://example.com/photo.png', $content[0]['source']['url']);
    }

    public function test_openai_provider_still_passes_image_url_blocks_through(): void
    {
        $provider = new OpenAIProvider;
        $request = $provider->buildRequest('gpt-4o', [
            ['role' => 'user', 'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,abc']],
            ]],
        ]);

        $this->assertSame('image_url', $request['messages'][0]['content'][0]['type']);
        $this->assertSame('data:image/jpeg;base64,abc', $request['messages'][0]['content'][0]['image_url']['url']);
    }

    public function test_aiservice_sends_anthropic_image_source_to_the_provider(): void
    {
        config(['services.ai' => [
            'enabled' => true,
            'type' => 'anthropic',
            'base_url' => 'https://api.anthropic.com/v1',
            'api_key' => 'test-key',
            'model' => 'claude-3-5-sonnet-20241022',
        ]]);

        $this->useTemporaryStorageDisk('photos');
        $photo = new Photo;
        $photo->setAttribute('gallery_id', 'gallery-1');
        $photo->setAttribute('filename', 'sample.jpg');
        Storage::disk('photos')->put(
            'gallery-1/sample.jpg',
            file_get_contents(__DIR__.'/../Fixtures/sample.jpg')
        );

        Http::fake([
            '*/messages' => Http::response([
                'content' => [[
                    'text' => '{"title":"AI Title","description":"AI Description","keywords":"key1, key2","location":"Vienna","detected_city":"Vienna"}',
                ]],
            ]),
        ]);

        $result = app(AIService::class)->generateMetadata($photo, 'Nature', 'A mountain');

        $this->assertSame('AI Title', $result['title']);
        Http::assertSent(function (Request $request): bool {
            $body = json_decode($request->body(), true);
            $content = $body['messages'][0]['content'] ?? [];
            $image = $content[1] ?? [];

            return ($image['type'] ?? null) === 'image'
                && ($image['source']['type'] ?? null) === 'base64'
                && ($image['source']['media_type'] ?? null) === 'image/jpeg'
                && is_string($image['source']['data'] ?? null)
                && $image['source']['data'] !== ''
                && ! array_key_exists('image_url', $image);
        });
    }
}
