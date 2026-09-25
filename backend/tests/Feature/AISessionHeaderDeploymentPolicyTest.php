<?php

namespace Tests\Feature;

use Tests\TestCase;

class AISessionHeaderDeploymentPolicyTest extends TestCase
{
    public function test_production_compose_uses_config_defaults_and_rejects_invalid_session_values(): void
    {
        $compose = $this->read('deployment/docker-compose.yml');

        $this->assertStringContainsString(
            '- AI_SESSION_HEADER=${AI_SESSION_HEADER-x-opencode-session}',
            $compose
        );
        $this->assertStringContainsString(
            '- AI_SESSION_PREFIX=${AI_SESSION_PREFIX-portal-}',
            $compose
        );
        $this->assertStringNotContainsString('- AI_SESSION_HEADER=${AI_SESSION_HEADER}', $compose);
        $this->assertStringNotContainsString('- AI_SESSION_PREFIX=${AI_SESSION_PREFIX}', $compose);

        $this->assertStringContainsString('case \\"$${AI_SESSION_HEADER}\\" in', $compose);
        $this->assertStringContainsString('*[!A-Za-z0-9-]*)', $compose);
        $this->assertStringContainsString('case \\"$${AI_SESSION_PREFIX}\\" in', $compose);
        $this->assertStringContainsString('*[!A-Za-z0-9._-]*)', $compose);
        $this->assertSame(2, substr_count($compose, "'') ;;"));
        $this->assertSame(2, substr_count($compose, "(fail-closed)!'; exit 1;;"));

        $headerGuard = strpos($compose, 'case \\"$${AI_SESSION_HEADER}\\" in');
        $prefixGuard = strpos($compose, 'case \\"$${AI_SESSION_PREFIX}\\" in');
        $applicationBootstrap = strpos($compose, 'php artisan cache:clear;');

        $this->assertNotFalse($headerGuard);
        $this->assertNotFalse($prefixGuard);
        $this->assertNotFalse($applicationBootstrap);
        $this->assertLessThan($applicationBootstrap, $headerGuard);
        $this->assertLessThan($applicationBootstrap, $prefixGuard);
    }

    public function test_tracked_ai_config_and_env_template_share_the_documented_defaults(): void
    {
        $config = $this->read('backend/config/services.php');
        $envExample = $this->read('backend/.env.example');

        $this->assertStringContainsString(
            "'session_header' => env('AI_SESSION_HEADER', 'x-opencode-session')",
            $config
        );
        $this->assertStringContainsString(
            "'session_prefix' => env('AI_SESSION_PREFIX', 'portal-')",
            $config
        );
        $this->assertMatchesRegularExpression('/^AI_SESSION_HEADER=x-opencode-session$/m', $envExample);
        $this->assertMatchesRegularExpression('/^AI_SESSION_PREFIX=portal-$/m', $envExample);
        $this->assertStringContainsString('An explicit empty value disables the header', $envExample);
    }

    public function test_user_agent_remains_hardcoded_and_not_deployment_configurable(): void
    {
        $compose = $this->read('deployment/docker-compose.yml');
        $servicesConfig = $this->read('backend/config/services.php');
        $userAgent = $this->read('backend/app/AI/Concerns/HasUserAgent.php');

        $this->assertStringNotContainsString('AI_USER_AGENT', $compose);
        $this->assertStringNotContainsString("'user_agent'", $servicesConfig);
        $this->assertStringContainsString(
            "\$headers['User-Agent'] = 'reisinger.pictures Portal';",
            $userAgent
        );
    }

    private function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 3).'/'.$relativePath;
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }
}
