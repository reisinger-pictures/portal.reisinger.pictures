<?php

namespace Tests\Unit;

use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LMStudioProvider;
use App\AI\Providers\OpenAIProvider;
use Tests\TestCase;

class AISessionHeaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.ai.api_key' => 'test-key',
            'services.ai.session_header' => 'x-opencode-session',
            'services.ai.session_prefix' => 'portal-',
        ]);
    }

    public function test_openai_provider_omits_session_header_without_session_id()
    {
        $headers = (new OpenAIProvider)->buildHeaders();

        $this->assertArrayNotHasKey('x-opencode-session', $headers);
        $this->assertEquals('Bearer test-key', $headers['Authorization']);
    }

    public function test_openai_provider_sets_prefixed_session_header()
    {
        $headers = (new OpenAIProvider)->buildHeaders('abc-123');

        $this->assertEquals('portal-abc-123', $headers['x-opencode-session']);
    }

    public function test_openai_provider_sanitizes_and_clamps_session_value()
    {
        $headers = (new OpenAIProvider)->buildHeaders('a b/c+d!'.str_repeat('x', 200));

        $value = $headers['x-opencode-session'];
        $this->assertEquals(128, strlen($value));
        $this->assertStringStartsWith('portal-a-b-c-d-', $value);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $value);
    }

    public function test_configured_session_prefix_is_sanitized_including_crlf()
    {
        config(['services.ai.session_prefix' => "portal-\r\nX-Injected: yes"]);

        $value = (new OpenAIProvider)->buildHeaders('batch-42')['x-opencode-session'];

        $this->assertStringNotContainsString("\r", $value);
        $this->assertStringNotContainsString("\n", $value);
        $this->assertStringStartsWith('portal-', $value);
        $this->assertStringEndsWith('batch-42', $value);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9._-]+\z/', $value);
    }

    public function test_overlong_session_prefix_is_still_bounded_by_final_value_limit()
    {
        config(['services.ai.session_prefix' => str_repeat('p', 200)]);

        $value = (new OpenAIProvider)->buildHeaders('batch-42')['x-opencode-session'];

        $this->assertSame(str_repeat('p', 128), $value);
    }

    public function test_invalid_configured_session_header_names_are_rejected()
    {
        foreach (['invalid header', "x-session\r\nX-Injected: yes", 'x:session'] as $name) {
            config(['services.ai.session_header' => $name]);

            $provider = new OpenAIProvider;
            $headers = $provider->buildHeaders('batch-42');

            $this->assertNull($provider->sessionHeaderName());
            $this->assertArrayNotHasKey('x-opencode-session', $headers);
            $this->assertSame('reisinger.pictures Portal', $headers['User-Agent']);
        }
    }

    public function test_session_header_cannot_replace_an_existing_provider_header()
    {
        config(['services.ai.session_header' => 'authorization']);

        $provider = new OpenAIProvider;
        $headers = $provider->buildHeaders('batch-42');

        $this->assertNull($provider->sessionHeaderName());
        $this->assertSame('Bearer test-key', $headers['Authorization']);
        $this->assertArrayNotHasKey('authorization', $headers);
        $this->assertSame('reisinger.pictures Portal', $headers['User-Agent']);
    }

    public function test_session_header_cannot_shadow_the_hardcoded_user_agent()
    {
        config(['services.ai.session_header' => 'user-agent']);

        $provider = new OpenAIProvider;
        $headers = $provider->buildHeaders('batch-42');

        $this->assertNull($provider->sessionHeaderName());
        $this->assertArrayNotHasKey('user-agent', $headers);
        $this->assertSame('reisinger.pictures Portal', $headers['User-Agent']);
    }

    public function test_empty_session_prefix_keeps_the_batch_session_identifier()
    {
        config(['services.ai.session_prefix' => '']);

        $headers = (new OpenAIProvider)->buildHeaders('batch-42');

        $this->assertSame('batch-42', $headers['x-opencode-session']);
    }

    public function test_session_header_name_is_configurable()
    {
        config(['services.ai.session_header' => 'x-custom-session']);

        $headers = (new OpenAIProvider)->buildHeaders('sess-1');

        $this->assertArrayNotHasKey('x-opencode-session', $headers);
        $this->assertEquals('portal-sess-1', $headers['x-custom-session']);
        $this->assertEquals('x-custom-session', (new OpenAIProvider)->sessionHeaderName());
    }

    public function test_empty_session_header_disables_feature()
    {
        config(['services.ai.session_header' => '']);

        $provider = new OpenAIProvider;

        $this->assertNull($provider->sessionHeaderName());
        $this->assertArrayNotHasKey('x-opencode-session', $provider->buildHeaders('sess-1'));
    }

    public function test_anthropic_provider_sets_session_header()
    {
        $headers = (new AnthropicProvider)->buildHeaders('batch-42');

        $this->assertEquals('portal-batch-42', $headers['x-opencode-session']);
        $this->assertEquals('test-key', $headers['x-api-key']);
    }

    public function test_lmstudio_provider_never_sends_session_header()
    {
        $provider = new LMStudioProvider;

        $this->assertNull($provider->sessionHeaderName());
        $this->assertArrayNotHasKey('x-opencode-session', $provider->buildHeaders('sess-1'));
    }

    public function test_openai_provider_sends_hardcoded_user_agent()
    {
        // UA must be independent of the session-header config (empty disables session affinity only).
        config(['services.ai.session_header' => '']);

        $headers = (new OpenAIProvider)->buildHeaders();

        $this->assertSame('reisinger.pictures Portal', $headers['User-Agent']);
    }

    public function test_anthropic_provider_sends_hardcoded_user_agent()
    {
        $headers = (new AnthropicProvider)->buildHeaders('batch-42');

        $this->assertSame('reisinger.pictures Portal', $headers['User-Agent']);
    }

    public function test_lmstudio_provider_sends_hardcoded_user_agent()
    {
        $headers = (new LMStudioProvider)->buildHeaders();

        $this->assertSame('reisinger.pictures Portal', $headers['User-Agent']);
    }
}
