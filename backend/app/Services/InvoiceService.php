<?php

namespace App\Services;

use App\Models\Org;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use App\Models\InvoiceSequence;
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class InvoiceService
{
    public function generateForOrg(Org $org, $initiator = null)
    {
        return DB::transaction(function () use ($org, $initiator) {
            $orgUserIds = $org->users()->pluck('users.id')->toArray();

            try {
                $openOrders = Order::whereIn('user_id', $orgUserIds)
                    ->where('status', 'delivery_note')
                    ->with(['invoiceSnapshot', 'user'])
                    ->lockForUpdate()
                    ->get();
            } catch (\Illuminate\Database\QueryException $e) {
                if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'lock wait timeout')) {
                    return ['success' => false, 'error' => 'Server ist derzeit überlastet. Bitte versuche es in einigen Sekunden erneut.'];
                }
                throw $e;
            }

            if ($openOrders->isEmpty()) {
                return ['success' => false, 'error' => 'Keine offenen Lieferscheine für diese Organisation gefunden.'];
            }

            $totalNet = 0.00;
            $totalGross = 0.00;
            $allItems = [];
            $usedTerms = [];

            foreach ($openOrders as $order) {
                $snap = $order->invoiceSnapshot;
                if ($snap) {
                    $totalNet += $snap->total_net;
                    $totalGross += $snap->total_gross;
                    
                    $items = $snap->customer_details['items'] ?? [];
                    foreach ($items as $item) {
                        $item['ordered_by'] = $order->user->name ?? 'Unbekannt';
                        $item['original_order_id'] = $order->id;
                        $allItems[] = $item;
                    }

                    $terms = $snap->customer_details['terms'] ?? [];
                    foreach ($terms as $k => $v) {
                        $usedTerms[$k] = $v;
                    }
                }
                // Alten Lieferschein archivieren
                $order->update(['status' => 'archived_in_collective']);
            }

            $fallbackUser = $org->users()->first();
            $billingStreet = $initiator ? $initiator->billing_street : ($fallbackUser->billing_street ?? 'Firmenadresse');
            $billingZip = $initiator ? $initiator->billing_zip : ($fallbackUser->billing_zip ?? '0000');
            $billingCity = $initiator ? $initiator->billing_city : ($fallbackUser->billing_city ?? 'Unbekannt');

            $brand = $org->brand?->value ?? $openOrders->first()->brand ?? \App\Enums\Brand::B2B->value;

            $collectiveOrder = Order::create([
                'user_id' => $initiator ? $initiator->id : ($fallbackUser->id ?? null),
                'status' => 'invoice_created',
                'brand' => $brand,
                'total_amount' => $totalGross
            ]);

            $invoiceNumber = InvoiceSequence::getNextInvoiceNumber('P-');

            $snapshot = InvoiceSnapshot::create([
                'order_id' => $collectiveOrder->id,
                'invoice_number' => $invoiceNumber,
                'brand' => $brand,
                'customer_details' => [
                    'name' => $org->name,
                    'email' => $initiator ? $initiator->email : ($fallbackUser->email ?? config('mail.from.address')),
                    'company' => $org->name,
                    'street' => $billingStreet,
                    'zip' => $billingZip,
                    'city' => $billingCity,
                    'country' => 'Österreich',
                    'items' => $allItems,
                    'terms' => $usedTerms,
                    'is_collective' => true
                ],
                'total_net' => $totalNet,
                'total_gross' => $totalGross,
                'tax_rate' => null
            ]);

            $mailTo = $initiator ? $initiator->email : $fallbackUser->email;
            Mail::to($mailTo)->queue(new InvoiceMail($collectiveOrder, $snapshot));

            return [
                'success' => true, 
                'invoice_number' => $invoiceNumber, 
                'processed_orders' => $openOrders->count()
            ];
        });
    }
}
