<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\SettingResolver;

class InvoiceDownloadController extends Controller
{
    public function downloadInvoice($id, SettingResolver $resolver)
    {
        $order = Order::where('id', $id)->where('user_id', auth('api')->id())->with('invoiceSnapshot')->firstOrFail();
        if ($order->is_quote_request && $order->status === 'pending') abort(403, 'Angebot noch nicht abgerechnet.');

        $brand = \App\Support\BrandRegistry::resolveFromOrder($order);

        $previousBrand = \App\Support\BrandRegistry::current();
        \App\Support\BrandRegistry::set($brand);

        try {
            $brandConfig = \App\Support\BrandRegistry::configForBrand($brand->value);

            return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', [
                'order' => $order,
                'snapshot' => $order->invoiceSnapshot,
                'items' => $order->invoiceSnapshot->customer_details['items'] ?? [],
                'bankHolder' => $resolver->get('bank_holder'),
                'bankIban' => $resolver->get('bank_iban'),
                'bankBic' => $resolver->get('bank_bic'),
                'pfx' => $brand->prefix(),
                'primaryColor' => $brandConfig?->primaryColor ?? '#1E5631',
                'secondaryColor' => $brandConfig?->secondaryColor ?? '#A4B494',
            ])->download($order->invoiceSnapshot->invoice_number . '.pdf');
        } finally {
            \App\Support\BrandRegistry::set($previousBrand);
        }
    }
}
