<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Tenant;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessCollectiveInvoices extends Command
{
    protected $signature = 'app:process-collective-invoices';
    protected $description = 'Generiert Sammelrechnungen automatisch am Monats- oder Quartalsende.';

    public function handle(InvoiceService $invoiceService)
    {
        $today = Carbon::today();
        $isEndOfMonth = $today->copy()->endOfMonth()->isToday();
        $isEndOfQuarter = $today->copy()->endOfQuarter()->isToday();

        if (!$isEndOfMonth && !$isEndOfQuarter) {
            $this->info('Heute ist weder Monats- noch Quartalsende. Nichts zu tun.');
            return 0;
        }

        $tenants = Tenant::where(function($q) use ($isEndOfMonth, $isEndOfQuarter) {
            if ($isEndOfMonth) $q->orWhere('invoice_frequency', 'monthly');
            if ($isEndOfQuarter) $q->orWhere('invoice_frequency', 'quarterly');
        })->get();

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
