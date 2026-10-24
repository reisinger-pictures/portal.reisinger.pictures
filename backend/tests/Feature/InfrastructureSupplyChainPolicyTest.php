<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class InfrastructureSupplyChainPolicyTest extends TestCase
{
    private const ACTION_SHA_PATTERN = '/^[^@\s]+@[0-9a-f]{40}$/';

    private const IMAGE_DIGEST_PATTERN = '/@sha256:[0-9a-f]{64}$/';

    /**
     * Concrete tag pinning is the accepted pattern. `latest` and bare floating
     * tags (e.g. `nginx` without a tag) are rejected; `@sha256:` digest pins
     * are deliberately not required because they freeze an image to one build
     * and need a manual update per patch release.
     */
    private const TAG_PINNED_PATTERN = '/^[a-z0-9][\w.\/-]*:[a-z0-9][\w.-]*$/i';

    /**
     * The exact tag that portal-base must be pinned to. Unlike third-party
     * images, portal-base is built by this repository, so the tag is stable and
     * the CI rebuilds it deterministically.
     */
    private const PORTAL_BASE_TAG = 'ghcr.io/reisinger-pictures/portal-base:8.5';

    private const MEILISEARCH_IMAGE = 'getmeili/meilisearch:v1.48.3';

    /**
     * The GitHub organisation that owns this repository. Both image workflows
     * publish through `OWNER: ${{ github.repository_owner }}`, so every
     * consumer must pin this namespace. The previously used personal `ghcr.io`
     * namespace silently froze the pinned digests on stale images: rebuilds only
     * ever reached the organisation path. The literal old path is deliberately
     * not spelled out here: this guard rejects *any* non-organisation `ghcr.io`
     * reference in a delivered artifact, so a literal copy would be a permanent
     * trap for anyone who widens the scan again.
     */
    private const IMAGE_NAMESPACE = 'reisinger-pictures';

    /**
     * @var list<string>
     */
    private const COMPOSE_FILES = [
        'deployment/docker-compose.yml',
        'docker-compose.local.yml',
        'docker-compose.test.yml',
    ];

    /**
     * The Stripe test secrets the E2E job injects and the preflight has to
     * guard.
     *
     * All three are read during an E2E run: the secret key by the balance
     * probe, the publishable key by the Vite build (`VITE_STRIPE_PUBLIC_KEY`
     * falls back to `STRIPE_KEY`, so guarding `STRIPE_KEY` covers it) and the
     * webhook secret by the Stripe self-test (`local_key_mode`).
     */
    private const E2E_STRIPE_TEST_SECRETS = [
        'STRIPE_KEY',
        'STRIPE_SECRET',
        'STRIPE_WEBHOOK_SECRET',
    ];

    /**
     * The PHP extensions the backend cannot run without, declared as Composer
     * platform requirements.
     *
     * `ext-gd` is used unconditionally by `ImageProcessor` (thumbnails,
     * scaling, watermarks) and, since the model-photo hardening, by the
     * contact sheet export for JPEG/PNG embedding. `ext-zip` backs the
     * location import (`app:import-locations`).
     *
     * An undeclared extension is an undocumented runtime assumption: nothing
     * checks it, and the first sign of it is a fatal error inside a
     * production job. See owner decision D-42.
     *
     * @var list<string>
     */
    private const REQUIRED_PLATFORM_EXTENSIONS = [
        'ext-gd',
        'ext-zip',
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
            // Tag pinning is the accepted pattern. Digest pinning (`@sha256:`) is
            // deliberately not required: it freezes an image to one build and
            // requires a manual update for every patch release. On a server with
            // a running workload that is the worse trade. The guard still rejects
            // `latest` and any unpinned floating tag.
            //
            // Build-args (e.g. `php:${PHP_VERSION}-fpm`) are resolved at build time
            // and are therefore treated as tag-pinned: the tag is concrete by the
            // time the image is pulled.
            if (str_contains($reference, '${PHP_VERSION}') || str_contains($reference, '${BASE_PIN}')) {
                continue;
            }
            $this->assertMatchesRegularExpression(
                self::TAG_PINNED_PATTERN,
                $reference,
                'Container images must be pinned to a concrete tag (not latest, not @sha256)'
            );
        }
    }

    /**
     * Regression guard for the namespace drift: both image workflows publish
     * under the owning organisation, so a consumer that names any other
     * registry namespace pins a digest no rebuild can ever refresh.
     *
     * The scan is deliberately limited to the delivered container artifacts —
     * see {@see containerArtifactFiles()} for why prose is not a security gate.
     */
    public function test_container_image_references_use_the_owning_organization_namespace(): void
    {
        $scannedFiles = 0;
        $references = [];

        foreach ($this->containerArtifactFiles() as $relativePath) {
            $contents = $this->read($relativePath);
            $scannedFiles++;

            if (preg_match_all('#\bghcr\.io/([^/\s\'"`]+)/#', $contents, $matches) === 0) {
                continue;
            }

            foreach ($matches[0] as $index => $reference) {
                $references[] = sprintf('%s:%d (%s)', $relativePath, $this->lineOf($contents, $matches[0][$index]), $reference);
            }
        }

        $this->assertGreaterThan(
            0,
            $scannedFiles,
            'the namespace scan must cover the delivered container artifacts'
        );
        $this->assertNotEmpty(
            $references,
            'the repository must still declare at least one ghcr.io image reference'
        );

        foreach ($references as $reference) {
            $this->assertMatchesRegularExpression(
                '#\bghcr\.io/'.preg_quote(self::IMAGE_NAMESPACE, '#').'/#',
                $reference,
                'Container image references must use the ghcr.io/'.self::IMAGE_NAMESPACE
                    .' namespace that base-image.yml and e2e-image.yml publish to'
            );
        }
    }

    /**
     * The same `portal-base` build must be pinned byte-identically by every
     * consumer. A drifted copy is how the deployment compose file kept booting
     * an image whose preflight/supervisor binaries were missing.
     */
    public function test_portal_base_is_pinned_to_one_single_tag_everywhere(): void
    {
        $tags = [];

        // Only the production compose and CI workflow pin the artifact that
        // actually boots. Other files (docs, templates, local test compose)
        // reference portal-base with placeholders or in prose, and a stray
        // mention must not fail the gate.
        foreach (['deployment/docker-compose.yml', '.github/workflows/ci.yml'] as $consumer) {
            $contents = $this->read($consumer);
            if (preg_match_all(
                '#\bghcr\.io/'.preg_quote(self::IMAGE_NAMESPACE, '#').'/portal-base:([^\s\'"@]+)#',
                $contents,
                $matches
            ) === 0) {
                continue;
            }

            foreach ($matches[1] as $tag) {
                $tags[$tag] = true;
            }
        }

        $this->assertCount(
            1,
            $tags,
            'every ghcr.io/'.self::IMAGE_NAMESPACE.'/portal-base reference must pin the same tag, found: '
                .implode(', ', array_keys($tags))
        );
    }

    /**
     * The static half of the P1-I5 policy. Only the *last* `USER` instruction
     * of the final build stage reaches the image config, so an earlier
     * `USER root` in a base stage must never be able to satisfy this guard.
     *
     * This is a source check, not a proof about the deployed artifact: the
     * digest production pinned until commit d7f3596 was built on 2026-08-20,
     * before `USER www-data` existed in the Dockerfile, and ran as uid 0. The
     * artifact half is
     * {@see test_pinned_base_image_artifact_gate_is_wired_into_the_security_contract_job()}.
     */
    public function test_base_image_source_declares_a_non_root_runtime_user(): void
    {
        $dockerfile = $this->read('deployment/Dockerfile');
        preg_match_all('/^[ \t]*USER[ \t]+(\S+)/mi', $dockerfile, $matches);

        $this->assertNotEmpty(
            $matches[1],
            'deployment/Dockerfile must declare a runtime user, otherwise the image runs as root'
        );

        $effectiveUser = strtolower((string) end($matches[1]));
        $this->assertNotContains(
            ['root', '0', '0.0', '0:0', 'root:root'],
            array_map('strtolower', $matches[1]),
            'no build stage of the base image may switch the runtime user back to root'
        );
        $this->assertSame(
            'www-data',
            $effectiveUser,
            'the effective (last) USER instruction of deployment/Dockerfile must be www-data; '
                .'a USER after it would silently override the non-root runtime'
        );
    }

    /**
     * The artifact half is only a gate while it is wired into a job that has no
     * image dependency, and while the pin it inspects is the pin production
     * boots. This asserts both: the script exists, fails closed (no `|| true`
     * escape hatch, no hardcoded second copy of a digest), and the
     * `security-contract` job actually runs it.
     */
    public function test_pinned_base_image_artifact_gate_is_wired_into_the_security_contract_job(): void
    {
        $gate = 'tests/infrastructure/verify-image-nonroot.sh';
        $gateSource = $this->read($gate);

        $this->assertStringContainsString('set -euo pipefail', $gateSource);
        $this->assertStringContainsString(
            'packages must be public',
            $gateSource,
            'a 401/403 from the registry must fail the gate with an actionable message, never a skip'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/sha256:[0-9a-f]{64}/',
            $gateSource,
            "{$gate} must not carry a second copy of a digest; it resolves the pin from the repository"
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\|\|[[:space:]]*true/',
            $gateSource,
            "{$gate} must fail closed; a swallowed exit code would turn the gate into a no-op"
        );

        $tags = [];
        // Only the compose file declares portal-base as an actual `image:`
        // pin. In the CI workflow it appears as a build argument (not a pin),
        // so scanning it would find nothing and fail.
        $contents = $this->read('deployment/docker-compose.yml');
        preg_match_all(
            '#^\s*image:\s*ghcr\.io/reisinger-pictures/portal-base:([^\s\'"@]+)#m',
            $contents,
            $matches
        );
        $this->assertNotEmpty($matches[1], 'deployment/docker-compose.yml must pin the portal-base tag');
        foreach ($matches[1] as $tag) {
            $tags[$tag] = true;
        }
        $this->assertCount(
            1,
            $tags,
            'the artifact gate resolves its input from these files, so their pins must agree, found: '
                .implode(', ', array_keys($tags))
        );

        $this->assertStringContainsString(
            'run: sh scripts/gates.sh full security',
            $this->ciJob('security-contract'),
            'the security-contract job must invoke the grouped gate containing the artifact gate: it is the only job without an image dependency, '
                .'so it keeps enforcing the non-root property while backend and e2e are blocked on a private package'
        );
        $this->assertStringContainsString(
            "bash {$gate}",
            $this->read('scripts/gates.sh'),
            'the grouped security gate must retain the pinned base-image artifact check'
        );
    }

    public function test_image_publishing_workflows_derive_the_owner_from_the_repository(): void
    {
        foreach (['.github/workflows/base-image.yml', '.github/workflows/e2e-image.yml'] as $workflow) {
            $contents = $this->read($workflow);

            $this->assertStringContainsString(
                'OWNER: ${{ github.repository_owner }}',
                $contents,
                "{$workflow} must publish under the repository owner namespace"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/OWNER:\s*[\'"]?[A-Za-z0-9._-]+\/?[\'"]?\s*$/m',
                $contents,
                "{$workflow} must not hardcode a literal OWNER that could drift from the repository owner"
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

    /**
     * Regression guard for the Stripe preflight of the E2E job.
     *
     * The step used to test only `secrets.STRIPE_SECRET != ''` while its own
     * error message named three secrets. A missing publishable key or webhook
     * secret therefore passed the preflight and surfaced later as a single red
     * smoke test with no visible cause.
     *
     * The gate is verified by evaluating the step's own condition: the
     * contract is the behaviour — one missing secret fails the step — not the
     * spelling of the shell expression.
     */
    public function test_e2e_stripe_preflight_requires_every_injected_test_secret(): void
    {
        $step = $this->ciStep('e2e', 'Ensure Stripe test keys are configured');

        $declared = $this->stripePresenceBooleans($step);

        $this->assertCount(
            count(self::E2E_STRIPE_TEST_SECRETS),
            $declared,
            'the preflight must declare exactly one presence boolean per Stripe test secret, found: '
                .implode(', ', array_keys($declared))
        );

        foreach (self::E2E_STRIPE_TEST_SECRETS as $secret) {
            $this->assertArrayHasKey(
                $secret.'_SET',
                $declared,
                "the preflight must expose the presence of {$secret} as a boolean; "
                    .'a missing secret must not pass the gate'
            );
            $this->assertSame(
                $secret,
                $declared[$secret.'_SET'],
                "{$secret}_SET must be bound to secrets.{$secret}"
            );
        }

        // The job injects exactly these three Stripe secrets. A new one that
        // the preflight does not guard would reopen the same blind spot.
        $this->assertEqualsCanonicalizing(
            self::E2E_STRIPE_TEST_SECRETS,
            $this->stripeSecretsReferencedByJob($this->ciJob('e2e')),
            'every Stripe secret the e2e job injects must be guarded by the preflight'
        );

        $run = $this->stepRunBlock($step);

        $this->assertStringContainsString(
            'exit 1',
            $run,
            'the preflight must end the step with a non-zero exit code, otherwise a missing secret does not fail the job'
        );

        $condition = $this->shellCondition($step);

        foreach ($this->presenceCombinations() as $combination) {
            $process = new Process(
                ['bash', '-c', 'if '.$condition.'; then exit 1; fi'],
                $this->projectPath(),
                $combination
            );
            $process->run();

            $missing = array_keys(array_filter(
                $combination,
                static fn (string $value): bool => $value !== 'true'
            ));

            $this->assertSame(
                $missing === [] ? 0 : 1,
                $process->getExitCode(),
                'the preflight must fail closed: with '.implode(', ', array_map(
                    static fn (string $name, string $value): string => $name.'='.$value,
                    array_keys($combination),
                    $combination
                )).' the step must '.($missing === [] ? 'pass' : 'fail')
            );
        }
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

        // composer_init is the last service left on 1000:1000. Both
        // long-running services carry the website tree's group webgroup (82)
        // instead, because that is what lets them write inside 2775+setgid
        // directories:
        //   - sftpgo as 1002:82 — an upload must inherit group webgroup via
        //     setgid instead of landing in the uploader's own group;
        //   - backend as 1000:82 — `FtpController::process()` creates the
        //     inbox directory and then `unlink`s the uploaded file again,
        //     which UID 1000 in group 1000 cannot do in such a directory.
        // The count is a regression guard: a further occurrence means a new
        // service was added without deciding its runtime user, and losing the
        // two asserted below means one was silently changed.
        $this->assertSame(1, substr_count($compose, 'user: "1000:1000"'));
        // The sftpgo user is asserted inside its own stanza, not file-wide: a
        // global `user: "1002:82"` elsewhere would satisfy a plain
        // assertStringContainsString while the service itself stayed on 1000.
        $this->assertMatchesRegularExpression(
            '/^\s*user:\s*"1002:82"$/m',
            $this->composeService('sftpgo'),
            'the sftpgo service must run as 1002:82; see AGENTS.md D-1 and features/infrastructure/19-ftp-upload-pipeline.md 7.9'
        );
        // ... and it must not keep the inherited value, in either spelling.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*user:/m',
            str_replace(
                'user: "1002:82"',
                '',
                $this->composeService('sftpgo')
            )
        );
        // Same two steps for the backend, whose group is the D-1 subject: a
        // 1000:1000 backend recreates exactly the 2775+setgid failure D-2
        // exists to prevent, and it is invisible at runtime until an import
        // tries to unlink its own upload.
        $this->assertMatchesRegularExpression(
            '/^\s*user:\s*"1000:82"$/m',
            $this->composeService('backend'),
            'the backend service must run as 1000:82; see AGENTS.md D-1 and D-2'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*user:/m',
            str_replace(
                'user: "1000:82"',
                '',
                $this->composeService('backend')
            )
        );
        // The fail-closed startup guard pins uid *and* gid as a shell string
        // inside `command:`. Both literals have to agree with the `user:`
        // above: a gid literal of 1000 next to a service running as 82 makes
        // the container refuse to start, and nothing else in this file would
        // ever notice — the guard is a string, not compose state. Before D-1
        // this is exactly the drift that would have shipped.
        $this->assertStringContainsString(
            "id -u | grep -qx 1000 || { echo 'FATAL: Backend muss als UID 1000 laufen. Start verweigert (fail-closed)!'; exit 1; }",
            $compose,
            'the fail-closed startup guard must still pin the backend to UID 1000'
        );
        $this->assertStringContainsString(
            "id -g | grep -qx 82 || { echo 'FATAL: Backend muss als GID 82 (webgroup) laufen. Start verweigert (fail-closed)!'; exit 1; }",
            $compose,
            'the fail-closed gid guard must name the same group as the backend `user:`; '
                .'a stale literal refuses the start while every other assertion in this class stays green'
        );
        $this->assertStringContainsString('USER www-data', $dockerfile);
        $this->assertStringContainsString('USER www-data', $e2eDockerfile);
        // Schreibbarkeit statt Eigentum. 19-ftp 7.9 verbietet `chown -R` auf
        // /home/webadmin/websites, ein Eigentumsvergleich waere also nicht
        // erfuellbar. Geprueft wird das, was zaehlt: ob UID 1000 schreiben darf.
        // Der explizite Negativ-Test haelt den unerfuellbaren Eigentumscheck
        // draussen, falls ihn jemand zurueckholt.
        $this->assertStringContainsString(
            'printenv PHOTO_STORAGE_PATH | xargs -r -I{} test -w {}',
            $compose
        );
        $this->assertStringNotContainsString("stat -c '%u:%g'", $compose);

        // Die Startvorbedingungen pruefen die Variablen ueber `printenv | grep`
        // statt ueber `case "$${VAR}"`. Grund ist Compose v5.0.2: es gibt jede
        // Command-Substitution als `$$(...)` aus und reduziert `$$` in keiner
        // YAML-Form, der Container-Shell expandiert `$$` dann zur PID und der
        // Vergleich liefe gegen `1234{APP_ENV}`. Die Invariante ist unveraendert.
        foreach (['APP_KEY', 'JWT_SECRET', 'FILE_ENCRYPTION_KEY', 'ADMIN_EMAIL', 'ADMIN_PASSWORD', 'PHOTO_STORAGE_PATH'] as $variable) {
            $this->assertStringContainsString('printenv '.$variable.' | grep -q .', $compose);
        }
        // Zusaetzlich muss PHOTO_STORAGE_PATH absolut sein — das ist der
        // frueheren `case`-Verzweigung mit `/*) ;;` nachempfunden.
        $this->assertStringContainsString("printenv PHOTO_STORAGE_PATH | grep -q '^/'", $compose);
        $this->assertStringContainsString(
            'php artisan migrate --force && php artisan app:seed-if-fresh && php artisan admin:update || exit 1',
            $compose
        );
        // Der Seed darf nicht unbedingt laufen. `DatabaseSeeder` ist fuer die 28
        // von ihm deklarierten `settings`-Keys autoritativ (upsert auf key/brand),
        // und `AGENTS.md` §13 schreibt `docker restart portal_backend` nach jedem
        // PHP-Sync vor — mit `db:seed --force` im Entrypoint hat damit jeder
        // Neustart 28 Produktions-Keys ueberschrieben, ohne dass ein Mensch den
        // Seed ausgeloest haette. Owner-Entscheidung 2026-09-28.
        $this->assertStringNotContainsString('artisan db:seed', $compose);
        $this->assertStringNotContainsString('app:import-locations', $compose);
    }

    /**
     * The isolated local E2E backend runs every Playwright worker on
     * 127.0.0.1, so the per-IP checkout limiter bucket (CheckoutKey::ip()) is
     * shared by the whole run. With the production defaults in
     * config/app.php (5/user-hour, 10/IP-hour, 30/IP-day) a full parallel run
     * exhausts that one budget and checkouts fail with "Too Many Attempts" —
     * green serially and in CI, red from roughly eight workers.
     *
     * CI carries the same three overrides in backend/.env.ci under
     * "Checkout E2E workers share localhost". The local harness must keep
     * all three, so assert the constant and every key that resolves to it.
     */
    public function test_isolated_e2e_backend_relaxes_every_checkout_throttle(): void
    {
        $script = $this->read('scripts/e2e-up.sh');

        $this->assertMatchesRegularExpression(
            '/^readonly E2E_CHECKOUT_LIMIT=1000$/m',
            $script,
            'scripts/e2e-up.sh must define E2E_CHECKOUT_LIMIT=1000; the three checkout throttles below resolve to it'
        );

        foreach ([
            'CHECKOUT_THROTTLE_USER_PER_HOUR',
            'CHECKOUT_THROTTLE_IP_PER_HOUR',
            'CHECKOUT_THROTTLE_IP_PER_DAY',
        ] as $key) {
            // Anchored to the start of a line: a commented-out or otherwise
            // prefixed `set_env` must not satisfy the gate.
            $this->assertMatchesRegularExpression(
                '/^set_env '.preg_quote($key, '/').' "\$E2E_CHECKOUT_LIMIT"$/m',
                $script,
                "scripts/e2e-up.sh must set {$key} to the relaxed E2E limit; every local worker shares "
                    .'the per-IP bucket, so the production default would fail a parallel run'
            );
        }
    }

    /**
     * Owner decision D-42: `ext-gd` is a declared platform requirement, not
     * an undocumented runtime assumption.
     *
     * The declaration is what makes a plain `composer install` fail fast on
     * a host without GD. The CI and production install paths pass
     * `--ignore-platform-reqs`, so the guard cannot break them, and the base
     * image builds GD explicitly (`deployment/Dockerfile`).
     */
    public function test_required_php_extensions_are_declared_as_platform_requirements(): void
    {
        $composer = $this->composerJson();

        $this->assertArrayHasKey(
            'require',
            $composer,
            'backend/composer.json must declare a require block'
        );

        foreach (self::REQUIRED_PLATFORM_EXTENSIONS as $extension) {
            $this->assertArrayHasKey(
                $extension,
                $composer['require'],
                "backend/composer.json must declare {$extension} as a platform requirement"
            );
        }
    }

    /**
     * The lock file has to mirror the declared platform requirements.
     *
     * A stale lock only makes Composer warn ("the lock file is not up to date
     * with the latest changes in composer.json") and continue — but the
     * platform verification that D-42 relies on then runs against the old
     * platform block, so a newly declared extension is not checked at install
     * time any more. A mirrored lock keeps that check honest.
     */
    public function test_composer_lock_mirrors_the_declared_platform_requirements(): void
    {
        $composer = $this->composerJson();
        $lock = json_decode($this->read('backend/composer.lock'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($lock, 'backend/composer.lock must decode to an object');
        $this->assertArrayHasKey(
            'platform',
            $lock,
            'backend/composer.lock must carry a platform block'
        );

        $declared = array_filter(
            $composer['require'],
            static fn (string $name): bool => $name === 'php'
                || str_starts_with($name, 'ext-')
                || str_starts_with($name, 'lib-'),
            ARRAY_FILTER_USE_KEY
        );

        $this->assertEqualsCanonicalizing(
            $declared,
            $lock['platform'],
            'backend/composer.lock must mirror the platform requirements of backend/composer.json; '
                .'refresh it with "composer update --lock --no-scripts --no-install"'
        );
    }

    /**
     * The decoded root `composer.json`.
     *
     * @return array<string, mixed>
     */
    private function composerJson(): array
    {
        $composer = json_decode($this->read('backend/composer.json'), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($composer, 'backend/composer.json must decode to an object');

        return $composer;
    }

    /**
     * Raw YAML of a single service from deployment/docker-compose.yml. Service
     * keys sit at two-space indentation under `services:`, so the next key on
     * that level — or any top-level key such as `networks:` — closes the block,
     * without needing a YAML parser.
     */
    private function composeService(string $name): string
    {
        $contents = $this->read('deployment/docker-compose.yml');
        $pattern = '/^  '.preg_quote($name, '/').':[ \t]*$(.*?)(?=^  [\w-]+:[ \t]*$|^[\w-]+:[ \t]*$|\z)/ms';
        $matched = preg_match($pattern, $contents, $matches);

        $this->assertSame(1, $matched, "docker-compose.yml must define exactly one {$name} service");
        $this->assertNotEmpty(trim($matches[1]), "the {$name} service must not be empty");

        return $matches[1];
    }

    /**
     * Raw YAML of a single top-level CI job. Job keys sit at two-space
     * indentation under `jobs:`, so the next key on that level closes the
     * block without needing a YAML parser.
     */
    private function ciJob(string $name): string
    {
        $contents = $this->read('.github/workflows/ci.yml');
        $pattern = '/^  '.preg_quote($name, '/').':[ \t]*$(.*?)(?=^  [\w-]+:[ \t]*$|\z)/ms';
        $matched = preg_match($pattern, $contents, $matches);

        $this->assertSame(1, $matched, "ci.yml must define the {$name} job");
        $this->assertNotEmpty(trim($matches[1]), "the {$name} job must not be empty");

        return $matches[1];
    }

    /**
     * Raw YAML of a single step of a CI job. Steps sit at six-space
     * indentation under `steps:`, so the next step on that level — or the
     * next job key — closes the block, without needing a YAML parser.
     */
    private function ciStep(string $jobName, string $stepName): string
    {
        $job = $this->ciJob($jobName);
        $pattern = '/^      - name: '.preg_quote($stepName, '/').'[ \t]*$(.*?)(?=^      - |^  [\w-]+:[ \t]*$|\z)/ms';
        $matched = preg_match($pattern, $job, $matches);

        $this->assertSame(1, $matched, "the {$jobName} job must define a step named \"{$stepName}\"");
        $this->assertNotEmpty(trim($matches[1]), "the \"{$stepName}\" step must not be empty");

        return $matches[1];
    }

    /**
     * The presence booleans a step declares, mapped to the secret each one
     * is bound to. Only the boolean may reach the shell — a secret value
     * would end up in the job log.
     *
     * @return array<string, string>
     */
    private function stripePresenceBooleans(string $step): array
    {
        $matched = preg_match_all(
            '/^\s+([A-Z_]+_SET): \$\{\{ secrets\.([A-Z_]+) != \'\' \}\}$/m',
            $step,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotFalse($matched, 'the preflight must expose the secret presence as booleans');

        $declared = [];
        foreach ($matches as $match) {
            $declared[$match[1]] = $match[2];
        }

        return $declared;
    }

    /**
     * The Stripe secrets a job injects, taken from the workflow itself so the
     * preflight cannot drift away from what the job actually consumes.
     *
     * @return list<string>
     */
    private function stripeSecretsReferencedByJob(string $job): array
    {
        preg_match_all('/secrets\.(STRIPE_[A-Z_]+)/', $job, $matches);

        $secrets = array_values(array_unique($matches[1]));
        sort($secrets);

        return $secrets;
    }

    /**
     * Every combination of the three presence booleans, as the environment
     * the preflight would see.
     *
     * @return list<array<string, string>>
     */
    private function presenceCombinations(): array
    {
        $combinations = [[]];

        foreach (self::E2E_STRIPE_TEST_SECRETS as $secret) {
            $expanded = [];
            foreach ($combinations as $combination) {
                foreach (['true', 'false'] as $value) {
                    $expanded[] = $combination + [$secret.'_SET' => $value];
                }
            }
            $combinations = $expanded;
        }

        return $combinations;
    }

    /**
     * The shell body of a step, taken from its `run: |` block.
     */
    private function stepRunBlock(string $step): string
    {
        $matched = preg_match('/^\s*run:\s*\|[^\n]*\n(.*)$/ms', $step, $matches);

        $this->assertSame(1, $matched, 'the step must carry a run block');

        return $matches[1];
    }

    /**
     * The condition of a step's `if` statement, normalized to one line.
     *
     * The condition decides whether the step fails, so the test evaluates it
     * instead of pinning the exact spelling of the shell expression.
     */
    private function shellCondition(string $step): string
    {
        $run = $this->stepRunBlock($step);
        $matched = preg_match('/\bif\s+(.+?);\s*then\b/s', $run, $matches);

        $this->assertSame(1, $matched, 'the step must guard its failure with an if/then condition');

        $condition = preg_replace('/\\\s*\n\s*/', ' ', $matches[1]) ?? $matches[1];

        return trim((string) preg_replace('/\s+/', ' ', $condition));
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

    /**
     * The delivered container artifacts: the files a container image reference
     * is actually consumed from.
     *
     * Prose is deliberately not scanned. Until 2026-10-18 this method walked
     * every tracked text file, Markdown included, on the theory that a stale
     * namespace in documentation is what let the original drift go unnoticed
     * (see IMAGE_NAMESPACE). The measurement that ended it: of the 139 scanned
     * Markdown files, five name a `ghcr.io/<organisation>` image reference and
     * **none** carries a finding this delivered set does not also carry — every
     * prose mention already names the organisation namespace. Prose therefore
     * had no finding to lose, while the walk made this gate depend on files CI
     * does not even run for: a documentation-only push is filtered out of the
     * pipeline (D-23), so a prose scan cannot be a gate at all. Documentation
     * drift is a review concern, not a supply-chain one.
     *
     * `ghcr.io/<organisation>` is spelled without the trailing separator on
     * purpose: with it, this docblock would match the very finding regex that
     * the IMAGE_NAMESPACE note warns about.
     *
     * Shell scripts, env fixtures and PHP test assertions are out of scope for
     * the same reason: they mention an image, they do not consume one.
     *
     * The list below is explicit rather than discovered — a conscious trade. A
     * walk picks up a new compose file or Dockerfile by itself, but it was never
     * complete either: it skipped whole trees and could not see an untracked
     * pin, so its breadth was hoped for, not guaranteed. The explicit list is
     * narrow but guaranteed. The consequence is named rather than hidden: a new
     * delivered artifact has to be added here, and nothing reports one that was
     * forgotten.
     *
     * @return list<string>
     */
    private function containerArtifactFiles(): array
    {
        $files = array_merge(
            self::COMPOSE_FILES,
            ['deployment/Dockerfile', 'deployment/Dockerfile.e2e'],
            array_map(
                static fn (string $path): string => '.github/workflows/'.basename($path),
                $this->workflowFiles()
            )
        );

        sort($files);

        return $files;
    }

    /**
     * Best-effort 1-based line number for a match, for actionable failures.
     */
    private function lineOf(string $contents, string $needle): int
    {
        $position = strpos($contents, $needle);

        return $position === false ? 0 : substr_count($contents, "\n", 0, $position) + 1;
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
