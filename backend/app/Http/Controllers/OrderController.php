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

    public function sendQuote(Request $request, $id) {
        $user = auth('api')->user();
        if (!$user->is_admin && !$user->is_photographer) return response()->json(['error' => 'Keine Berechtigung'], 403);
        $request->validate(['custom_price' => 'required|numeric', 'message' => 'required|string']);
        $order = Order::with(['user', 'invoiceSnapshot'])->findOrFail($id);
        $items = $order->invoiceSnapshot->customer_details['items'] ?? [];
        $photoIds = array_column($items, 'photoId');
        $payload = base64_encode(json_encode(['photos' => $photoIds, 'price' => $request->custom_price, 'exp' => now()->addDays(14)->timestamp]));
        $signature = hash_hmac('sha256', $payload, config('app.key'));
        $link = rtrim(config('app.frontend_url', config('app.url')), '/') . '/cart?quote_token=' . $payload . '.' . $signature;
        $mailBody = '<p>Guten Tag ' . ($order->user->name ?? '') . ',</p>';
        $mailBody .= '<p>' . nl2br(e($request->message)) . '</p>';
        $mailBody .= '<p><br><a href="' . $link . '" style="background-color: #2A9D8F; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 4px; display: inline-block;">Angebot annehmen & Bezahlen</a></p>';
        \Illuminate\Support\Facades\Mail::to($order->user->email)->send(new \App\Mail\CustomMail('Ihr individuelles Angebot', $mailBody));
        return response()->json(['success' => true]);
    }
}