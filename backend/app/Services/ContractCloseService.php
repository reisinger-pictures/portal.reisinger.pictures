<?php

namespace App\Services;

use App\Enums\Brand;
use App\Mail\ContractClosedMail;
use App\Models\Contract;
use App\Models\InvoiceSequence;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ContractCloseService
{
    public function close(Contract $contract): void
    {
        $items = $contract->items ?? [];
        $discounts = $contract->discounts ?? [];
        $subtotal = 0;

        foreach ($items as $item) {
            if (($item['type'] ?? 'item') === 'item') {
                $subtotal += ($item['price'] ?? 0) * ($item['qty'] ?? 1);
            }
        }

        foreach ($discounts as $discount) {
            if (($discount['type'] ?? '') === 'discount_fixed') {
                $subtotal -= ($discount['price'] ?? 0);
            } elseif (($discount['type'] ?? '') === 'discount_percent') {
                $subtotal -= (int) round($subtotal * ($discount['price'] ?? 0) / 10000);
            }
        }

        $totalGross = max(0, $subtotal);

        $orderId = null;
        $invoiceNumber = null;

        if ($totalGross > 0 && !empty($contract->billing_details)) {
            DB::transaction(function () use ($contract, $totalGross, &$orderId, &$invoiceNumber) {
                $brand = $contract->brand ?? BrandRegistry::currentOrDefault();

                $userId = null;
                if (!empty($contract->billing_details['email'])) {
                    $billingUser = \App\Models\User::where('email', $contract->billing_details['email'])->first();
                    $userId = $billingUser?->id;
                }

                $order = Order::create([
                    'user_id' => $userId,
                    'status' => 'invoice_created',
                    'total_amount' => $totalGross,
                    'is_quote_request' => false,
                    'brand' => $brand,
                ]);

                $invoiceNumber = InvoiceSequence::getNextInvoiceNumber('P-');

                InvoiceSnapshot::create([
                    'invoice_number' => $invoiceNumber,
                    'order_id' => $order->id,
                    'brand' => $brand,
                    'customer_details' => array_merge(
                        $contract->billing_details ?? [],
                        ['items' => $contract->items ?? [], 'terms' => $contract->terms_html ?? '']
                    ),
                    'total_net' => $totalGross,
                    'total_gross' => $totalGross,
                    'tax_rate' => null,
                ]);

                $orderId = $order->id;
            });
        }

        $recipients = $contract->signers->pluck('email')->unique()->toArray();

        if (!empty($contract->billing_details['email'])) {
            $recipients[] = $contract->billing_details['email'];
        }

        $recipients = array_unique(array_filter($recipients));

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->queue(new ContractClosedMail($contract));
        }
    }
}
