<?php

namespace Tests\Unit\Support;

use App\Support\FtpSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The format rule of P1-M21 in isolation: `ftp_slug` is the FTP/SFTP account
 * name, so `^[a-z0-9][a-z0-9_-]{2,31}$` is a hard contract, not cosmetics.
 */
class FtpSlugTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function validValues(): array
    {
        return [
            'plain' => ['jdoe'],
            'dash' => ['j-doe'],
            'underscore' => ['j_doe'],
            'trailing digit' => ['jdoe1'],
            'digits only' => ['123'],
            'minimum length' => ['abc'],
            'maximum length' => [str_repeat('a', FtpSlug::MAX_LENGTH)],
            'all allowed characters' => ['a0-_0a'],
        ];
    }

    #[DataProvider('validValues')]
    public function test_accepts_slug_equivalent_values(string $value): void
    {
        $this->assertTrue(FtpSlug::isValid($value), "[{$value}] should be a valid ftp slug");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidValues(): array
    {
        return [
            'dot' => ['a.b'],
            'at sign' => ['a@b'],
            'slash' => ['a/b'],
            'backslash' => ['a\\b'],
            'space' => ['a b'],
            'leading underscore' => ['_jdoe'],
            'leading dash' => ['-jdoe'],
            'too short' => ['ab'],
            'single character' => ['a'],
            'too long' => [str_repeat('a', FtpSlug::MAX_LENGTH + 1)],
            'uppercase' => ['JDoe'],
            'plus' => ['j+doe'],
            'path traversal' => ['../jdoe'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_rejects_non_conforming_values(string $value): void
    {
        $this->assertFalse(FtpSlug::isValid($value), "[{$value}] should not be a valid ftp slug");
    }

    /**
     * normalize() may only touch cosmetics. Everything the format spec exists to
     * reject has to survive it, otherwise a typed login would silently become a
     * different account name.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    #[DataProvider('normalizeCases')]
    public function test_normalize_only_touches_cosmetics(string $input, string $expected): void
    {
        $normalized = FtpSlug::normalize($input);

        $this->assertSame($expected, $normalized);
        $this->assertSame(
            FtpSlug::isValid($expected),
            FtpSlug::isValid($normalized),
            'normalize() must not change whether a value passes the format rule'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalizeCases(): array
    {
        return [
            'case and umlauts' => ['Max NeÚ', 'max-neu'],
            'surrounding and inner whitespace' => ['  Max  Mustermann  ', 'max-mustermann'],
            'repeat dash' => ['j - doe', 'j-doe'],
            'underscore survives' => ['j_doe', 'j_doe'],
            'trailing separator' => ['jdoe-', 'jdoe'],
            'dot survives' => ['j.doe', 'j.doe'],
            'at sign survives' => ['j@doe', 'j@doe'],
            'slash survives' => ['j/doe', 'j/doe'],
            'leading underscore survives' => ['_jdoe', '_jdoe'],
            'leading dash survives' => ['-jdoe', '-jdoe'],
        ];
    }

    /**
     * The generated path may reduce what normalize() preserves, because nothing
     * typed it and nothing references it yet.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    #[DataProvider('generatedBaseCases')]
    public function test_generated_base_is_always_valid(string $input, string $expected): void
    {
        $base = FtpSlug::toValidBase($input);

        $this->assertSame($expected, $base);
        $this->assertTrue(FtpSlug::isValid($base), "[{$base}] must satisfy the format rule");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function generatedBaseCases(): array
    {
        return [
            'plain local part' => ['florian', 'florian'],
            'dotted local part' => ['j.doe', 'j-doe'],
            'at sign local part' => ['j@doe', 'j-doe'],
            'slash local part' => ['j/doe', 'j-doe'],
            'underscore local part' => ['j_doe', 'j_doe'],
            'too short local part' => ['ab', FtpSlug::FALLBACK],
            'only separators' => ['--__--', FtpSlug::FALLBACK],
            'empty' => ['', FtpSlug::FALLBACK],
            'overlong local part' => [str_repeat('a', 40), str_repeat('a', FtpSlug::MAX_LENGTH)],
            'leading separator' => ['_jdoe', 'jdoe'],
        ];
    }

    public function test_collision_counter_never_exceeds_the_length_ceiling(): void
    {
        $base = FtpSlug::toValidBase(str_repeat('a', 40));

        foreach (['1', '12', '123'] as $counter) {
            $suffixed = FtpSlug::withSuffix($base, $counter);

            $this->assertTrue(FtpSlug::isValid($suffixed), "[{$suffixed}] must stay valid");
            $this->assertLessThanOrEqual(FtpSlug::MAX_LENGTH, strlen($suffixed));
            $this->assertStringEndsWith($counter, $suffixed);
        }
    }

    public function test_message_states_the_same_bounds_as_the_rule(): void
    {
        $message = FtpSlug::message();

        $this->assertStringContainsString((string) FtpSlug::MIN_LENGTH, $message);
        $this->assertStringContainsString((string) FtpSlug::MAX_LENGTH, $message);
    }

    public function test_pattern_anchors_both_ends(): void
    {
        // A non-anchored pattern would accept "a.b" via a partial match and
        // silently reintroduce the original defect.
        $this->assertStringStartsWith('/^', FtpSlug::PATTERN);
        $this->assertStringEndsWith('$/', FtpSlug::PATTERN);
    }
}
