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

        $event = null;

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
        } catch(\UnexpectedValueException $e) {
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            if (!$endpoint_secret && app()->environment('local')) {
                // Fallback nur für lokale Tests ohne CLI Secret
                $event = json_decode($payload);
            } else {
                return response()->json(['error' => 'Invalid signature'], 400);
            }
        }

        if ($event->type === 'payment_intent.succeeded') {
            $paymentIntent = $event->data->object;
            $orderId = $paymentIntent->metadata->order_id ?? null;
            
            if ($orderId) {
                $order = Order::with(['user', 'invoiceSnapshot'])->find($orderId);
                if ($order && $order->status !== 'paid') {
                    $order->update(['status' => 'paid']);
                    if ($order->user) {
                        Mail::to($order->user->email)->send(new InvoiceMail($order, $order->invoiceSnapshot));
                    }
                }
            }
        }

        return response()->json(['status' => 'success']);
    }
}
