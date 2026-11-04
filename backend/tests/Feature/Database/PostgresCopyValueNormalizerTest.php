<?php

namespace Tests\Feature\Database;

use App\Console\Commands\PostgresCopyValueNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PostgresCopyValueNormalizerTest extends TestCase
{
    #[DataProvider('tinyIntValues')]
    public function test_mariadb_tinyint_one_is_converted_to_a_postgres_boolean(mixed $value, bool $expected): void
    {
        $actual = (new PostgresCopyValueNormalizer)->normalize(
            $value,
            ['data_type' => 'tinyint', 'column_type' => 'tinyint(1) unsigned'],
            'boolean',
        );

        $this->assertSame($expected, $actual);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function tinyIntValues(): iterable
    {
        yield 'zero string' => ['0', false];
        yield 'one string' => ['1', true];
        yield 'zero integer' => [0, false];
        yield 'one integer' => [1, true];
    }

    public function test_zero_dates_become_null_but_valid_dates_are_unchanged(): void
    {
        $normalizer = new PostgresCopyValueNormalizer;
        $column = ['data_type' => 'datetime', 'column_type' => 'datetime'];

        $this->assertNull($normalizer->normalize('0000-00-00 00:00:00', $column, 'timestamp without time zone'));
        $this->assertNull($normalizer->normalize('2020-00-00 12:00:00', $column, 'timestamp without time zone'));
        $this->assertSame('2026-10-20 10:15:30', $normalizer->normalize(
            '2026-10-20 10:15:30',
            $column,
            'timestamp without time zone',
        ));
    }

    public function test_json_decimal_and_lowercase_uuid_strings_are_not_rewritten(): void
    {
        $normalizer = new PostgresCopyValueNormalizer;
        $json = '{"enabled":true,"items":[1,2]}';
        $decimal = '12.3400';
        $uuid = '9f7c2d93-5df6-4fab-a29d-7ba9b431a601';

        $this->assertSame($json, $normalizer->normalize(
            $json,
            ['data_type' => 'json', 'column_type' => 'longtext'],
            'jsonb',
        ));
        $this->assertSame($decimal, $normalizer->normalize(
            $decimal,
            ['data_type' => 'decimal', 'column_type' => 'decimal(12,4)'],
            'numeric',
        ));
        $this->assertSame($uuid, $normalizer->normalize(
            $uuid,
            ['data_type' => 'char', 'column_type' => 'char(36)'],
            'character',
        ));
    }

    public function test_binary_bytes_are_encoded_for_bytea_without_changing_the_payload(): void
    {
        $bytes = "\x00\xFFportal\x00";

        $normalized = (new PostgresCopyValueNormalizer)->normalize(
            $bytes,
            ['data_type' => 'longblob', 'column_type' => 'longblob'],
            'bytea',
        );

        $this->assertSame('\\x'.bin2hex($bytes), $normalized);
        $this->assertSame($bytes, hex2bin(substr($normalized, 2)));
    }

    public function test_invalid_tinyint_boolean_values_fail_loudly(): void
    {
        $this->expectException(RuntimeException::class);

        (new PostgresCopyValueNormalizer)->normalize(
            '2',
            ['data_type' => 'tinyint', 'column_type' => 'tinyint(1)'],
            'boolean',
        );
    }
}
