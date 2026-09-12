<?php

namespace App\Console\Commands;

use App\Models\Org;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessCollectiveInvoices extends Command
{
    protected $signature = 'app:process-collective-invoices {--frequency=monthly : Billing frequency (monthly|quarterly)} {--brand= : Optional brand filter (brand ID from brands table)}';

    protected $description = 'Generiert Sammelrechnungen automatisch am Monats- oder Quartalsende.';

    public function handle(InvoiceService $invoiceService)
    {
        $frequency = $this->option('frequency');

        if (! in_array($frequency, ['monthly', 'quarterly'], true)) {
            $this->error("Ungültige Frequenz: {$frequency}. Erlaubt: monthly, quarterly.");

            return 1;
        }

        $query = Org::where('invoice_frequency', $frequency);

        if ($brand = $this->option('brand')) {
            $query->where('brand', $brand);
        }

        $orgs = $query->get();

        $count = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($orgs as $org) {
            try {
                $result = $invoiceService->generateForOrg($org);
            } catch (\Throwable $e) {
                // An unexpected exception (e.g. a deadlock outside the guarded
                // query) must not abort the whole batch silently.
                $this->reportFailure($org, $e->getMessage());
                $failed++;

                continue;
            }

            if ($result['success'] ?? false) {
                $this->info("Sammelrechnung {$result['invoice_number']} für {$org->name} erstellt.");
                Log::info("Automated Invoicing: Collective invoice {$result['invoice_number']} generated for org {$org->name}.");
                $count++;

                continue;
            }

            $error = $result['error'] ?? 'Unbekannter Fehler';

            // "Nothing to bill" is an expected no-op, not a failure.
            if ($error === 'Keine offenen Lieferscheine für diese Organisation gefunden.') {
                $this->line("Übersprungen: {$org->name} (keine offenen Lieferscheine).");
                $skipped++;

                continue;
            }

            $this->reportFailure($org, $error);
            $failed++;
        }

        $this->info("Lauf abgeschlossen. {$count} Sammelrechnungen erstellt, {$skipped} übersprungen, {$failed} fehlgeschlagen.");

        // A non-zero exit code signals partial/total failure to the scheduler
        // and operators instead of reporting a successful run.
        return $failed > 0 ? 1 : 0;
    }

    private function reportFailure(Org $org, string $error): void
    {
        $this->error("Fehler für {$org->name}: {$error}");

        Log::error('Automated Invoicing: Failed to generate collective invoice', [
            'org_id' => $org->id,
            'org_name' => $org->name,
            'error' => $error,
        ]);
    }
}
