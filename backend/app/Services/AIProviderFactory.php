<?php
namespace App\Services;

use App\AI\Contracts\AIProvider;
use App\AI\Providers\OpenAIProvider;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\LMStudioProvider;

class AIProviderFactory
{
    public function make(): AIProvider
    {
        return match (config('services.ai.type')) {
            'anthropic' => new AnthropicProvider(),
            'lmstudio' => new LMStudioProvider(),
            default => new OpenAIProvider(),
        };
    }
}
