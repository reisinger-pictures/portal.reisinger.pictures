<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\InvoiceSnapshot;
use App\Models\InvoiceSequence;
use App\Models\Photo;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMail;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::where('user_id', auth()->id())
            ->with('invoiceSnapshot')
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($orders);
    }

    public function checkout(Request $request, \App\Services\CheckoutService $checkoutService)
    {
        $user = auth('api')->user();

        $holder = \App\Models\Setting::where('key', 'bank_holder')->value('value');
        $iban = \App\Models\Setting::where('key', 'bank_iban')->value('value');
        $street = \App\Models\Setting::where('key', 'company_street')->value('value');
        if (empty($holder) || empty($iban) || empty($street)) {
            return response()->json(['error' => 'Der Betreiber hat noch keine vollständigen Rechnungsdaten (Impressum & Bank) hinterlegt. Kauf derzeit nicht möglich.'], 400);
        }

        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.photoId' => 'required|string|exists:photos,id',
            'items.*.tier' => 'nullable|string|in:web,print,original',
            'items.*.useCaseId' => 'nullable|string|exists:license_use_cases,id',
            'items.*.modifierIds' => 'nullable|array',
            'items.*.modifierIds.*' => 'string|exists:license_modifiers,id',
            'items.*.isQuote' => 'boolean',
            'items.*.notes' => 'nullable|string',
            'billing_name' => 'required|string|max:255',
            'billing_company' => 'nullable|string|max:255',
            'billing_street' => 'required|string|max:255',
            'billing_zip' => 'required|string|max:20',
            'billing_city' => 'required|string|max:255',
            'payment_method' => 'nullable|string|in:stripe,invoice',
            'quote_message' => 'nullable|string',
            'withdrawal_waived' => 'required|boolean',
        ]);

        $isClient = $user->roles()->where('name', 'client')->exists();
        // Jeder darf via Stripe einkaufen. Rolle wird nur für Rechnungskauf geprüft.

        $paymentMethod = $request->payment_method ?? 'stripe';
        if ($paymentMethod === 'invoice' && !$isClient && !$user->is_power_user && !$user->is_admin) {
            return response()->json(['error' => 'Kauf auf Rechnung nicht erlaubt.'], 403);
        }

        
        $hasQuotesOnly = collect($request->items)->every(fn($i) => isset($i['isQuote']) && $i['isQuote']);
        if (!$hasQuotesOnly && !$request->withdrawal_waived) {
            return response()->json(['error' => 'Sie müssen auf Ihr Widerrufsrecht verzichten, um digitale Bilddaten zu kaufen.'], 422);
        }
        return $checkoutService->processCheckout($request, $user, $paymentMethod);
    }

    public function indexAdmin() { return response()->json(Order::with(['user', 'invoiceSnapshot'])->orderBy('created_at', 'desc')->get()); }
    
    public function updateStatus(Request $request, $id) {
        $request->validate(['status' => 'required|string|in:pending,invoice_created,pending_payment,paid,overdue,cancelled,disputed,refunded,delivery_note,archived_in_collective']);
        Order::findOrFail($id)->update(['status' => $request->status]);
        return response()->json(['success' => true]);
    }
    
    public function sendQuote(Request $request, $id) {
        $user = auth('api')->user();
        if (!$user->is_admin && !$user->is_photographer) return response()->json(['error' => 'Keine Berechtigung'], 403);
        
        $request->validate([
            'custom_price' => 'required|integer',
            'message' => 'required|string'
        ]);

        $order = Order::with(['user', 'invoiceSnapshot'])->findOrFail($id);
        $order->update(['status' => 'cancelled']);

        $items = $order->invoiceSnapshot->customer_details['items'] ?? [];
        $photoIds = array_column($items, 'photoId');

        $payload = base64_encode(json_encode([
            'photos' => $photoIds,
            'price' => $request->custom_price,
            'exp' => now()->addDays(14)->timestamp
        ]));
        $signature = hash_hmac('sha256', $payload, config('app.key'));
        $link = rtrim(config('app.frontend_url', config('app.url')), '/') . '/cart?quote_token=' . $payload . '.' . $signature;

        $subject = "Individuelles Angebot";
        $body = "<p>" . nl2br(htmlspecialchars($request->message)) . "</p><br><p><a href=\"{$link}\">Hier geht es zum Angebot und Checkout</a></p>";

        \Illuminate\Support\Facades\Mail::to($order->user->email)->send(new \App\Mail\CustomMail($subject, $body));

        return response()->json(['success' => true]);
    }

    public function generateQuoteLink(Request $request) {
        $user = auth('api')->user();
        if (!$user->is_admin && !$user->is_photographer) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $request->validate(['photo_ids' => 'required|array', 'custom_price' => 'required|integer']);
        $payload = base64_encode(json_encode(['photos' => $request->photo_ids, 'price' => $request->custom_price, 'exp' => now()->addDays(14)->timestamp]));
        $signature = hash_hmac('sha256', $payload, config('app.key'));
        return response()->json(['success' => true, 'link' => rtrim(config('app.frontend_url', config('app.url')), '/') . '/cart?quote_token=' . $payload . '.' . $signature]);
    }

    public function decodeQuoteLink(Request $request) {
        $token = $request->query('token');
        if (!$token || strpos($token, '.') === false) return response()->json(['error' => 'Invalid token format'], 400);
        list($payload, $signature) = explode('.', $token, 2);
        if (!hash_equals(hash_hmac('sha256', $payload, config('app.key')), $signature)) return response()->json(['error' => 'Invalid signature'], 400);
        $data = json_decode(base64_decode($payload), true);
        if (isset($data['exp']) && $data['exp'] < time()) return response()->json(['error' => 'Token expired'], 400);
        return response()->json($data);
    }

    public function downloadInvoice($id) {
        $order = Order::where('id', $id)->where('user_id', auth()->id())->with('invoiceSnapshot')->firstOrFail();
        if ($order->is_quote_request && $order->status === 'pending') abort(403, 'Angebot noch nicht abgerechnet.');
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', ['order' => $order, 'snapshot' => $order->invoiceSnapshot, 'items' => $order->invoiceSnapshot->customer_details['items'] ?? []])->download($order->invoiceSnapshot->invoice_number . '.pdf');
    }

    
    
    public function generateManualInvoice(Request $request)
    {
        $user = auth('api')->user();
        if (!$user || !$user->is_super_admin) {
            return response()->json(['error' => 'Keine Berechtigung'], 403);
        }

        $validated = $request->validate([
            'invoice_number' => 'required|string',
            'date' => 'required|date',
            'due_date' => 'required|string',
            'type' => 'nullable|string|in:invoice,offer',
            'service_date' => 'nullable|string',
            'validity' => 'nullable|string',
            'customer_name' => 'nullable|string',
            'customer_company' => 'nullable|string',
            'customer_street' => 'nullable|string',
            'customer_zip' => 'nullable|string',
            'customer_city' => 'nullable|string',
            'customer_country' => 'nullable|string',
            'customer_email' => 'nullable|email',
            'customer_uid' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.type' => 'required|string|in:item,discount_fixed,discount_percent',
            'items.*.description' => 'required|string',
            'items.*.notes' => 'nullable|string',
            'items.*.price' => 'required|integer',
            'items.*.qty' => 'required|numeric|min:0.01',
            'terms_html' => 'nullable|string'
        ]);

        $runningTotal = 0;
        $mappedItems = [];

        foreach ($validated['items'] as $item) {
            if ($item['type'] === 'item') {
                $rowTotal = $item['price'] * $item['qty'];
                $runningTotal += $rowTotal;
                $mappedItems[] = [
                    'type' => 'item',
                    'filename' => $item['description'],
                    'notes' => $item['notes'] ?? '',
                    'tier' => 'custom',
                    'qty' => $item['qty'],
                    'price' => $item['price'],
                    'row_total' => $rowTotal
                ];
            } elseif ($item['type'] === 'discount_fixed') {
                $runningTotal -= $item['price'];
                $mappedItems[] = [
                    'type' => 'discount_fixed',
                    'filename' => $item['description'],
                    'notes' => $item['notes'] ?? '',
                    'tier' => 'custom',
                    'qty' => 1,
                    'price' => $item['price'],
                    'row_total' => -$item['price']
                ];
            } elseif ($item['type'] === 'discount_percent') {
                $discountAmt = (int) round($runningTotal * ($item['price'] / 10000));
                $runningTotal -= $discountAmt;
                $mappedItems[] = [
                    'type' => 'discount_percent',
                    'filename' => $item['description'],
                    'notes' => $item['notes'] ?? '',
                    'tier' => 'custom',
                    'qty' => 1,
                    'price' => $item['price'], 
                    'row_total' => -$discountAmt,
                    'calculated_percentage' => $item['price']
                ];
            }
        }

        $total = max(0, $runningTotal);
        
        $sanitizer = app(\Symfony\Component\HtmlSanitizer\HtmlSanitizer::class);
        $customerDetails = [
            'name' => $validated['customer_name'] ?? '',
            'company' => $validated['customer_company'] ?? '',
            'street' => $validated['customer_street'] ?? '',
            'zip' => $validated['customer_zip'] ?? '',
            'city' => $validated['customer_city'] ?? '',
            'country' => $validated['customer_country'] ?? '',
            'email' => $validated['customer_email'] ?? '',
            'uid' => $validated['customer_uid'] ?? '',
            'due_date' => $validated['due_date'], // Übernimmt den Freitext exakt so
            'is_collective' => false,
            'custom_html_terms' => isset($validated['terms_html']) ? $sanitizer->sanitize($validated['terms_html']) : null
        ];

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
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($viewName, [
            'title' => $docTitle,
            'snapshot' => $snapshot,
            'items' => $mappedItems,
            'bankHolder' => \App\Models\Setting::where('key', 'bank_holder')->value('value'),
            'bankIban' => \App\Models\Setting::where('key', 'bank_iban')->value('value'),
            'bankBic' => \App\Models\Setting::where('key', 'bank_bic')->value('value')
        ]);

        $output = $pdf->output();

        if ($isOffer) {
            $smartData = [
                'customer_name' => $validated['customer_name'] ?? '',
                'customer_company' => $validated['customer_company'] ?? '',
                'customer_street' => $validated['customer_street'] ?? '',
                'customer_zip' => $validated['customer_zip'] ?? '',
                'customer_city' => $validated['customer_city'] ?? '',
                'customer_country' => $validated['customer_country'] ?? '',
                'customer_email' => $validated['customer_email'] ?? '',
                'customer_uid' => $validated['customer_uid'] ?? '',
                'items' => $validated['items'] ?? [],
                'terms_html' => $validated['terms_html'] ?? ''
            ];
            $payload = base64_encode(json_encode($smartData));
            $signature = hash_hmac('sha256', $payload, config('app.key'));
            $output .= "\n%SMART_DOC:{$payload}.{$signature}%\n";
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

        $request->validate(['pdf' => 'required|file']); // MIME type check removed, we rely on the HMAC signature instead
        $content = file_get_contents($request->file('pdf')->getPathname());
        
        if (preg_match('/%SMART_DOC:(.*?)\.(.*?)%/', $content, $matches)) {
            $payload = $matches[1];
            $signature = $matches[2];
            if (hash_equals(hash_hmac('sha256', $payload, config('app.key')), $signature)) {
                return response()->json(json_decode(base64_decode($payload), true));
            }
            return response()->json(['error' => 'Signatur ungültig oder manipuliert. Das Angebot wurde eventuell verändert.'], 400);
        }
        return response()->json(['error' => 'Kein eingebettetes Angebot in diesem PDF gefunden.'], 404);
    }
}
