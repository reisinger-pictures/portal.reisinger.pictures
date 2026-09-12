<?php

namespace Tests\Feature;

use App\Models\InvoiceSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P0-B10 (MEDIUM) — the first insert of the yearly invoice sequence must be
 * race-safe (no duplicate-key 500 when two requests create the row together).
 *
 * The full concurrent race cannot be reproduced on the SQLite `:memory:` test
 * DB (single connection). These tests cover the create path and the tolerated
 * duplicate-key path (`insertOrIgnore` on an already-existing row), which is
 * the outcome the losing transaction sees.
 */
class InvoiceSequenceRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_call_creates_the_sequence_row_and_returns_first_number(): void
    {
        $year = (int) date('Y');
        $this->assertDatabaseMissing('invoice_sequences', ['year' => $year]);

        $number = InvoiceSequence::getNextInvoiceNumber('P-');

        $this->assertSame(sprintf('P-%04d-0001', $year), $number);
        $this->assertDatabaseHas('invoice_sequences', ['year' => $year, 'current_value' => 1]);
    }

    public function test_existing_sequence_row_is_tolerated_and_incremented(): void
    {
        $year = (int) date('Y');
        InvoiceSequence::create(['year' => $year, 'current_value' => 5]);

        // insertOrIgnore() must not raise a duplicate-key error on the existing PK.
        $number = InvoiceSequence::getNextInvoiceNumber('L-');

        $this->assertSame(sprintf('L-%04d-0006', $year), $number);
        $this->assertSame(6, (int) InvoiceSequence::where('year', $year)->sole()->current_value);
        $this->assertSame(1, InvoiceSequence::where('year', $year)->count());
    }
}
