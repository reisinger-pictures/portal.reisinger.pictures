<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use App\Models\InvoiceSequence;
use App\Models\Photo;
use App\Services\PricingService;
use App\Services\ManualInvoiceService;
use App\Services\QuoteLinkService;
use App\Http\Resources\OrderResource;
use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\SendQuoteRequest;
use App\Http\Requests\GenerateManualInvoiceRequest;
use App\Services\SettingResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMail;

class OrderController extends Controller
{
    public function __construct(
        private ManualInvoiceService $invoiceService,
        private QuoteLinkService $quoteLinkService
    ) {}

    public function index()
    {
        $orders = Order::where('user_id', auth()->id())
            ->with('invoiceSnapshot')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(OrderResource::collection($orders)->resolve());
    }

    public function checkout(CheckoutRequest $request, \App\Services\CheckoutService $checkoutService, SettingResolver $resolver)
    {
        $user = auth('api')->user();

        $holder = $resolver->get('bank_holder');
        $iban = $resolver->get('bank_iban');
        $street = $resolver->get('company_street');
        if (empty($holder) || empty($iban) || empty($street)) {
            return response()->json(['error' => 'Der Betreiber hat noch keine vollständigen Rechnungsdaten (Impressum & Bank) hinterlegt. Kauf derzeit nicht möglich.'], 400);
        }

        $validated = $request->validated();

        if (\Illuminate\Support\Facades\Gate::denies('purchase-upgrades')) {
            return response()->json(['error' => __('messages.upgrade_not_allowed')], 403);
        }

        $paymentMethod = $request->payment_method ?? 'stripe';
        if ($paymentMethod === 'invoice' && \Illuminate\Support\Facades\Gate::denies('purchase-on-invoice')) {
            return response()->json(['error' => 'Kauf auf Rechnung nicht erlaubt.'], 403);
        }

        
        $hasQuotesOnly = collect($request->items)->every(fn($i) => isset($i['isQuote']) && $i['isQuote']);
        if (!$hasQuotesOnly && !$request->withdrawal_waived) {
            return response()->json(['error' => 'Sie müssen auf Ihr Widerrufsrecht verzichten, um digitale Bilddaten zu kaufen.'], 422);
        }
        return $checkoutService->processCheckout($request, $user, $paymentMethod);
    }

    public function indexAdmin() { return response()->json(\App\Http\Resources\OrderResource::collection(Order::with(['user', 'invoiceSnapshot'])->orderBy('created_at', 'desc')->get())->resolve()); }
    
    public function updateStatus(Request $request, $id) {
        $request->validate(['status' => 'required|string|in:pending,invoice_created,pending_payment,paid,overdue,cancelled,disputed,refunded,delivery_note,archived_in_collective']);
        Order::findOrFail($id)->update(['status' => $request->status]);
        return response()->json(['success' => true]);
    }
    
    public function sendQuote(SendQuoteRequest $request, $id) {
        $user = auth('api')->user();

        $validated = $request->validated();

        $order = Order::with(['user', 'invoiceSnapshot'])->findOrFail($id);
        $order->update(['status' => 'cancelled']);

        $items = $order->invoiceSnapshot->customer_details['items'] ?? [];
        $photoIds = array_column($items, 'photoId');

        $link = $this->quoteLinkService->generateQuoteLink($photoIds, $request->custom_price);

        $subject = "Individuelles Angebot";
        $body = "<p>" . nl2br(htmlspecialchars($request->message)) . "</p><br><p><a href=\"{$link}\">Hier geht es zum Angebot und Checkout</a></p>";

        \Illuminate\Support\Facades\Mail::to($order->user->email)->send(new \App\Mail\CustomMail($subject, $body));

        return response()->json(['success' => true]);
    }

    public function generateQuoteLink(Request $request) {
        $user = auth('api')->user();
        if (!$user->is_admin && !$user->is_photographer) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $request->validate(['photo_ids' => 'required|array', 'custom_price' => 'required|integer']);

        $link = $this->quoteLinkService->generateQuoteLink($request->photo_ids, $request->custom_price);

        return response()->json(['success' => true, 'link' => $link]);
    }

    public function decodeQuoteLink(Request $request) {
        $token = $request->query('token');
        if (!$token) return response()->json(['error' => 'Ungültiges Token-Format.'], 400);

        $data = $this->quoteLinkService->decode($token);
        if (!$data) return response()->json(['error' => 'Angebot abgelaufen oder ungültig.'], 410);

        return response()->json($data);
    }

    public function downloadInvoice($id, SettingResolver $resolver) {
        $order = Order::where('id', $id)->where('user_id', auth()->id())->with('invoiceSnapshot')->firstOrFail();
        if ($order->is_quote_request && $order->status === 'pending') abort(403, 'Angebot noch nicht abgerechnet.');

        $brand = \App\Support\BrandRegistry::resolveFromOrder($order);

        // Temporarily set brand so SettingResolver reads the correct brand scope,
        // then restore to prevent leakage to the rest of the request.
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
                'isSrp' => $brand === \App\Enums\Brand::SRP,
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

        $sanitizer = app(\Symfony\Component\HtmlSanitizer\HtmlSanitizer::class);
        $customerDetails = $this->invoiceService->prepareCustomerDetails($validated, $sanitizer);

        $isOffer = ($validated['type'] ?? 'invoice') === 'offer';
        $docTitle = $isOffer ? 'ANGEBOT' : 'RECHNUNG';
        $filename = $isOffer ? 'Angebot-' . date('Y-m-d') : $validated['invoice_number'];

        $snapshot = new \App\Models\InvoiceSnapshot([
            'invoice_number' => $validated['invoice_number'],
            'customer_details' => array_merge($customerDetails, [
                'service_date' => $validated['service_date'] ?? null,
                'validity' => $validated['validity'] ?? null
            ]),
            'total_net' => $total,
            'total_gross' => $total,
            'tax_rate' => 0
        ]);
        $snapshot->created_at = $validated['date'];

        $viewName = $isOffer ? 'pdf.manual_offer' : 'pdf.invoice';
        $bankDetails = $this->invoiceService->getBankDetails();

        $isSrp = \App\Support\BrandRegistry::isSrp();
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
            'Content-Type' => 'application/pdf'
        ]);
    }

    public function extractOffer(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->is_super_admin) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $request->validate(['pdf' => 'required|file']);
        $content = file_get_contents($request->file('pdf')->getPathname());

        // No marker at all → 404. Invalid/expired marker → 400 (null payload).
        if (!preg_match('/OFFER_JWT:([A-Za-z0-9_\.\-]+)/', $content)) {
            return response()->json(['error' => 'Kein eingebettetes Angebot in diesem PDF gefunden.'], 404);
        }

        $data = $this->invoiceService->extractOfferFromPdf($content);

        if (!$data) {
            return response()->json(['error' => 'Angebot nicht auslesbar oder abgelaufen.'], 400);
        }

        return response()->json($data);
    }
}
