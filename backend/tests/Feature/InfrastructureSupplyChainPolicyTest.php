<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class InfrastructureSupplyChainPolicyTest extends TestCase
{
    private const ACTION_SHA_PATTERN = '/^[^@\s]+@[0-9a-f]{40}$/';

    private const IMAGE_DIGEST_PATTERN = '/@sha256:[0-9a-f]{64}$/';

    private const MEILISEARCH_IMAGE = 'getmeili/meilisearch:v1.48.3@sha256:c1a52f17c759c2cd6349eede3d5108b8dac07b97e10665b1d64a2d4961c2fd29';

    /**
     * @var list<string>
     */
    private const COMPOSE_FILES = [
        'deployment/docker-compose.yml',
        'docker-compose.local.yml',
        'docker-compose.test.yml',
    ];

    public function test_github_actions_are_pinned_to_full_commit_shas(): void
    {
        $actionCount = 0;

        foreach ($this->workflowFiles() as $workflow) {
            $contents = $this->read($workflow);
            preg_match_all('/^\s*(?:-\s*)?uses:\s*([^\s#]+)/m', $contents, $matches);

            foreach ($matches[1] as $reference) {
                if (str_starts_with($reference, './')) {
                    continue;
                }

                $this->assertMatchesRegularExpression(
                    self::ACTION_SHA_PATTERN,
                    $reference,
                    "{$workflow} must pin the action to a full commit SHA"
                );
                $actionCount++;
            }
        }

        $this->assertGreaterThan(0, $actionCount);
    }

    public function test_deployment_and_ci_container_images_are_digest_pinned(): void
    {
        $references = [];

        foreach ($this->workflowFiles() as $workflow) {
            $contents = $this->withoutFullLineComments($this->read($workflow));
            preg_match_all('/^\s*image:\s*([^\s#]+)/m', $contents, $matches);
            array_push($references, ...$matches[1]);

            // Images passed directly to `docker run` are not YAML `image:` keys.
            preg_match_all('/(?:ghcr\.io|docker\.io)\/[^\s"\']+/', $contents, $registryMatches);
            array_push($references, ...$registryMatches[0]);
            preg_match_all('/(?<![\w.\/-])composer:[^\s"\']+/', $contents, $composerMatches);
            array_push($references, ...$composerMatches[0]);
        }

        foreach (self::COMPOSE_FILES as $composeFile) {
            $compose = $this->read($composeFile);
            preg_match_all('/^\s*image:\s*([^\s#]+)/m', $compose, $composeMatches);
            array_push($references, ...$composeMatches[1]);
        }

        foreach (['docker-compose.local.yml', 'docker-compose.test.yml'] as $composeFile) {
            $this->assertStringContainsString(
                'image: '.self::MEILISEARCH_IMAGE,
                $this->read($composeFile),
                "{$composeFile} must use the same pinned Meilisearch image as deployment"
            );
        }

        foreach (['deployment/Dockerfile', 'deployment/Dockerfile.e2e'] as $dockerfile) {
            $contents = $this->read($dockerfile);
            preg_match_all('/^FROM\s+(\S+)/m', $contents, $fromMatches);
            preg_match_all('/^COPY\s+--from=(\S+)/m', $contents, $copyMatches);
            array_push($references, ...$fromMatches[1], ...$copyMatches[1]);
        }

        $this->assertNotEmpty($references);
        foreach (array_unique($references) as $reference) {
            $this->assertMatchesRegularExpression(
                self::IMAGE_DIGEST_PATTERN,
                $reference,
                'Container images must use an immutable sha256 digest'
            );
        }
    }

    public function test_e2e_node_runtime_download_is_version_pinned_and_checksummed(): void
    {
        $dockerfile = $this->read('deployment/Dockerfile.e2e');

        $this->assertStringNotContainsString('latest-v', $dockerfile);
        $this->assertMatchesRegularExpression('/^ARG NODE_VERSION=\d+\.\d+\.\d+$/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^ARG NODE_SHA256=[0-9a-f]{64}$/m', $dockerfile);
        $this->assertStringContainsString(
            'https://nodejs.org/dist/v${NODE_VERSION}/node-v${NODE_VERSION}-linux-x64.tar.xz',
            $dockerfile
        );
        $this->assertStringContainsString('sha256sum -c --strict -', $dockerfile);
    }

    public function test_photo_storage_path_configuration_is_absolute_and_fail_closed(): void
    {
        $config = $this->read('backend/config/filesystems.php');

        $this->assertStringContainsString("env('PHOTO_STORAGE_PATH'", $config);
        $this->assertStringContainsString("trim(\$photoStoragePath) === ''", $config);
        $this->assertStringContainsString("str_starts_with(\$photoStoragePath, '/')", $config);
        $this->assertStringContainsString('InvalidArgumentException', $config);

        foreach (['backend/.env.example', 'backend/.env.ci'] as $envFile) {
            $contents = $this->read($envFile);
            $this->assertMatchesRegularExpression(
                '/^PHOTO_STORAGE_PATH=\/[^\r\n]+$/m',
                $contents,
                "{$envFile} must provide an absolute photo storage path"
            );
        }
    }

    public function test_photo_storage_config_rejects_empty_and_relative_paths_at_runtime(): void
    {
        $backendPath = $this->projectPath('backend');
        $configCachePath = sys_get_temp_dir().'/portal-config-cache-'.bin2hex(random_bytes(8)).'.php';
        $script = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo config('filesystems.disks.photos.root');
PHP;

        try {
            foreach (['', 'photos'] as $invalidPath) {
                $process = new Process(
                    [PHP_BINARY, '-r', $script, $backendPath],
                    null,
                    [
                        'APP_CONFIG_CACHE' => $configCachePath,
                        'PHOTO_STORAGE_PATH' => $invalidPath,
                    ]
                );
                $process->run();

                $this->assertFalse($process->isSuccessful());
                $output = $process->getOutput().$process->getErrorOutput();
                $this->assertStringContainsString(
                    'PHOTO_STORAGE_PATH must be a non-empty absolute path.',
                    $output
                );
            }

            $process = new Process(
                [PHP_BINARY, '-r', $script, $backendPath],
                null,
                [
                    'APP_CONFIG_CACHE' => $configCachePath,
                    'PHOTO_STORAGE_PATH' => '/var/www/photos',
                ]
            );
            $process->run();

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('/var/www/photos', trim($process->getOutput()));
        } finally {
            @unlink($configCachePath);
        }
    }

    public function test_backend_runtime_and_startup_gate_are_non_root_and_fail_closed(): void
    {
        $compose = $this->read('deployment/docker-compose.yml');
        $dockerfile = $this->read('deployment/Dockerfile');
        $e2eDockerfile = $this->read('deployment/Dockerfile.e2e');

        $this->assertSame(2, substr_count($compose, 'user: "1000:1000"'));
        $this->assertStringContainsString('USER www-data', $dockerfile);
        $this->assertStringContainsString('USER www-data', $e2eDockerfile);
        $this->assertStringContainsString('stat -c \'%u:%g\'', $compose);

        foreach (['APP_KEY', 'JWT_SECRET', 'FILE_ENCRYPTION_KEY', 'ADMIN_EMAIL', 'ADMIN_PASSWORD', 'PHOTO_STORAGE_PATH'] as $variable) {
            $this->assertStringContainsString('$${'.$variable.'}', $compose);
        }
        $this->assertStringContainsString('case \"$${PHOTO_STORAGE_PATH}\" in', $compose);
        $this->assertStringContainsString('/*) ;;', $compose);
        $this->assertStringContainsString(
            'php artisan migrate --force && php artisan db:seed --force && php artisan admin:update || exit 1',
            $compose
        );
        $this->assertStringNotContainsString('app:import-locations', $compose);
    }

    /**
     * @return list<string>
     */
    private function workflowFiles(): array
    {
        $files = glob($this->projectPath('.github/workflows/*.{yml,yaml}'), GLOB_BRACE);
        $this->assertNotFalse($files);
        sort($files);

        return array_values($files);
    }

    private function read(string $relativePath): string
    {
        $path = $this->projectPath($relativePath);
        $this->assertFileExists($path);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }

    private function withoutFullLineComments(string $contents): string
    {
        return preg_replace('/^\s*#.*$/m', '', $contents) ?? $contents;
    }

    private function projectPath(string $relativePath = ''): string
    {
        $root = dirname(__DIR__, 3);
        if ($relativePath === '') {
            return $root;
        }

        return str_starts_with($relativePath, '/')
            ? $relativePath
            : $root.'/'.$relativePath;
    }
}
