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

        // Die Guards lesen ueber `printenv | grep` statt ueber ein `case`, weil
        // Compose v5.0.2 jede Command-Substitution als `$$(...)` ausgibt und
        // `$$` in keiner YAML-Form reduziert — der Container-Shell expandiert
        // `$$` zur PID, und `case "$${APP_ENV}"` verglich dann `1234{APP_ENV}`.
        // Die Invariante ist dieselbe: nur Zeichen aus der Klasse sind erlaubt,
        // ein leerer Wert bleibt erlaubt. `test_the_guards_reject_unsafe_values`
        // beweist das ausfuehrlich statt per String-Match.
        // Jeder Guard muss in den Fail-closed-Zweig muenden, sonst warnt er nur.
        // Die Zeile wird deshalb komplett geprueft, nicht nur die Bedingung.
        $this->assertStringContainsString(
            "printenv AI_SESSION_HEADER | grep -qE '^[A-Za-z0-9-]*$' || { echo",
            $compose
        );
        $this->assertStringContainsString(
            "printenv AI_SESSION_PREFIX | grep -qE '^[A-Za-z0-9._-]*$' || { echo",
            $compose
        );
        $this->assertSame(2, substr_count($compose, 'printenv AI_SESSION_'));
        // Das Muster gilt fuer alle Startvorbedingungen, nicht nur fuer die
        // beiden Session-Guards — hier wird nur belegt, dass es ueberhaupt
        // verwendet wird, damit ein Umbau auf reine Warnungen auffaellt.
        $this->assertGreaterThanOrEqual(2, substr_count($compose, "(fail-closed)!'; exit 1; }"));

        $headerGuard = strpos($compose, 'printenv AI_SESSION_HEADER | grep -qE');
        $prefixGuard = strpos($compose, 'printenv AI_SESSION_PREFIX | grep -qE');
        $applicationBootstrap = strpos($compose, 'php artisan cache:clear');

        $this->assertNotFalse($headerGuard);
        $this->assertNotFalse($prefixGuard);
        $this->assertNotFalse($applicationBootstrap);
        $this->assertLessThan($applicationBootstrap, $headerGuard);
        $this->assertLessThan($applicationBootstrap, $prefixGuard);
    }

    /**
     * Fuehrt die beiden Guards aus dem Compose-File wirklich aus.
     *
     * Ein String-Match auf die POSIX-Klasse beweist nur, dass irgendwo eine
     * Klasse steht. Hier wird die extrahierte Zeile tatsaechlich in `sh`
     * ausgefuehrt — und genau das ist der Unterschied zwischen einer
     * Absicherung und einer Absicht.
     *
     * Ausfuehrlich weil die CRLF-Variante die eigentliche Bedrohung ist: ein
     * Header-Name mit Zeilenumbruch landet in einer Request-Header-Zeile und
     * zeil einen Header-Splitter auf.
     */
    public function test_the_guards_reject_unsafe_values(): void
    {
        $compose = $this->read('deployment/docker-compose.yml');
        preg_match_all('/^\s+printenv AI_SESSION_(?:HEADER|PREFIX) .*$/m', $compose, $matches);
        $this->assertCount(2, $matches[0], 'both session guards must be present in the compose command');

        foreach ($matches[0] as $guardLine) {
            $guard = trim($guardLine);
            $variable = str_contains($guard, 'AI_SESSION_HEADER') ? 'AI_SESSION_HEADER' : 'AI_SESSION_PREFIX';

            $allowed = $variable === 'AI_SESSION_HEADER'
                ? ['x-opencode-session', '', 'abc-123']
                : ['portal-', '', 'portal.a_b-c'];
            foreach ($allowed as $value) {
                $this->assertSame(
                    0,
                    $this->runGuard($guard, $variable, $value),
                    sprintf('%s must accept %s', $variable, var_export($value, true)),
                );
            }

            // Gemeinsame Ablehnungen fuer beide Variablen: CRLF ist die
            // eigentliche Bedrohung (Header-Splitting), der Rest sind
            // Shell-Metazeichen. `a_b` steht bewusst nur beim Header: der
            // Unterstrich ist im HTTP-Header-Namen unzulaessig, im Prefix aber
            // ausdruecklich erlaubt (er muss zu `x-opencode-session` passen,
            // das ja nicht der Prefix ist — der Prefix darf Punkte und
            // Unterstriche tragen).
            $rejected = ["x\r\ny: 1", 'x;rm -rf /', 'a b', '$(id)'];
            if ($variable === 'AI_SESSION_HEADER') {
                $rejected[] = 'a_b';
            }

            foreach ($rejected as $value) {
                $this->assertSame(
                    1,
                    $this->runGuard($guard, $variable, $value),
                    sprintf('%s must reject %s', $variable, var_export($value, true)),
                );
            }
        }
    }

    /**
     * Fuehrt die Bedingung einer Guard-Zeile aus. Rueckgabe 0 = akzeptiert,
     * 1 = abgelehnt.
     *
     * Nur die Bedingung (alles vor `||`), nie das `exit` — sonst waere der
     * Testprozess beendet. So bleibt der sh-Code minimal und vorhersagbar.
     */
    private function runGuard(string $guardLine, string $variable, string $value): int
    {
        // Bedingung = alles vor dem ersten `||`
        $parts = explode('||', $guardLine, 2);
        $condition = trim($parts[0]);

        // Die Variable wird per `export` gesetzt, nicht ueber die Umgebung von
        // proc_open: PHP verwirft dort leerwertige Variablen vollstaendig, und
        // ein leeres `AI_SESSION_HEADER` ist genau der dokumentierte Weg, den
        // Header abzuschalten. Docker exportiert die Variablen aus
        // `environment:`, `export` bildet das nach; ohne `export` sieht
        // `printenv` die Variable gar nicht, weil es nur die Umgebung liest.
        $command = sprintf('export %s=%s; %s', $variable, escapeshellarg($value), $condition);

        $process = proc_open(
            ['sh', '-c', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (! is_resource($process)) {
            $this->fail('could not start the guard process');
        }
        foreach ($pipes as $pipe) {
            stream_get_contents($pipe);
            fclose($pipe);
        }

        return proc_close($process);
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
