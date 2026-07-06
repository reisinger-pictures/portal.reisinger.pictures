<?php

namespace Tests\Unit\Services;

use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LMStudioProvider;
use App\AI\Providers\OpenAIProvider;
use App\Services\AIProviderFactory;
use Tests\TestCase;

class AIProviderFactoryTest extends TestCase
{
    private AIProviderFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = app(AIProviderFactory::class);
    }

    public function test_default_returns_openai_provider_when_type_is_null()
    {
        config(['services.ai.type' => null]);

        $provider = $this->factory->make();

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
    }

    public function test_default_returns_openai_provider_when_type_is_missing()
    {
        config(['services.ai.type' => '']);

        $provider = $this->factory->make();

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
    }

    public function test_returns_anthropic_provider()
    {
        config(['services.ai.type' => 'anthropic']);

        $provider = $this->factory->make();

        $this->assertInstanceOf(AnthropicProvider::class, $provider);
    }

    public function test_returns_lmstudio_provider()
    {
        config(['services.ai.type' => 'lmstudio']);

        $provider = $this->factory->make();

        $this->assertInstanceOf(LMStudioProvider::class, $provider);
    }

    public function test_returns_openai_provider_for_unknown_type()
    {
        config(['services.ai.type' => 'nonexistent-provider']);

        $provider = $this->factory->make();

        $this->assertInstanceOf(OpenAIProvider::class, $provider);
    }

    public function test_each_provider_implements_ai_provider_interface()
    {
        config(['services.ai.type' => 'anthropic']);
        $this->assertInstanceOf(
            \App\AI\Contracts\AIProvider::class,
            $this->factory->make()
        );

        config(['services.ai.type' => 'lmstudio']);
        $this->assertInstanceOf(
            \App\AI\Contracts\AIProvider::class,
            $this->factory->make()
        );

        config(['services.ai.type' => 'openai']);
        $this->assertInstanceOf(
            \App\AI\Contracts\AIProvider::class,
            $this->factory->make()
        );
    }
}
