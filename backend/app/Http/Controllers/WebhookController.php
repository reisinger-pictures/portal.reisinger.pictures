<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use Illuminate\Support\Facades\Mail;
use App\Mail\InvoiceMail;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
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

        $event = null;

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
        } catch(\UnexpectedValueException $e) {
            Log::error('Stripe Webhook Error: Invalid payload', ['exception' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe Webhook Error: Invalid signature', [
                'exception' => $e->getMessage(),
                'configured_secret' => $endpoint_secret
            ]);
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        if ($event->type === 'payment_intent.succeeded') {
            $paymentIntent = $event->data->object;
            $orderId = $paymentIntent->metadata->order_id ?? null;
            
            if ($orderId) {
                $order = Order::with(['user', 'invoiceSnapshot'])->find($orderId);
                if ($order && $order->status !== 'paid') {
                    // Exakte Gebühr via Stripe API abrufen
                    $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));
                    $intent = $stripe->paymentIntents->retrieve($paymentIntent->id, [
                        'expand' => ['latest_charge.balance_transaction']
                    ]);
                    
                    $feeCents = $intent->latest_charge->balance_transaction->fee ?? 0;
                    
                    if ($feeCents === 0) {
                        Log::warning("Stripe Webhook: Balance transaction missing or fee is 0 for Order {$orderId}");
                    }

                    $order->update([
                        'status' => 'paid',
                        'stripe_fee_cents' => $feeCents
                    ]);
                    if ($order->user) {
                        Mail::to($order->user->email)->queue(new InvoiceMail($order, $order->invoiceSnapshot));
                    }
                }
            }
        } elseif ($event->type === 'charge.dispute.created') {
            $dispute = $event->data->object;
            $piId = $dispute->payment_intent ?? null;
            $order = Order::where('stripe_payment_intent_id', $piId)->first();
            if ($order) {
                $order->update(['status' => 'disputed']);
                Mail::to(env('ACCOUNTING_EMAIL', 'accounting@reisinger.pictures'))
                    ->send(new \App\Mail\CustomMail('Stripe Dispute eröffnet', "Für die Bestellung {$order->id} wurde ein Dispute (Rückbuchung) eröffnet. Der Download-Zugriff für den Kunden wurde automatisch gesperrt."));
            }
        } elseif ($event->type === 'charge.refunded') {
            $charge = $event->data->object;
            $piId = $charge->payment_intent ?? null;
            $order = Order::where('stripe_payment_intent_id', $piId)->first();
            if ($order) {
                $order->update(['status' => 'refunded']);
            }
        }

        return response()->json(['status' => 'success']);
    }
}
