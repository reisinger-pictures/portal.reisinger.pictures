<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Org;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Log;

class ProcessCollectiveInvoices extends Command
{
    protected $signature = 'app:process-collective-invoices {--frequency=monthly : Billing frequency (monthly|quarterly)} {--brand= : Optional brand filter (rp|srp)}';
    protected $description = 'Generiert Sammelrechnungen automatisch am Monats- oder Quartalsende.';

    public function handle(InvoiceService $invoiceService)
    {
        $frequency = $this->option('frequency');

        if (!in_array($frequency, ['monthly', 'quarterly'], true)) {
            $this->error("Ungültige Frequenz: {$frequency}. Erlaubt: monthly, quarterly.");
            return 1;
        }

        $query = Org::where('invoice_frequency', $frequency);

        if ($brand = $this->option('brand')) {
            $query->where('brand', $brand);
        }

        $orgs = $query->get();

        $count = 0;
        foreach ($orgs as $org) {
            $result = $invoiceService->generateForOrg($org);
            if ($result['success']) {
                $this->info("Sammelrechnung {$result['invoice_number']} für {$org->name} erstellt.");
                Log::info("Automated Invoicing: Collective invoice {$result['invoice_number']} generated for org {$org->name}.");
                $count++;
            }
        }

        $this->info("Lauf abgeschlossen. {$count} Sammelrechnungen erstellt.");
        return 0;
    }
}
