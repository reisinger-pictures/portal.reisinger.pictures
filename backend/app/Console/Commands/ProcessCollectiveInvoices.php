<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Log;

class ProcessCollectiveInvoices extends Command
{
    protected $signature = 'app:process-collective-invoices {--frequency=monthly : Billing frequency (monthly|quarterly)}';
    protected $description = 'Generiert Sammelrechnungen automatisch am Monats- oder Quartalsende.';

    public function handle(InvoiceService $invoiceService)
    {
        $frequency = $this->option('frequency');

        if (!in_array($frequency, ['monthly', 'quarterly'], true)) {
            $this->error("Ungültige Frequenz: {$frequency}. Erlaubt: monthly, quarterly.");
            return 1;
        }

        $tenants = Tenant::where('invoice_frequency', $frequency)->get();

        $count = 0;
        foreach ($tenants as $tenant) {
            $result = $invoiceService->generateForTenant($tenant);
            if ($result['success']) {
                $this->info("Sammelrechnung {$result['invoice_number']} für {$tenant->name} erstellt.");
                Log::info("Automated Invoicing: Collective invoice {$result['invoice_number']} generated for tenant {$tenant->name}.");
                $count++;
            }
        }

        $this->info("Lauf abgeschlossen. {$count} Sammelrechnungen erstellt.");
        return 0;
    }
}
