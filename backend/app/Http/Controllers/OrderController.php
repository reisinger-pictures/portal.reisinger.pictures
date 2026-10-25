<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\AuthorizationService;
use App\Services\StripePaymentService;
use App\Support\ActorIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function index()
    {
        $orders = Order::query()
            ->ownedBy(auth('api')->user())
            ->with('invoiceSnapshot')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json(OrderResource::collection($orders)->resolve());
    }

    public function show(string $id)
    {
        $user = auth('api')->user();
        $order = Order::query()
            ->ownedBy($user)
            ->with('invoiceSnapshot')
            ->findOrFail($id);

        return response()->json([
            'id' => (string) $order->getKey(),
            'order_id' => (string) $order->getKey(),
            'status' => $order->status,
            'invoice_number' => $order->invoiceSnapshot?->invoice_number,
            'total_amount' => (int) $order->total_amount,
            'currency' => 'eur',
        ]);
    }

    public function indexAdmin()
    {
        $user = auth('api')->user();
        if ($user && app(AuthorizationService::class)->isReservedNullBrandActor($user)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        $query = Order::with(['user', 'invoiceSnapshot'])->orderBy('created_at', 'desc');

        // Brand scoping: brand-bound admins only see orders of their own brand.
        // Cross-brand users (brand = null, e.g. super_admin) see all brands.
        if ($user->brand !== null) {
            $query->where('brand', $user->brand);
        }

        return response()->json(OrderResource::collection($query->get())->resolve());
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate(['status' => 'required|string|in:pending,invoice_created,pending_payment,paid,overdue,cancelled,disputed,refunded,delivery_note,archived_in_collective']);

        $user = auth('api')->user();
        if ($user && app(AuthorizationService::class)->isReservedNullBrandActor($user)) {
            return response()->json(['error' => 'Forbidden (Brand Isolation)'], 403);
        }

        $query = Order::query();

        // Brand scoping: brand-bound admins must not mutate orders of another
        // brand (404 instead of leaking existence). Cross-brand users may act
        // on any brand.
        if ($user->brand !== null) {
            $query->where('brand', $user->brand);
        }

        $order = $query->findOrFail($id);
        if ($request->status === 'cancelled') {
            $error = $this->cancelOpenPaymentIntent($order);
            if ($error !== null) {
                return response()->json(['error' => $error], 502);
            }
        }

        if ($request->status === 'paid') {
            $rejection = $this->paidTransitionRejection($order);
            if ($rejection !== null) {
                return response()->json(['error' => $rejection['error']], $rejection['status']);
            }
        }

        $order->update(['status' => $request->status]);

        // PurchaseService caches the positive result per photo/tier. An admin
        // transition can revoke that result (refunded/disputed/cancelled), so
        // every manual status write must invalidate the order owner's entries.
        $this->clearPurchasedCache($order);

        return response()->json(['success' => true]);
    }

    private function cancelOpenPaymentIntent(Order $order): ?string
    {
        $paymentIntentId = $order->stripe_payment_intent_id;
        if (! is_string($paymentIntentId) || trim($paymentIntentId) === '') {
            return null;
        }

        $paymentIntentId = trim($paymentIntentId);
        // Resolved lazily: the Stripe client is built eagerly in the service
        // constructor and throws without a secret, which must not break the
        // customer read paths that share this controller.
        $stripePayment = app(StripePaymentService::class);

        try {
            $paymentIntent = $stripePayment->retrievePaymentIntent($paymentIntentId);
            $status = $paymentIntent['status'] ?? null;

            if ($status === 'succeeded') {
                return 'Die Bestellung ist bereits bezahlt. Eine Stornierung kann nicht über Stripe durchgeführt werden; eine Stornierung ist kein Erstattungsweg.';
            }

            if ($status !== 'canceled') {
                $result = $stripePayment->cancelPaymentIntent($paymentIntentId);

                // Fail closed: the order must not be marked cancelled unless
                // Stripe actually reports the intent as canceled.
                if (($result['status'] ?? null) !== 'canceled') {
                    Log::warning('order.cancel.payment_intent_not_cancelled', [
                        'order_id' => (string) $order->getKey(),
                        'payment_intent_id' => $paymentIntentId,
                        'payment_intent_status' => $result['status'] ?? null,
                    ]);

                    return 'Die Stornierung konnte bei Stripe nicht abgeschlossen werden. Der Bestellstatus wurde nicht geändert.';
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('order.cancel.payment_intent_not_cancelled', [
                'order_id' => (string) $order->getKey(),
                'payment_intent_id' => $paymentIntentId,
                'exception_class' => $exception::class,
                'exception_message' => $this->safeStripeExceptionMessage($exception),
            ]);

            return 'Die Stornierung konnte bei Stripe nicht abgeschlossen werden. Der Bestellstatus wurde nicht geändert.';
        }

        return null;
    }

    /**
     * Guard for the transition to `paid`.
     *
     * `paid` is download-eligible (PurchaseService::DOWNLOAD_ELIGIBLE_STATUSES),
     * so an unrestricted admin write would grant paid access without any
     * payment. The transition is therefore allowed only from states that do not
     * turn an unpaid order into an accessible one. `refunded` and `disputed` are
     * rejected unconditionally: the Stripe intent stays `succeeded` after a
     * refund, so the intent alone cannot prove that the money is still there.
     *
     * @return array{status: int, error: string}|null null when the transition is allowed
     */
    private function paidTransitionRejection(Order $order): ?array
    {
        // Idempotent rewrite: the order is already paid, so the status does not
        // change and no access is granted that was not granted before.
        if ($order->status === 'paid') {
            return null;
        }

        // Invoice chain: for B2B the access is already granted at
        // `invoice_created` (PurchaseService: "invoice = B2B, collected
        // offline"), and `overdue`, `delivery_note` and `archived_in_collective`
        // are settled org flows. Setting `paid` there is a bookkeeping step,
        // not an access grant.
        if (in_array($order->status, ['invoice_created', 'overdue', 'delivery_note', 'archived_in_collective'], true)) {
            return null;
        }

        // Terminal states are never reopened by a status flip. A refunded
        // PaymentIntent stays `succeeded` in Stripe, so the intent alone cannot
        // prove that the money is still there; a refunded order needs a new
        // payment, and a won dispute is restored by the `charge.dispute.closed`
        // webhook, not by this route. Rejected before the lookup on purpose: no
        // Stripe call is made for these states.
        if (in_array($order->status, ['refunded', 'disputed'], true)) {
            return [
                'status' => 422,
                'error' => 'Eine zurückgezahlte oder bestrittene Bestellung kann nicht über den Bestellstatus wieder auf paid gesetzt werden: Der Stripe-Zahlungsstatus bleibt nach einer Erstattung auf succeeded und belegt nicht, dass das Geld noch vorhanden ist. Eine Erstattung erfordert eine neue Zahlung; ein gewonnener Disput wird über den Webhook wiederhergestellt.',
            ];
        }

        // No invoice and no Stripe intent: nothing confirms a payment, so the
        // status write would be a pure access grant.
        $paymentIntentId = $order->stripe_payment_intent_id;
        if (! is_string($paymentIntentId) || trim($paymentIntentId) === '') {
            return [
                'status' => 422,
                'error' => 'paid kann nur aus der Rechnungskette oder bei einer von Stripe bestätigten Zahlung gesetzt werden.',
            ];
        }

        $paymentIntentId = trim($paymentIntentId);
        // Resolved lazily: the Stripe client is built eagerly in the service
        // constructor and throws without a secret, which must not break the
        // customer read paths that share this controller.
        $stripePayment = app(StripePaymentService::class);

        try {
            $paymentIntent = $stripePayment->retrievePaymentIntent($paymentIntentId);
            if (($paymentIntent['status'] ?? null) === 'succeeded') {
                return null;
            }
        } catch (\Throwable $exception) {
            // Fail closed: without a Stripe answer the payment is unproven, and
            // a 422 would suggest the request itself was wrong.
            Log::warning('order.paid.payment_intent_lookup_failed', [
                'order_id' => (string) $order->getKey(),
                'payment_intent_id' => $paymentIntentId,
                'exception_class' => $exception::class,
                'exception_message' => $this->safeStripeExceptionMessage($exception),
            ]);

            return [
                'status' => 502,
                'error' => 'Die Zahlung konnte bei Stripe nicht geprüft werden. Der Bestellstatus wurde nicht geändert.',
            ];
        }

        return [
            'status' => 422,
            'error' => 'paid kann nur aus der Rechnungskette oder bei einer von Stripe bestätigten Zahlung gesetzt werden.',
        ];
    }

    private function safeStripeExceptionMessage(\Throwable $exception): string
    {
        $message = $exception->getMessage();
        $stripeSecret = config('services.stripe.secret');
        if (is_string($stripeSecret) && $stripeSecret !== '') {
            $message = str_replace($stripeSecret, '[REDACTED_STRIPE_SECRET]', $message);
        }

        $message = preg_replace('/\b(?:sk|rk)_(?:test|live)_[A-Za-z0-9_-]+\b/i', '[REDACTED_STRIPE_SECRET]', $message) ?? '';
        $message = preg_replace('/\bpi_[A-Za-z0-9_-]+_secret_[A-Za-z0-9_-]+\b/i', '[REDACTED_CLIENT_SECRET]', $message) ?? '';
        $message = preg_replace('/\bclient[_-]?secret\s*[:=]\s*["\']?[^"\'\s,;]+/i', 'client_secret=[REDACTED]', $message) ?? '';
        $message = preg_replace('/\bauthorization\s*:\s*bearer\s+\S+/i', 'Authorization: Bearer [REDACTED]', $message) ?? '';

        return mb_substr($message, 0, 500, 'UTF-8');
    }

    private function clearPurchasedCache(Order $order): void
    {
        $snapshot = $order->invoiceSnapshot;
        if ($snapshot === null) {
            return;
        }

        $items = $snapshot->customer_details['items'] ?? [];
        if (! is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['photoId'])) {
                continue;
            }

            if (! is_string($item['photoId']) && ! is_int($item['photoId'])) {
                continue;
            }

            $photoId = trim((string) $item['photoId']);
            if ($photoId === '') {
                continue;
            }

            foreach (['web', 'print', 'original'] as $tier) {
                $cacheKey = ActorIdentity::purchaseCacheKeyForOrder($order, $photoId, $tier);
                if ($cacheKey !== null) {
                    Cache::forget($cacheKey);
                }
            }
        }
    }
}
