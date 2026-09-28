<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards D-6 (AGENTS.md §14): the CI fixture raises API_THROTTLE_LIMIT to 1000
 * while backend/.env.example keeps 60 as the documented production value.
 *
 * Both files are read as text and parsed here. Reading the value through env()
 * or config('app.throttle_api') would test the phpunit.xml environment instead
 * of the file: the test process always carries its own value, so the assertion
 * would pass even after someone reverted the file — a test that cannot fail.
 */
class ApiThrottleLimitPolicyTest extends TestCase
{
    private const KEY = 'API_THROTTLE_LIMIT';

    public function test_ci_raises_the_api_throttle_limit_to_1000(): void
    {
        $contents = $this->read('backend/.env.ci');

        $this->assertSame('1000', $this->envValue($contents, self::KEY));
    }

    public function test_ci_documents_why_it_exceeds_the_production_value(): void
    {
        $contents = $this->read('backend/.env.ci');
        $comment = $this->commentBlockAbove($contents, self::KEY);

        // A bare number loses the reason D-6 records: the four Playwright
        // workers share 127.0.0.1 and burst past the 60/min production budget.
        $this->assertNotSame('', $comment, 'API_THROTTLE_LIMIT must not be a bare number in .env.ci');
        $this->assertStringContainsString('Playwright', $comment);
    }

    public function test_example_keeps_60_as_the_documented_production_value(): void
    {
        $contents = $this->read('backend/.env.example');
        $comment = $this->commentBlockAbove($contents, self::KEY);

        $this->assertSame('60', $this->envValue($contents, self::KEY));
        $this->assertNotSame('', $comment, 'API_THROTTLE_LIMIT must not be a bare number in .env.example');
        $this->assertStringContainsString('60', $comment);
        $this->assertStringContainsString('.env.ci', $comment);
        $this->assertMatchesRegularExpression(
            '/production|produktions-sinnwert/i',
            $comment,
            'The example must name 60 as the production value.'
        );
    }

    public function test_ci_limit_stays_strictly_above_the_documented_production_value(): void
    {
        $ci = (int) $this->envValue($this->read('backend/.env.ci'), self::KEY);
        $example = (int) $this->envValue($this->read('backend/.env.example'), self::KEY);

        // If both drift to the same number, D-6 is silently reversed while each
        // file still "matches" itself. The relationship is the contract.
        $this->assertGreaterThan($example, $ci);
    }

    /**
     * Read the effective, uncommented value for `$key`.
     *
     * Only whole assignment lines count, so a commented sample in the template
     * can never be mistaken for the declared value.
     */
    private function envValue(string $contents, string $key): string
    {
        $pattern = '/^'.preg_quote($key, '/').'=(.*)$/m';

        $this->assertMatchesRegularExpression($pattern, $contents, "{$key} must be declared as an uncommented assignment");

        preg_match($pattern, $contents, $matches);

        return trim($matches[1], " \t\"'");
    }

    /**
     * The contiguous comment lines directly above the `$key` assignment.
     *
     * The decision records *why* CI differs from production; this returns the
     * text that keeps that reasoning attached to the number.
     */
    private function commentBlockAbove(string $contents, string $key): string
    {
        $lines = preg_split('/\R/', $contents) ?: [];
        $block = [];

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if (preg_match('/^'.preg_quote($key, '/').'=/', $trimmed) === 1) {
                return implode("\n", $block);
            }

            if (str_starts_with($trimmed, '#')) {
                $block[] = trim(ltrim($trimmed, "# \t"));

                continue;
            }

            if (trim($trimmed) !== '') {
                // A previous assignment or directive ends the run.
                $block = [];
            }
        }

        $this->fail("{$key} must be declared as an uncommented assignment");
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
