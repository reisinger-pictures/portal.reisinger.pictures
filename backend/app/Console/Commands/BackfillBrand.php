<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use App\Enums\Brand;

class BackfillBrand extends Command
{
    protected $signature = 'app:backfill-brand';
    protected $description = 'Backfill brand column for existing orders and invoice_snapshots (CLI-safe B2B default).';

    public function handle()
    {
        // CLI/queue context has no HTTP host → config('app.brand') is empty here. The historical
        // default is B2B, so we backfill NULL rows with the explicit B2B enum value instead of
        // reading the unreliable runtime config. See features/infrastructure/12-...md (B-01 F1).
        $updatedOrders = Order::whereNull('brand')->update(['brand' => Brand::B2B->value]);
        $this->info("Orders backfilled: {$updatedOrders}");

        $updatedSnapshots = InvoiceSnapshot::whereNull('brand')->update(['brand' => Brand::B2B->value]);
        $this->info("InvoiceSnapshots backfilled: {$updatedSnapshots}");

        return 0;
    }
}
