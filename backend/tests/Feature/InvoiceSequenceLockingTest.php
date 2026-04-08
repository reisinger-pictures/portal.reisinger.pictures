<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\InvoiceSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;

class InvoiceSequenceLockingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_generates_correct_format_and_increments()
    {
        // Flow W: Generierung und Formatprüfung
        $num1 = InvoiceSequence::getNextInvoiceNumber('P-');
        $num2 = InvoiceSequence::getNextInvoiceNumber('P-');
        $numL = InvoiceSequence::getNextInvoiceNumber('L-');
        
        $year = (int) date('Y');
        
        $this->assertEquals(sprintf('P-%04d-0001', $year), $num1);
        $this->assertEquals(sprintf('P-%04d-0002', $year), $num2);
        
        // Auch bei anderem Präfix wird die Sequence (aktuell pro Jahr global) weitergezählt
        $this->assertEquals(sprintf('L-%04d-0003', $year), $numL);
    }
}
