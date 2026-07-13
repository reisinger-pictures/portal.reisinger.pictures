<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Services\SettingResolver;
use App\Http\Requests\GenerateManualInvoiceRequest;

class InvoiceController extends Controller
{
    public function __construct(
        private \App\Services\ManualInvoiceService $invoiceService,
        private \Symfony\Component\HtmlSanitizer\HtmlSanitizer $sanitizer,
    ) {}

    public function downloadInvoice($id, SettingResolver $resolver)
    {
        $order = Order::where('id', $id)->where('user_id', auth('api')->id())->with('invoiceSnapshot')->firstOrFail();
        if ($order->is_quote_request && $order->status === 'pending') abort(403, 'Angebot noch nicht abgerechnet.');

        $brand = \App\Support\BrandRegistry::resolveFromOrder($order);

        $previousBrand = \App\Support\BrandRegistry::current();
        \App\Support\BrandRegistry::set($brand);

        try {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', [
                'order' => $order,
                'snapshot' => $order->invoiceSnapshot,
                'items' => $order->invoiceSnapshot->customer_details['items'] ?? [],
                'bankHolder' => $resolver->get('bank_holder'),
                'bankIban' => $resolver->get('bank_iban'),
                'bankBic' => $resolver->get('bank_bic'),
                'isSrp' => $brand->value === 'srp',
                'pfx' => $brand->prefix(),
            ])->download($order->invoiceSnapshot->invoice_number . '.pdf');
        } finally {
            \App\Support\BrandRegistry::set($previousBrand);
        }
    }

    public function generateManualInvoice(GenerateManualInvoiceRequest $request)
    {
        $user = auth('api')->user();
        $validated = $request->validated();

        $processed = $this->invoiceService->processItems($validated['items']);
        $mappedItems = $processed['items'];
        $total = $processed['total'];

        $customerDetails = $this->invoiceService->prepareCustomerDetails($validated, $this->sanitizer);

        $isOffer = ($validated['type'] ?? 'invoice') === 'offer';
        $docTitle = $isOffer ? 'ANGEBOT' : 'RECHNUNG';
        $filename = $isOffer ? 'Angebot-' . date('Y-m-d') : $validated['invoice_number'];

        $snapshot = new \App\Models\InvoiceSnapshot([
            'invoice_number' => $validated['invoice_number'],
            'customer_details' => array_merge($customerDetails, [
                'service_date' => $validated['service_date'] ?? null,
                'validity' => $validated['validity'] ?? null,
            ]),
            'total_net' => $total,
            'total_gross' => $total,
            'tax_rate' => 0,
        ]);
        $snapshot->created_at = $validated['date'];

        $viewName = $isOffer ? 'pdf.manual_offer' : 'pdf.invoice';
        $bankDetails = $this->invoiceService->getBankDetails();

        $isSrp = \App\Support\BrandRegistry::configOrDefault()->id === 'srp';
        $pfx = \App\Support\BrandRegistry::prefix();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($viewName, [
            'title' => $docTitle,
            'snapshot' => $snapshot,
            'items' => $mappedItems,
            'bankHolder' => $bankDetails['holder'],
            'bankIban' => $bankDetails['iban'],
            'bankBic' => $bankDetails['bic'],
            'isSrp' => $isSrp,
            'pfx' => $pfx,
        ]);

        $output = $pdf->output();

        if ($isOffer) {
            $offerData = $this->invoiceService->prepareOfferData($validated);
            $payloadData = $this->invoiceService->generateOfferPayload($offerData);
            $output .= "\n{$payloadData['marker']}\n";
        }

        return response()->streamDownload(function() use ($output) {
            echo $output;
        }, $filename . '.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
