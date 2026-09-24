<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use Barryvdh\DomPDF\Facade\Pdf;

class InvoiceDownloadController extends Controller
{
    public function downloadInvoice($id, SettingResolver $resolver)
    {
        $order = Order::query()
            ->ownedBy(auth('api')->user())
            ->with('invoiceSnapshot')
            ->findOrFail($id);
        if ($order->is_quote_request && $order->status === 'pending') {
            abort(403, 'Angebot noch nicht abgerechnet.');
        }

        $brand = BrandRegistry::resolveFromOrder($order);

        $previousBrand = BrandRegistry::current();
        BrandRegistry::set($brand);

        try {
            $brandConfig = BrandRegistry::configForBrand($brand->value);

            return Pdf::loadView('pdf.invoice', [
                'order' => $order,
                'snapshot' => $order->invoiceSnapshot,
                'items' => $order->invoiceSnapshot->customer_details['items'] ?? [],
                'bankHolder' => $resolver->get('bank_holder'),
                'bankIban' => $resolver->get('bank_iban'),
                'bankBic' => $resolver->get('bank_bic'),
                'pfx' => $brand->prefix(),
                'primaryColor' => $brandConfig?->primaryColor ?? '#1E5631',
                'secondaryColor' => $brandConfig?->secondaryColor ?? '#A4B494',
            ])->download($order->invoiceSnapshot->invoice_number.'.pdf');
        } finally {
            BrandRegistry::set($previousBrand);
        }
    }
}
