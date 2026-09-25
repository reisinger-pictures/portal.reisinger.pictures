<?php

namespace App\Services;

use App\Support\PersistedMoney;
use InvalidArgumentException;

/**
 * The single pricing implementation for contract snapshots and the line
 * items used by invoice/PDF documents.
 *
 * Contract snapshots keep ordinary items and discounts in separate arrays.
 * Reads may still encounter an older row where a discount was written into
 * `items` (or an item into `discounts`); that compatibility is normalized
 * deterministically. New writes are checked with preflightForWrite(), which
 * rejects mixed arrays, non-canonical numeric values, unsafe arithmetic, and
 * totals that cannot fit the persisted accounting columns before persistence.
 */
class ContractPricingService
{
    public const ITEM_TYPE = 'item';

    public const FIXED_DISCOUNT_TYPE = 'discount_fixed';

    public const PERCENT_DISCOUNT_TYPE = 'discount_percent';

    /** Percentage rates use basis points: 10% = 1000, 100% = 10000. */
    public const PERCENT_SCALE = 10000;

    /** Manual-invoice quantities use hundredths: 0.25 = 25 wire units. */
    public const MANUAL_QUANTITY_SCALE = 100;

    /**
     * The largest integer that can cross the PHP/TypeScript JSON boundary
     * without losing integer precision in JavaScript.
     */
    public const MAX_SAFE_INTEGER = 9007199254740991;

    /** There are 100 basis points in one display percentage point. */
    private const DISPLAY_PERCENT_SCALE = 100;

    /**
     * Normalize a snapshot for reading and return the ordered invoice lines.
     *
     * @return array{items: list<array<string, mixed>>, discounts: list<array<string, mixed>>, lines: list<array<string, mixed>>}
     */
    public function normalizeSnapshot(mixed $items, mixed $discounts): array
    {
        $itemLines = $this->normalizeLines($items, 'items', true);
        $discountLines = $this->normalizeLines($discounts, 'discounts', true);

        $normalizedItems = [
            ...array_values(array_filter(
                $itemLines,
                static fn (array $line): bool => $line['type'] === self::ITEM_TYPE,
            )),
            ...array_values(array_filter(
                $discountLines,
                static fn (array $line): bool => $line['type'] === self::ITEM_TYPE,
            )),
        ];
        $normalizedDiscounts = [
            ...array_values(array_filter(
                $itemLines,
                static fn (array $line): bool => $line['type'] !== self::ITEM_TYPE,
            )),
            ...array_values(array_filter(
                $discountLines,
                static fn (array $line): bool => $line['type'] !== self::ITEM_TYPE,
            )),
        ];

        $hasLegacyMixedPlacement = $this->containsNonItem($itemLines)
            || $this->containsItem($discountLines);

        // In a canonical snapshot all items precede the ordered discounts. If
        // a legacy row mixed the two arrays, retain the source order for the
        // compatibility calculation/invoice serialization.
        $lines = $hasLegacyMixedPlacement
            ? [...$itemLines, ...$discountLines]
            : [...$normalizedItems, ...$normalizedDiscounts];

        return [
            'items' => $normalizedItems,
            'discounts' => $normalizedDiscounts,
            'lines' => $lines,
        ];
    }

    /**
     * Validate the canonical representation before persisting a new contract.
     *
     * @return array{items: list<array<string, mixed>>, discounts: list<array<string, mixed>>, lines: list<array<string, mixed>>}
     */
    public function normalizeForWrite(mixed $items, mixed $discounts): array
    {
        $itemLines = $this->normalizeLines($items, 'items', false);
        $discountLines = $this->normalizeLines($discounts, 'discounts', false);

        foreach ($itemLines as $line) {
            if ($line['type'] !== self::ITEM_TYPE) {
                throw new InvalidArgumentException('Discounts must be stored in the discounts array.');
            }
        }

        foreach ($discountLines as $line) {
            if ($line['type'] === self::ITEM_TYPE) {
                throw new InvalidArgumentException('Items must be stored in the items array.');
            }
        }

        return [
            'items' => $itemLines,
            'discounts' => $discountLines,
            'lines' => [...$itemLines, ...$discountLines],
        ];
    }

