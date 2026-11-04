<?php

namespace App\Console\Commands;

use RuntimeException;

final class PostgresCopyValueNormalizer
{
    /**
     * Convert only values whose MariaDB representation is not accepted by the
     * equivalent PostgreSQL column. JSON and decimal values deliberately stay
     * byte-for-byte strings; UUIDs are already stored in lowercase.
     *
     * @param  array{data_type: string, column_type: string}  $sourceColumn
     */
    public function normalize(mixed $value, array $sourceColumn, string $targetDataType): mixed
    {
        if ($value === null) {
            return null;
        }

        $dataType = strtolower($sourceColumn['data_type']);
        $columnType = strtolower($sourceColumn['column_type']);
        $targetDataType = strtolower($targetDataType);

        if ($dataType === 'tinyint' && preg_match('/^tinyint\(1\)(?:\s|$)/', $columnType) === 1) {
            if ($targetDataType !== 'boolean') {
                throw new RuntimeException('A MariaDB tinyint(1) column must map to PostgreSQL boolean.');
            }

            if ($value === 0 || $value === '0') {
                return false;
            }

            if ($value === 1 || $value === '1') {
                return true;
            }

            throw new RuntimeException('A MariaDB tinyint(1) value was not 0 or 1.');
        }

        if (in_array($dataType, ['date', 'datetime', 'timestamp'], true) && $this->isZeroDate((string) $value)) {
            return null;
        }

        if ($this->isBinaryType($dataType)) {
            if ($targetDataType !== 'bytea') {
                throw new RuntimeException('A MariaDB binary/blob column must map to PostgreSQL bytea.');
            }

            if (is_resource($value)) {
                $value = stream_get_contents($value);

                if (! is_string($value)) {
                    throw new RuntimeException('A MariaDB binary/blob value could not be read.');
                }
            }

            if (! is_string($value)) {
                throw new RuntimeException('A MariaDB binary/blob value was not a byte string.');
            }

            // PostgreSQL bytea's text input format is a hex escape. Encoding
            // preserves the original bytes, including NUL and invalid UTF-8.
            return '\\x'.bin2hex($value);
        }

        return $value;
    }

    private function isZeroDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]|$)/', $value, $parts) !== 1) {
            return false;
        }

        return $parts[1] === '0000' || $parts[2] === '00' || $parts[3] === '00';
    }

    private function isBinaryType(string $dataType): bool
    {
        return in_array($dataType, [
            'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob',
        ], true);
    }
}
