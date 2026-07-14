<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Log;
use App\Support\BrandRegistry;
use Illuminate\Support\Facades\Cache;

class WebhookController extends Controller
{
    private \App\Services\StripePaymentService $stripePayment;

    public function __construct(?\App\Services\StripePaymentService $stripePayment = null)
    {
        $this->stripePayment = $stripePayment ?? app(\App\Services\StripePaymentService::class);
    }

    public function handleStripe(Request $request)
    {
        $payload = $request->getContent();
        $sig_header = $request->header('Stripe-Signature');
        $endpoint_secret = config('services.stripe.webhook_secret');

        // Lokaler Entwicklungs-Fallback: Lese das Secret live aus der Datei des Auto-Tunnelers
        $secretFile = storage_path('app/private/stripe_secret.txt');
        if (empty($endpoint_secret) && file_exists($secretFile)) {
            $endpoint_secret = trim(file_get_contents($secretFile));
        }

        // Splitte kommagetrennte Secrets für Multi-Domain-Infrastrukturen auf
        $secrets = array_filter(array_map('trim', explode(',', $endpoint_secret)));
        $event = null;
        $lastException = null;

        foreach ($secrets as $secret) {
            try {
                $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $secret);
                $lastException = null;
                break; // Gültige Signatur gefunden, Schleife abbrechen!
            } catch (\Stripe\Exception\SignatureVerificationException $e) {
                $lastException = $e;
            } catch (\UnexpectedValueException $e) {
                Log::error('Stripe Webhook Error: Invalid payload', ['exception' => $e->getMessage()]);
                return response()->json(['error' => 'Invalid payload'], 400);
            }
        }

        // Falls kein Secret passte, schlage lautstark fehl
        if ($lastException || !$event) {
            Log::error('Stripe Webhook Error: Invalid signature across all configured secrets', [
                'exception' => $lastException ? $lastException->getMessage() : 'No secrets configured',
                'configured_secrets_count' => count($secrets)
            ]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        if ($event->type === 'payment_intent.succeeded') {
            $paymentIntent = $event->data->object;
            $orderId = $paymentIntent->metadata->order_id ?? null;
            
            if ($orderId) {
                $order = Order::with(['user', 'invoiceSnapshot'])->find($orderId);
                if ($order && $order->status !== 'paid') {
                    // Amount verification: reject underpaid intents. Both values
                    // are integer cents in EUR, so no conversion is needed.
                    // Returns 200 so Stripe does not retry a webhook that is
                    // legitimately signed but reflects an underpayment (fraud
                    // scenario, not a malformed webhook). Logged for manual review.
                    $expectedCents = (int) $order->total_amount;
                    $receivedCents = (int) ($paymentIntent->amount_received ?? 0);
                    if ($receivedCents < $expectedCents) {
                        Log::warning("Stripe Webhook: underpaid intent for Order {$orderId}", [
                            'expected_cents' => $expectedCents,
                            'received_cents' => $receivedCents,
                        ]);
                        return response()->json(['status' => 'ignored', 'reason' => 'underpaid'], 200);
                    }

                    $feeCents = $this->stripePayment->retrievePaymentIntentWithFee($paymentIntent->id);
                    
                    if ($feeCents === 0) {
                        Log::warning("Stripe Webhook: Balance transaction missing or fee is 0 for Order {$orderId}");
                    }

                    $order->update([
                        'status' => 'paid',
                        'stripe_fee_cents' => $feeCents
                    ]);
                    if ($order->user && !Cache::has('invoice_sent_' . $order->id)) {
                        Mail::to($order->user->email)->queue(new InvoiceMail($order, $order->invoiceSnapshot));
                        Cache::put('invoice_sent_' . $order->id, true, now()->addDays(7));
                    }
                }
            }
        } elseif ($event->type === 'charge.dispute.created') {
            $dispute = $event->data->object;
            $piId = $dispute->payment_intent ?? null;
            if ($piId === null) {
                Log::warning('Webhook: dispute with null payment_intent, skipping', ['dispute_id' => $dispute->id ?? null]);
                return response()->json(['status' => 'success']);
            }
            $order = Order::where('stripe_payment_intent_id', $piId)->first();
            if ($order && $order->status !== 'disputed') {
                $order->update(['status' => 'disputed']);
                $this->clearPurchasedCache($order);
                Mail::to(BrandRegistry::configOrDefault()->accountingEmail ?? 'accounting@reisinger.pictures')
                    ->send(new \App\Mail\CustomMail('Stripe Dispute eröffnet', "Für die Bestellung {$order->id} wurde ein Dispute (Rückbuchung) eröffnet. Der Download-Zugriff für den Kunden wurde automatisch gesperrt."));
            }
        } elseif ($event->type === 'charge.refunded') {
            $charge = $event->data->object;
            $piId = $charge->payment_intent ?? null;
            if ($piId === null) {
                Log::warning('Webhook: refund with null payment_intent, skipping', ['charge_id' => $charge->id ?? null]);
                return response()->json(['status' => 'success']);
            }
            $order = Order::where('stripe_payment_intent_id', $piId)->first();
            if ($order && $order->status !== 'refunded') {
                $order->update(['status' => 'refunded']);
                $this->clearPurchasedCache($order);
            }
        }

        return response()->json(['status' => 'success']);
    }

    private function clearPurchasedCache(Order $order): void
    {
        $snapshot = $order->invoiceSnapshot;
        if (!$snapshot) return;

        $items = $snapshot->customer_details['items'] ?? [];
        foreach ($items as $item) {
            if (!isset($item['photoId'])) continue;
            foreach (['web', 'print', 'original'] as $tier) {
                Cache::forget("user.{$order->user_id}.purchased.{$item['photoId']}.{$tier}");
            }
        }
    }
}
