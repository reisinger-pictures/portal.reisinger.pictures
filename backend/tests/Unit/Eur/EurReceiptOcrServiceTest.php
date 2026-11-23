<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Services\Eur\EurReceiptOcrService;
use PHPUnit\Framework\TestCase;

final class EurReceiptOcrServiceTest extends TestCase
{
    private EurReceiptOcrService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EurReceiptOcrService;
    }

    public function test_hints_extract_receipt_date_and_last_amount(): void
    {
        $text = "Musterfirma\nBeleg Nr: R-2026-42\n15.05.2026\nZwischensumme 10,00 €\nGesamt 1.234,56 €";

        self::assertSame([
            'receipt_no' => 'R-2026-42',
            'date' => '2026-05-15',
            'amount_cents' => 123456,
        ], $this->service->hints($text));
    }

    public function test_hints_are_all_null_without_matches(): void
    {
        self::assertSame([
            'receipt_no' => null,
            'date' => null,
            'amount_cents' => null,
        ], $this->service->hints('keine strukturierten Daten'));
    }

    public function test_hints_ignore_an_impossible_date(): void
    {
        self::assertNull($this->service->hints('31.02.2026')['date']);
    }

    public function test_a_fake_runner_receives_the_original_bytes(): void
    {
        $service = new EurReceiptOcrService(static fn (string $bytes): string => 'Beleg Nr: '.bin2hex(substr($bytes, 0, 3)));

        self::assertSame(
            ['receipt_no' => '89504E', 'date' => null, 'amount_cents' => null],
            $service->hints($service->recognise("\x89PNG...") ?? ''),
        );
    }
}
