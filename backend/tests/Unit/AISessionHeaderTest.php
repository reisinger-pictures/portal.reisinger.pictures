<?php
namespace Tests\Unit;

use Tests\TestCase;
use App\AI\Providers\OpenAIProvider;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LMStudioProvider;

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
        $headers = (new OpenAIProvider())->buildHeaders();

        $this->assertArrayNotHasKey('x-opencode-session', $headers);
        $this->assertEquals('Bearer test-key', $headers['Authorization']);
    }

    public function test_openai_provider_sets_prefixed_session_header()
    {
        $headers = (new OpenAIProvider())->buildHeaders('abc-123');

        $this->assertEquals('portal-abc-123', $headers['x-opencode-session']);
    }

    public function test_openai_provider_sanitizes_and_clamps_session_value()
    {
        $headers = (new OpenAIProvider())->buildHeaders('a b/c+d!' . str_repeat('x', 200));

        $value = $headers['x-opencode-session'];
        $this->assertEquals(128, strlen($value));
        $this->assertStringStartsWith('portal-a-b-c-d-', $value);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]+$/', $value);
    }

    public function test_session_header_name_is_configurable()
    {
        config(['services.ai.session_header' => 'x-custom-session']);

        $headers = (new OpenAIProvider())->buildHeaders('sess-1');

        $this->assertArrayNotHasKey('x-opencode-session', $headers);
        $this->assertEquals('portal-sess-1', $headers['x-custom-session']);
        $this->assertEquals('x-custom-session', (new OpenAIProvider())->sessionHeaderName());
    }

    public function test_empty_session_header_disables_feature()
    {
        config(['services.ai.session_header' => '']);

        $provider = new OpenAIProvider();

        $this->assertNull($provider->sessionHeaderName());
        $this->assertArrayNotHasKey('x-opencode-session', $provider->buildHeaders('sess-1'));
    }

    public function test_anthropic_provider_sets_session_header()
    {
        $headers = (new AnthropicProvider())->buildHeaders('batch-42');

        $this->assertEquals('portal-batch-42', $headers['x-opencode-session']);
        $this->assertEquals('test-key', $headers['x-api-key']);
    }

    public function test_lmstudio_provider_never_sends_session_header()
    {
        $provider = new LMStudioProvider();

        $this->assertNull($provider->sessionHeaderName());
        $this->assertArrayNotHasKey('x-opencode-session', $provider->buildHeaders('sess-1'));
    }
}
