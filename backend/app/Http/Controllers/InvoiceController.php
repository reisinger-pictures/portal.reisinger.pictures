<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\GenerateManualInvoiceRequest;

class InvoiceController extends Controller
{
    public function __construct(
        private \App\Services\ManualInvoiceService $invoiceService,
        private \Symfony\Component\HtmlSanitizer\HtmlSanitizer $sanitizer,
    ) {}

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

        $pfx = \App\Support\BrandRegistry::prefix();
        $brandConfig = \App\Support\BrandRegistry::configOrDefault();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($viewName, [
            'title' => $docTitle,
            'snapshot' => $snapshot,
            'items' => $mappedItems,
            'bankHolder' => $bankDetails['holder'],
            'bankIban' => $bankDetails['iban'],
            'bankBic' => $bankDetails['bic'],
            'pfx' => $pfx,
            'primaryColor' => $brandConfig->primaryColor,
            'secondaryColor' => $brandConfig->secondaryColor,
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
