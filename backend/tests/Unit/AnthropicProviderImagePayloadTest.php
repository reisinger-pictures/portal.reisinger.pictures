<?php

namespace Tests\Unit;

use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\OpenAIProvider;
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
}
