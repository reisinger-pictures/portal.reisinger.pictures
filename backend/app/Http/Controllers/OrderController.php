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

    public function checkout(Request $request, PricingService $pricingService)
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
            'items.*.tier' => 'required|string|in:web,print,original',
            'items.*.usage' => 'required|string|in:editorial,commercial',
            'items.*.duration' => 'required|string|in:1_year,unlimited',
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

        return DB::transaction(function () use ($request, $user) {
            $paymentMethod = $request->payment_method ?? 'stripe';
            $totalNet = 0.00;
            $basePrice = (float) (\App\Models\Setting::where('key', 'base_price')->value('value') ?? 35.00);
            $lineItems = [];
            $isQuoteRequest = false;

            // Fachliche Prüfung: Widerrufsverzicht ist bei digitalen Sofort-Käufen Pflicht
        $hasQuotesOnly = collect($request->items)->every(fn($i) => isset($i['isQuote']) && $i['isQuote']);
        if (!$hasQuotesOnly && !$request->withdrawal_waived) {
            return response()->json(['error' => 'Sie müssen auf Ihr Widerrufsrecht verzichten, um digitale Bilddaten zu kaufen.'], 422);
        }

        foreach ($request->items as $item) {
                $photo = Photo::with('gallery')->findOrFail($item['photoId']);
                if (!$photo->gallery->is_public && !$user->canAccessGallery($photo->gallery_id)) abort(403, 'Zugriff verweigert (Nicht öffentlich & keine Rechte)');
                
                $isItemQuote = isset($item['isQuote']) && $item['isQuote'];
                if ($isItemQuote) {
                    $isQuoteRequest = true;
                    $delta = 0.00;
                } else {
                    $resMult = ['web' => 1.0, 'print' => 2.0, 'original' => 4.0][$item['tier']];
                    $useMult = ['editorial' => 1.0, 'commercial' => 3.0][$item['usage']];
                    $durMult = ['1_year' => 1.0, 'unlimited' => 2.0][$item['duration']];
                    
                    $requestedPrice = $basePrice * $resMult * $useMult * $durMult;
                    $userResMult = ['none' => 0.0, 'web' => 1.0, 'print' => 2.0, 'original' => 4.0][$user->flatrate_level ?? 'none'] ?? 0.0;
                    $userPrice = $basePrice * $userResMult * 1.0 * 1.0;
                    
                    $delta = $requestedPrice - $userPrice;
                    if ($delta < 0) $delta = 0;
                }
                
                $totalNet += $delta;

                $lineItems[] = [
                    'photoId' => $photo->id,
                    'filename' => $photo->title ?: 'Bild ' . substr($photo->id, 0, 8),
                    'tier' => $item['tier'],
                    'usage' => $item['usage'],
                    'duration' => $item['duration'],
                    'price' => $delta,
                    'isQuote' => $isItemQuote,
                    'notes' => $item['notes'] ?? null,
                ];
            }

            if ($totalNet <= 0 && !$isQuoteRequest) return response()->json(['error' => 'Warenkorb hat keinen Wert.'], 400);

            $user->update([
                'billing_name' => $request->billing_name,
                'billing_company' => $request->billing_company,
                'billing_street' => $request->billing_street,
                'billing_zip' => $request->billing_zip,
                'billing_city' => $request->billing_city,
            ]);

            $tenant = $user->tenants()->first();
            $isLieferschein = $tenant && $tenant->invoice_frequency !== 'immediate';
            
            if ($isQuoteRequest) {
                $orderStatus = 'pending';
            } else {
                $orderStatus = $isLieferschein ? 'delivery_note' : ($paymentMethod === 'invoice' ? 'invoice_created' : 'pending_payment');
            }

            $order = Order::create([
                'user_id' => $user->id,
                'status' => $orderStatus,
                'total_amount' => $totalNet,
                'is_quote_request' => $isQuoteRequest
            ]);

            $prefix = $isLieferschein ? 'L-' : 'P-';
            $invoiceNumber = $isQuoteRequest ? 'A-' . strtoupper(\Illuminate\Support\Str::random(8)) : InvoiceSequence::getNextInvoiceNumber($prefix);

            $snapshot = InvoiceSnapshot::create([
                'order_id' => $order->id,
                'invoice_number' => $invoiceNumber,
                'customer_details' => [
                    'name' => $request->billing_name,
                    'email' => $user->email,
                    'company' => $request->billing_company,
                    'street' => $request->billing_street,
                    'zip' => $request->billing_zip,
                    'city' => $request->billing_city,
                    'country' => 'Österreich',
                    'items' => $lineItems,
                    'quote_message' => $request->quote_message ?? null,
                    'terms' => [] 
                ],
                'total_net' => $totalNet,
                'total_gross' => $totalNet,
                'tax_rate' => 0.00
            ]);

            if ($isQuoteRequest) {
                return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $invoiceNumber]);
            }

            if ($isLieferschein || $paymentMethod === 'invoice') {
                Mail::to($user->email)->send(new InvoiceMail($order, $snapshot));
                return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $invoiceNumber]);
            }

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => (int) round($totalNet * 100),
                'currency' => 'eur',
                'metadata' => ['order_id' => $order->id],
                'receipt_email' => $user->email,
            ]);

            return response()->json([
                'success' => true,
                'requires_action' => true,
                'client_secret' => $paymentIntent->client_secret,
                'order_id' => $order->id,
                'invoice_number' => $invoiceNumber
            ]);
        });
    }

    public function indexAdmin() { return response()->json(Order::with(['user', 'invoiceSnapshot'])->orderBy('created_at', 'desc')->get()); }
    
    public function updateStatus(Request $request, $id) {
        $request->validate(['status' => 'required|string']);
        Order::findOrFail($id)->update(['status' => $request->status]);
        return response()->json(['success' => true]);
    }
    
    public function generateQuoteLink(Request $request) {
        $user = auth('api')->user();
        if (!$user->is_admin && !$user->is_photographer) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $request->validate(['photo_ids' => 'required|array', 'custom_price' => 'required|numeric']);
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
            'due_date' => 'required|string', // Geändert auf String für Freitext
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
            'items.*.price' => 'required|numeric',
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
                $discountAmt = $runningTotal * ($item['price'] / 100);
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
            'custom_html_terms' => $validated['terms_html'] ?? null
        ];

        $snapshot = new \App\Models\InvoiceSnapshot([
            'invoice_number' => $validated['invoice_number'],
            'customer_details' => $customerDetails,
            'total_net' => $total,
            'total_gross' => $total,
            'tax_rate' => 0
        ]);
        $snapshot->created_at = $validated['date'];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.invoice', [
            'snapshot' => $snapshot,
            'items' => $mappedItems,
            'bankHolder' => \App\Models\Setting::where('key', 'bank_holder')->value('value'),
            'bankIban' => \App\Models\Setting::where('key', 'bank_iban')->value('value'),
            'bankBic' => \App\Models\Setting::where('key', 'bank_bic')->value('value')
        ]);

        return response()->streamDownload(function() use ($pdf) {
            echo $pdf->output();
        }, $validated['invoice_number'] . '.pdf', [
            'Content-Type' => 'application/pdf'
        ]);
    }
}
