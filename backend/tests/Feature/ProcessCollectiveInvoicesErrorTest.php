<?php

namespace Tests\Feature;

use App\Models\Org;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Regression: the command only inspected `success` and silently skipped
 * failures (including deadlock/overload), while still reporting a completed
 * run with exit code 0.
 */
class ProcessCollectiveInvoicesErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_invoice_generation_returns_non_zero_and_is_logged(): void
    {
        Org::create(['name' => 'Broken Org', 'invoice_frequency' => 'monthly']);

        $this->mock(InvoiceService::class, function ($mock) {
            $mock->shouldReceive('generateForOrg')->once()->andReturn([
                'success' => false,
                'error' => 'Server ist derzeit überlastet. Bitte versuche es in einigen Sekunden erneut.',
            ]);
        });

        Log::spy();

        $this->artisan('app:process-collective-invoices')->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => $message === 'Automated Invoicing: Failed to generate collective invoice')
            ->once();
    }

    public function test_no_open_delivery_notes_is_a_skip_not_a_failure(): void
    {
        Org::create(['name' => 'Empty Org', 'invoice_frequency' => 'monthly']);

        $this->mock(InvoiceService::class, function ($mock) {
            $mock->shouldReceive('generateForOrg')->once()->andReturn([
                'success' => false,
                'error' => 'Keine offenen Lieferscheine für diese Organisation gefunden.',
            ]);
        });

        $this->artisan('app:process-collective-invoices')->assertExitCode(0);
    }

    public function test_exception_during_generation_is_caught_and_reported(): void
    {
        Org::create(['name' => 'Throwing Org', 'invoice_frequency' => 'monthly']);

        $this->mock(InvoiceService::class, function ($mock) {
            $mock->shouldReceive('generateForOrg')->once()->andThrow(
                new \RuntimeException('Deadlock detected, try restarting transaction')
            );
        });

        Log::spy();

        $this->artisan('app:process-collective-invoices')->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => $message === 'Automated Invoicing: Failed to generate collective invoice')
            ->once();
    }
}
