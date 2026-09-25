<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateManualInvoiceRequest;
use App\Models\InvoiceSnapshot;
use App\Services\ManualInvoiceService;
use App\Support\BrandRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;

class InvoiceController extends Controller
{
    public function __construct(
        private ManualInvoiceService $invoiceService,
        private HtmlSanitizer $sanitizer,
    ) {}

    public function generateManualInvoice(GenerateManualInvoiceRequest $request)
    {
        $user = auth('api')->user();
        $validated = $request->validated();
        $validated['invoice_number'] = $this->normalizeInvoiceNumber($validated['invoice_number']);

        try {
            $processed = $this->invoiceService->processItems($validated['items']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'items' => $exception->getMessage(),
            ]);
        }
        $mappedItems = $processed['items'];
        $total = $processed['total'];

        $customerDetails = $this->invoiceService->prepareCustomerDetails($validated, $this->sanitizer);

        $isOffer = ($validated['type'] ?? 'invoice') === 'offer';
        $docTitle = $isOffer ? 'ANGEBOT' : 'RECHNUNG';
        $filename = $isOffer ? 'Angebot-'.date('Y-m-d') : $validated['invoice_number'];

        $snapshot = new InvoiceSnapshot([
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

        $pfx = BrandRegistry::prefix();
        $brandConfig = BrandRegistry::configOrDefault();

        $pdf = Pdf::loadView($viewName, [
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

        return response()->streamDownload(function () use ($output) {
            echo $output;
        }, $filename.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * Normalise and validate a manually supplied document number.
     *
     * Manual documents are rendered on the fly (not persisted), so a full
     * duplicate guarantee would require persisting them. We can still reject
     * malformed numbers and numbers that collide with an already persisted
     * invoice snapshot (which shares the global `invoice_number` primary key).
     */
    private function normalizeInvoiceNumber(string $invoiceNumber): string
    {
        $invoiceNumber = trim($invoiceNumber);

        if ($invoiceNumber === '' || strlen($invoiceNumber) > 64 || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]*$/', $invoiceNumber)) {
            throw ValidationException::withMessages([
                'invoice_number' => 'Ungültige Rechnungsnummer. Erlaubt sind Buchstaben, Ziffern, Punkt, Bindestrich, Unterstrich und Schrägstrich (max. 64 Zeichen).',
            ]);
        }

        if (InvoiceSnapshot::where('invoice_number', $invoiceNumber)->exists()) {
            throw ValidationException::withMessages([
                'invoice_number' => 'Diese Rechnungsnummer ist bereits vergeben.',
            ]);
        }

        return $invoiceNumber;
    }
}