    /**
     * Normalize a write and run the same authoritative pricing engine that is
     * used for serialization, closing, and invoicing. This must run before a
     * model write so an arithmetically invalid snapshot cannot become a 500
     * after it has already been persisted.
     *
     * @return array{items: list<array<string, mixed>>, discounts: list<array<string, mixed>>, lines: list<array<string, mixed>>}
     */
    public function preflightForWrite(mixed $items, mixed $discounts): array
    {
        $snapshot = $this->normalizeForWrite($items, $discounts);
        $this->preflightSnapshot($snapshot);

        return $snapshot;
    }

    /**
     * Run checked pricing on an already normalized snapshot and enforce the
     * smaller ceiling required by the persisted order/invoice money columns.
     *
     * @param  array{items: list<array<string, mixed>>, discounts: list<array<string, mixed>>, lines: list<array<string, mixed>>}  $snapshot
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function preflightSnapshot(array $snapshot): array
    {
        $processed = $this->processNormalizedLines($snapshot['lines'], 1);
        PersistedMoney::assertFitsCents($processed['total'], 'contract total');

        return $processed;
    }

    /**
     * Determine whether copying a legacy snapshot into the canonical
     * items/discounts partitions preserves its ordered line stream.
     *
     * A mixed row is safe to copy when the resulting canonical sequence has
     * the same type order as the source. Otherwise the only lossless choice
     * is to fail closed; silently moving a discount before an item changes the
     * price semantics.
     */
    public function canCopySnapshotWithoutReordering(mixed $items, mixed $discounts): bool
    {
        $snapshot = $this->normalizeSnapshot($items, $discounts);
        $canonicalLines = [...$snapshot['items'], ...$snapshot['discounts']];
        $sourceLines = $snapshot['lines'];

        if (count($sourceLines) !== count($canonicalLines)) {
            return false;
        }

        foreach ($sourceLines as $index => $line) {
            if (($line['type'] ?? null) !== ($canonicalLines[$index]['type'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return the canonical line order used by contract invoices and PDFs.
     *
     * @return list<array<string, mixed>>
     */
    public function invoiceLines(mixed $items, mixed $discounts): array
    {
        return $this->normalizeSnapshot($items, $discounts)['lines'];
    }

    public function calculateTotal(mixed $items, mixed $discounts): int
    {
        return $this->preflightSnapshot(
            $this->normalizeSnapshot($items, $discounts),
        )['total'];
    }

    /**
     * Process ordered contract invoice lines and calculate their total.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function processLines(mixed $lines): array
    {
        return $this->processNormalizedLines(
            $this->normalizeLines($lines, 'items', true),
            1,
        );
    }

    /**
     * Process manual-invoice lines whose quantities use hundredths on the wire.
     * Legacy lines without `quantity_scale` retain their exact decimal quantity
     * and are normalized to the same integer representation before arithmetic.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function processManualInvoiceLines(mixed $lines): array
    {
        return $this->processNormalizedLines(
            $this->normalizeManualInvoiceLines($lines),
            self::MANUAL_QUANTITY_SCALE,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $normalizedLines
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function processNormalizedLines(array $normalizedLines, int $quantityScale): array
    {
        $runningTotal = 0;
        $mappedItems = [];

        foreach ($normalizedLines as $line) {
            if ($line['type'] === self::ITEM_TYPE) {
                $rowTotal = $quantityScale === 1
                    ? $this->checkedMultiplyNonNegative($line['price'], $line['qty'])
                    : $this->multiplyAndRoundHalfUpNonNegative(
                        $line['price'],
                        $line['qty'],
                        $quantityScale,
                    );
                $runningTotal = $this->checkedAdd($runningTotal, $rowTotal);
                $mappedItems[] = [
                    'type' => self::ITEM_TYPE,
                    'filename' => $line['description'],
                    'notes' => $line['notes'] ?? '',
                    'tier' => 'custom',
                    'qty' => $quantityScale === 1
                        ? $line['qty']
                        : $this->formatFixedPoint($line['qty'], $quantityScale),
                    'price' => $line['price'],
                    'row_total' => $rowTotal,
                ];

                continue;
            }

            if ($line['type'] === self::FIXED_DISCOUNT_TYPE) {
                $discountAmount = $line['price'];
                $runningTotal = $this->checkedAdd($runningTotal, -$discountAmount);
                $mappedItems[] = [
                    'type' => self::FIXED_DISCOUNT_TYPE,
                    'filename' => $line['description'],
                    'notes' => $line['notes'] ?? '',
                    'tier' => 'custom',
                    'qty' => 1,
                    'price' => $line['price'],
                    'row_total' => -$discountAmount,
                ];

                continue;
            }

            // Calculate round(abs(runningTotal) * rate / 10000) without ever
            // forming the potentially 54-bit product as a PHP float. The same
            // decomposition and half-up-away-from-zero rule are implemented
            // in frontend/src/logic/contractPricing.ts.
            $discountAmount = $this->percentageDiscountAmount($runningTotal, $line['price']);
            $runningTotal = $this->checkedAdd($runningTotal, -$discountAmount);
            $mappedItems[] = [
                'type' => self::PERCENT_DISCOUNT_TYPE,
                'filename' => $line['description'],
                'notes' => $line['notes'] ?? '',
                'tier' => 'custom',
                'qty' => 1,
                'price' => $line['price'],
                'row_total' => -$discountAmount,
                // Derived display metadata, not the wire percentage rate.
                // 1000 basis points are exposed as integer 10 (10%).
                'calculated_percentage' => $this->displayPercentage($line['price']),
            ];
        }

        return [
            'items' => $mappedItems,
            'total' => max(0, $runningTotal),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeManualInvoiceLines(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('The manual invoice items must be a list.');
        }

        $normalizedInput = [];
        foreach ($value as $index => $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException("The manual invoice contains an invalid line at index {$index}.");
            }

            $quantityScale = $line['quantity_scale'] ?? null;
            if ($quantityScale !== null && $quantityScale !== self::MANUAL_QUANTITY_SCALE) {
                throw new InvalidArgumentException("The items[{$index}].quantity_scale value is unsupported.");
            }

            if (($line['type'] ?? null) === self::ITEM_TYPE) {
                $line['qty'] = $quantityScale === null
                    ? $this->legacyManualQuantityToUnits(
                        $line['qty'] ?? null,
                        "items[{$index}].qty",
                    )
                    : $this->normalizeFixedManualQuantity(
                        $line['qty'] ?? null,
                        $quantityScale,
                        "items[{$index}].qty",
                    );
                $line['quantity_scale'] = self::MANUAL_QUANTITY_SCALE;
            } else {
                // Manual wire payloads may carry the uniform fixed-point
                // metadata on discount rows too. Discounts intentionally do
                // not use quantity data, so discard it before pricing.
                unset($line['qty'], $line['quantity_scale']);
            }

            $normalizedInput[] = $line;
        }

        return $this->normalizeLines($normalizedInput, 'items', false);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(mixed $value, string $field, bool $legacyRead): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("The {$field} snapshot must be a list.");
        }

        $normalized = [];
        foreach ($value as $index => $line) {
            if (! is_array($line)) {
                throw new InvalidArgumentException("The {$field} snapshot contains an invalid line at index {$index}.");
            }

            $type = $line['type'] ?? null;
            if ($type === null && $legacyRead && $field === 'items') {
                // A very old contract row omitted the type for ordinary items.
                // This is the only implicit type compatibility on read.
                $type = self::ITEM_TYPE;
            }

            if (! is_string($type) || ! in_array($type, [
                self::ITEM_TYPE,
                self::FIXED_DISCOUNT_TYPE,
                self::PERCENT_DISCOUNT_TYPE,
            ], true)) {
                throw new InvalidArgumentException("The {$field} snapshot contains an unsupported line type.");
            }

            $line['type'] = $type;
            $line['description'] = $this->normalizeDescription($line['description'] ?? null, $field, $index);
            if (array_key_exists('notes', $line)) {
                $line['notes'] = is_string($line['notes']) ? $line['notes'] : '';
            }
            $line['price'] = $this->normalizeNonNegativeInteger(
                $line['price'] ?? null,
                "{$field}[{$index}].price",
                $legacyRead,
            );

            if ($type === self::ITEM_TYPE) {
                $line['qty'] = $this->normalizePositiveInteger(
                    $line['qty'] ?? ($legacyRead ? 1 : null),
                    "{$field}[{$index}].qty",
                    $legacyRead,
                );
            } else {
                // Discount quantity is presentation-only and is intentionally
                // not part of the canonical contract snapshot.
                unset($line['qty']);
            }

            // row_total is derived output. Keeping it in a read snapshot would
            // allow a stale client value to leak back into an invoice/PDF.
            unset($line['row_total']);

            $normalized[] = $line;
        }

        return $normalized;
    }

    private function normalizeDescription(mixed $value, string $field, int $index): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("The {$field} snapshot contains an invalid description at index {$index}.");
        }

        return $value;
    }

    private function normalizeNonNegativeInteger(mixed $value, string $field, bool $legacyRead): int
    {
        $integer = $this->normalizeInteger($value, $field, $legacyRead);

        if ($integer < 0) {
            throw new InvalidArgumentException("The {$field} value must not be negative.");
        }

        return $integer;
    }

    private function normalizePositiveInteger(mixed $value, string $field, bool $legacyRead): int
    {
        $integer = $this->normalizeNonNegativeInteger($value, $field, $legacyRead);
        if ($integer < 1) {
            throw new InvalidArgumentException("The {$field} value must be at least one.");
        }

        return $integer;
    }

    private function normalizeInteger(mixed $value, string $field, bool $legacyRead): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (! $legacyRead) {
            throw new InvalidArgumentException("The {$field} value must be a canonical integer.");
        } elseif (is_float($value)) {
            // Casting before checking can saturate an out-of-range float. Only
            // an integral float strictly below 2^53 can be represented by the
            // canonical JavaScript safe-integer range.
            if (! is_finite($value)
                || floor($value) !== $value
                || $value > (float) self::MAX_SAFE_INTEGER
            ) {
                throw new InvalidArgumentException("The {$field} value must be an exactly representable integer.");
            }

            $integer = (int) $value;
        } elseif (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
            $integer = $this->integerFromString($value, $field);
        } else {
            throw new InvalidArgumentException("The {$field} value must be an integer.");
        }

        if ($integer < -self::MAX_SAFE_INTEGER || $integer > self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException("The {$field} value exceeds the safe integer range.");
        }

        return $integer;
    }

    private function integerFromString(string $value, string $field): int
    {
        $negative = str_starts_with($value, '-');
        $digits = ltrim($negative ? substr($value, 1) : $value, '0');
        $digits = $digits === '' ? '0' : $digits;
        $maximum = (string) self::MAX_SAFE_INTEGER;

        if ($negative) {
            throw new InvalidArgumentException("The {$field} value must not be negative.");
        }

        if (strlen($digits) > strlen($maximum)
            || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)
        ) {
            throw new InvalidArgumentException("The {$field} value exceeds the safe integer range.");
        }

        return (int) $digits;
    }

    private function normalizeFixedManualQuantity(
        mixed $value,
        mixed $quantityScale,
        string $field,
    ): int {
        if ($quantityScale !== self::MANUAL_QUANTITY_SCALE) {
            throw new InvalidArgumentException("The {$field} quantity scale is unsupported.");
        }

        return $this->normalizePositiveInteger($value, $field, false);
    }

    private function legacyManualQuantityToUnits(mixed $value, string $field): int
    {
        $units = match (true) {
            is_int($value) => $this->checkedMultiplyNonNegative(
                $value,
                self::MANUAL_QUANTITY_SCALE,
            ),
            is_float($value) => $this->manualQuantityFloatToUnits($value, $field),
            is_string($value) => $this->manualQuantityStringToUnits($value, $field),
            default => throw new InvalidArgumentException("The {$field} value must be a quantity."),
        };

        if ($units < 1) {
            throw new InvalidArgumentException("The {$field} value must be at least 0.01.");
        }

        return $units;
    }

    private function manualQuantityFloatToUnits(float $value, string $field): int
    {
        // JSON decoding has already turned a legacy numeric quantity into a
        // float. Do not multiply that float by the scale: the product can
        // round before the fixed-point boundary is checked. The shortest JSON
        // representation preserves the decimal value that was supplied on the
        // wire, which is then parsed with integer arithmetic below.
        if (! is_finite($value)
            || $value <= 0
            || $value > (float) intdiv(self::MAX_SAFE_INTEGER, self::MANUAL_QUANTITY_SCALE)
        ) {
            throw new InvalidArgumentException("The {$field} value must have at most two exact decimal places.");
        }

        $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        if (! is_string($encoded)) {
            throw new InvalidArgumentException("The {$field} value must be an exact decimal quantity.");
        }

        return $this->manualDecimalStringToUnits(
            $this->expandDecimalNotation($encoded, $field),
            $field,
        );
    }

    private function expandDecimalNotation(string $value, string $field): string
    {
        if (! str_contains($value, 'e') && ! str_contains($value, 'E')) {
            return $value;
        }

        if (preg_match('/^([+-]?)(\d+)(?:\.(\d*))?[eE]([+-]?\d+)$/D', $value, $matches) !== 1
            || strlen($matches[4]) > 6
        ) {
            throw new InvalidArgumentException("The {$field} value must be an exact decimal quantity.");
        }

        $sign = $matches[1] === '-' ? '-' : '';
        $whole = $matches[2];
        $fraction = $matches[3] ?? '';
        $exponent = (int) $matches[4];
        $digits = $whole.$fraction;
        $decimalPosition = strlen($whole) + $exponent;

        if ($decimalPosition <= 0) {
            return $sign.'0.'.str_repeat('0', -$decimalPosition).$digits;
        }

        if ($decimalPosition >= strlen($digits)) {
            return $sign.$digits.str_repeat('0', $decimalPosition - strlen($digits));
        }

        return $sign.substr($digits, 0, $decimalPosition).'.'.substr($digits, $decimalPosition);
    }

    private function manualDecimalStringToUnits(string $value, string $field): int
    {
        if (preg_match('/^([0-9]+)(?:\.([0-9]+))?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException("The {$field} value must be an exact decimal quantity.");
        }

        $whole = ltrim($matches[1], '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = rtrim($matches[2] ?? '', '0');
        if (strlen($fraction) > 2) {
            throw new InvalidArgumentException("The {$field} value must have at most two exact decimal places.");
        }

        $fractionUnits = $fraction === '' ? 0 : (int) str_pad($fraction, 2, '0');
        $units = $this->checkedAdd(
            $this->checkedMultiplyNonNegative(
                $this->integerFromString($whole, $field),
                self::MANUAL_QUANTITY_SCALE,
            ),
            $fractionUnits,
        );

        if ($units < 1) {
            throw new InvalidArgumentException("The {$field} value must be at least 0.01.");
        }

        return $units;
    }

    private function manualQuantityStringToUnits(string $value, string $field): int
    {
        if (preg_match('/^\d+(?:\.(\d{1,2}))?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException("The {$field} value must be an exact decimal quantity.");
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $units = $this->checkedAdd(
            $this->checkedMultiplyNonNegative(
                $this->integerFromString($whole, $field),
                self::MANUAL_QUANTITY_SCALE,
            ),
            (int) str_pad($fraction, 2, '0'),
        );

        if ($units < 1) {
            throw new InvalidArgumentException("The {$field} value must be at least 0.01.");
        }

        return $units;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if (($right > 0 && $left > self::MAX_SAFE_INTEGER - $right)
            || ($right < 0 && $left < -self::MAX_SAFE_INTEGER - $right)
        ) {
            throw new InvalidArgumentException('Pricing arithmetic exceeds the safe integer range.');
        }

        return $left + $right;
    }

    private function checkedMultiplyNonNegative(int $left, int $right): int
    {
        if ($left < 0 || $right < 0) {
            throw new InvalidArgumentException('Pricing arithmetic requires non-negative operands.');
        }

        if ($left !== 0 && $right > intdiv(self::MAX_SAFE_INTEGER, $left)) {
            throw new InvalidArgumentException('Pricing arithmetic exceeds the safe integer range.');
        }

        return $left * $right;
    }

    private function percentageDiscountAmount(int $runningTotal, int $rate): int
    {
        $amount = $this->multiplyAndRoundHalfUpNonNegative(
            abs($runningTotal),
            $rate,
            self::PERCENT_SCALE,
        );

        return $runningTotal < 0 ? -$amount : $amount;
    }

    private function multiplyAndRoundHalfUpNonNegative(int $left, int $right, int $scale): int
    {
        if ($left < 0 || $right < 0 || $scale < 1) {
            throw new InvalidArgumentException('Pricing arithmetic requires non-negative operands and a positive scale.');
        }

        $leftQuotient = intdiv($left, $scale);
        $leftRemainder = $left % $scale;
        $rightQuotient = intdiv($right, $scale);
        $rightRemainder = $right % $scale;

        // left * right / scale
        // = leftQuotient * right
        // + leftRemainder * rightQuotient
        // + leftRemainder * rightRemainder / scale.
        $wholeAmount = $this->checkedMultiplyNonNegative($leftQuotient, $right);
        $remainderAmount = $this->checkedMultiplyNonNegative($leftRemainder, $rightQuotient);
        $smallProduct = $this->checkedMultiplyNonNegative($leftRemainder, $rightRemainder);
        $roundedRemainder = intdiv($smallProduct, $scale);
        if ($smallProduct % $scale >= intdiv($scale + 1, 2)) {
            $roundedRemainder++;
        }

        return $this->checkedAdd(
            $wholeAmount,
            $this->checkedAdd($remainderAmount, $roundedRemainder),
        );
    }

    private function formatFixedPoint(int $value, int $scale): int|string
    {
        $whole = intdiv($value, $scale);
        $fraction = $value % $scale;
        $decimalPlaces = strlen((string) $scale) - 1;

        return $fraction === 0
            ? $whole
            : sprintf('%d.%0'.$decimalPlaces.'d', $whole, $fraction);
    }

    private function displayPercentage(int $basisPoints): int|string
    {
        $whole = intdiv($basisPoints, self::DISPLAY_PERCENT_SCALE);
        $fraction = $basisPoints % self::DISPLAY_PERCENT_SCALE;

        return $fraction === 0
            ? $whole
            : sprintf('%d.%02d', $whole, $fraction);
    }

    /** @param list<array<string, mixed>> $lines */
    private function containsNonItem(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line['type'] !== self::ITEM_TYPE) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $lines */
    private function containsItem(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line['type'] === self::ITEM_TYPE) {
                return true;
            }
        }

        return false;
    }
}
