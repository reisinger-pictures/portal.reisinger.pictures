<?php

namespace App\Services;

use App\Models\Order;
use App\Models\InvoiceSnapshot;
use App\Models\Photo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMail;

class CheckoutService
{
    protected $pricingService;

    public function __construct(PricingService $pricingService) {
        $this->pricingService = $pricingService;
    }

    public function processCheckout($request, $user, $basePrice, $paymentMethod)
    {
        return DB::transaction(function () use ($request, $user, $basePrice, $paymentMethod) {
            $totalNet = 0.00;
            $lineItems = [];
            $isQuoteRequest = false;

            foreach ($request->items as $item) {
                $photo = Photo::with('gallery')->findOrFail($item['photoId']);
                if (!$photo->gallery->is_public && !$user->canAccessGallery($photo->gallery_id)) abort(403, 'Zugriff verweigert (Nicht öffentlich & keine Rechte)');
                
                $isItemQuote = isset($item['isQuote']) && $item['isQuote'];
                if ($isItemQuote) {
                    $isQuoteRequest = true;
                    $delta = 0.00;
                } else {
                    $delta = $this->pricingService->calculateUpgradeDelta($basePrice, $item['tier'], $item['usage'], $item['duration'], $user->flatrate_level ?? 'none');
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

            $user->update($request->only(['billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city']));

            $tenant = $user->tenants()->first();
            $isLieferschein = $tenant && $tenant->invoice_frequency !== 'immediate';
            $orderStatus = $isQuoteRequest ? 'pending' : ($isLieferschein ? 'delivery_note' : ($paymentMethod === 'invoice' ? 'invoice_created' : 'pending_payment'));

            $order = Order::create([
                'user_id' => $user->id,
                'status' => $orderStatus,
                'total_amount' => $totalNet,
                'is_quote_request' => $isQuoteRequest
            ]);

            $snapshot = InvoiceSnapshot::create([
                'order_id' => $order->id,
                'customer_details' => [
                    'name' => $request->billing_name,
                    'company' => $request->billing_company,
                    'street' => $request->billing_street,
                    'zip' => $request->billing_zip,
                    'city' => $request->billing_city,
                    'email' => $user->email,
                    'country' => 'Österreich',
                    'items' => $lineItems,
                    'quote_message' => $request->quote_message ?? null,
                    'terms' => [] 
                ],
                'total_net' => $totalNet,
                'total_gross' => $totalNet,
                'tax_rate' => 0.00
            ]);

            if ($isQuoteRequest || $isLieferschein || $paymentMethod === 'invoice') {
                if (!$isQuoteRequest) Mail::to($user->email)->queue(new InvoiceMail($order, $snapshot));
                return response()->json(['success' => true, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
            }

            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => (int) round($totalNet * 100),
                'currency' => 'eur',
                'metadata' => ['order_id' => $order->id],
                'receipt_email' => $user->email,
            ], ['idempotency_key' => 'pi_' . $order->id]);

            $order->update(['ip_address' => $request->ip(), 'stripe_payment_intent_id' => $paymentIntent->id]);

            return response()->json(['success' => true, 'requires_action' => true, 'client_secret' => $paymentIntent->client_secret, 'order_id' => $order->id, 'invoice_number' => $snapshot->invoice_number]);
        });
    }
}
