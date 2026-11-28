<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\MolliePaymentAttempt;
use App\Models\Order;
use App\Services\AuthorizationService;
use App\Services\MollieCheckoutService;
use App\Services\MolliePaymentException;
use App\Services\MolliePaymentService;
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
            'currency' => 'ea',
        ]);
    }

    /**
     * Owner-scoped payment state for the German return view.
     *
     * A verified provider fetch is the only thing that can turn the order
     * paid here; the query string and the return redirect are presentation
     * only. A provider outage leaves the order untouched and reports the
     * last known state.
     */
    public function paymentStatus(
        string $id,
        MolliePaymentService $mollie,
        MollieCheckoutService $mollieCheckout,
    ) {
        $user = auth('api')->user();
        $order = Order::query()
            ->ownedBy($user)
            ->with('invoiceSnapshot')
            ->findOrFail($id);

        if ($order->status === 'paid') {
            return $this->paymentStatusResponse($order, null);
        }

        $attempt = MolliePaymentAttempt::query()
            ->forOrder($order)
            ->orderByDesc('generation')
            ->first();

        if ($attempt === null) {
            return $this->paymentStatusResponse($order, null);
        }

        if ($attempt->mollie_payment_id !== null && $mollie->hasCredential()) {
            try {
                $payment = $mollie->getPayment($attempt->mollie_payment_id);
                $mollieCheckout->applyVerifiedPayment($attempt, $payment);
            } catch (MolliePaymentException $exception) {
                Log::warning('order.payment_status.lookup_failed', [
                    'order_id' => (string) $order->getKey(),
                    'attempt_id' => (string) $attempt->getKey(),
                    'reason' => $exception->reason,
                ]);
            }
        }

        $order->refresh();
        $attempt->refresh();

        return $this->paymentStatusResponse($order, $attempt);
    }

    private function paymentStatusResponse(Order $order, ?MolliePaymentAttempt $attempt)
    {
        $active = $attempt !== null && in_array($attempt->status, [
            MolliePaymentAttempt::STATUS_OPEN,
            MolliePaymentAttempt::STATUS_PENDING,
            MolliePaymentAttempt::STATUS_AUTHORIZED,
        ], true);

        return response()->json([
            'order_id' => (string) $order->getKey(),
            'status' => $order->status,
            'invoice_number' => $order->invoiceSnapshot?->invoice_number,
            'payment_status' => $attempt?->status,
            'payment_ambiguous' => $attempt?->status === MolliePaymentAttempt::STATUS_AMBIGUOUS,
            'retryable' => $attempt?->isRetryable() ?? false,
            'checkout_url' => $active ? $attempt->checkout_url : null,
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
            $error = $this->cancelOpenPayment($order);
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

    private function cancelOpenPayment(Order $order): ?string
    {
        return $this->cancelOpenMolliePayment($order);
    }

    /**
     * Cancel the latest Mollie attempt when it is in a state the provider can
     * still cancel. An unidentified or non-cancelable attempt fails closed:
     * the order must not become `cancelled` while money could still arrive.
     */
    private function cancelOpenMolliePayment(Order $order): ?string
    {
        $attempt = MolliePaymentAttempt::query()
            ->forOrder($order)
            ->orderByDesc('generation')
            ->first();

        if ($attempt === null) {
            return null;
        }

        if ($attempt->isPaid()) {
            return 'Die Bestellung ist bereits bezahlt. Eine Stornierung ist kein Erstattungsweg.';
        }

        $paymentId = $attempt->mollie_payment_id;
        if (! is_string($paymentId) || $paymentId === '') {
            if (in_array($attempt->status, [
                MolliePaymentAttempt::STATUS_CREATING,
                MolliePaymentAttempt::STATUS_AMBIGUOUS,
            ], true)) {
                return 'Für diese Bestellung liegt ein ungeklärter Mollie-Zahlungsversuch vor. Bitte zuerst abstimmen; der Bestellstatus wurde nicht geändert.';
            }

            return null;
        }

        $mollie = app(MolliePaymentService::class);
        $mollieCheckout = app(MollieCheckoutService::class);

        try {
            $payment = $mollie->getPayment($paymentId);
            $status = $payment['status'] ?? null;

            if ($status === MolliePaymentAttempt::STATUS_PAID) {
                return 'Die Bestellung ist bereits bezahlt. Eine Stornierung ist kein Erstattungsweg.';
            }

            if (in_array($status, [
                MolliePaymentAttempt::STATUS_CANCELED,
                MolliePaymentAttempt::STATUS_FAILED,
                MolliePaymentAttempt::STATUS_EXPIRED,
            ], true)) {
                $mollieCheckout->applyVerifiedPayment($attempt, $payment);

                return null;
            }

            if (($payment['is_cancelable'] ?? false) !== true) {
                return 'Die Mollie-Zahlung kann in ihrem aktuellen Status nicht storniert werden. Der Bestellstatus wurde nicht geändert; bitte die Zahlung in Mollie prüfen.';
            }

            $result = $mollie->cancelPayment($paymentId);
            if (($result['status'] ?? null) !== MolliePaymentAttempt::STATUS_CANCELED) {
                Log::warning('order.cancel.mollie_payment_not_cancelled', [
                    'order_id' => (string) $order->getKey(),
                    'payment_id' => $paymentId,
                    'payment_status' => $result['status'] ?? null,
                ]);

                return 'Die Stornierung konnte bei Mollie nicht abgeschlossen werden. Der Bestellstatus wurde nicht geändert.';
            }

            $mollieCheckout->applyVerifiedPayment($attempt->refresh(), $result);
        } catch (MolliePaymentException $exception) {
            Log::warning('order.cancel.mollie_payment_not_cancelled', [
                'order_id' => (string) $order->getKey(),
                'payment_id' => $paymentId,
                'reason' => $exception->reason,
            ]);

            return 'Die Stornierung konnte bei Mollie nicht abgeschlossen werden. Der Bestellstatus wurde nicht geändert.';
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
     * rejected unconditionally: a refunded or charged-back payment proves that
     * the money is no longer there, so a status flip cannot re-establish it.
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

        // Terminal states are never reopened by a status flip. A refunded or
        // charged-back order needs a new payment; it is rejected before the
        // provider lookup on purpose, so no provider call is made for these
        // states.
        if (in_array($order->status, ['refunded', 'disputed'], true)) {
            return [
                'status' => 422,
                'error' => 'Eine zurückgezahlte oder bestrittene Bestellung kann nicht über den Bestellstatus wieder auf paid gesetzt werden: Der Zahlungsstatus belegt nach einer Erstattung nicht, dass das Geld noch vorhanden ist. Eine Erstattung erfordert eine neue Zahlung.',
            ];
        }

        // No invoice and no provider payment: nothing confirms a payment, so
        // the status write would be a pure access grant.
        return $this->molliePaidTransitionVerification($order);
    }

    /**
     * Verify the Mollie attempt before an admin write grants `paid` access.
     *
     * The attempt's own paid state is only trusted after it was set by the
     * verified provider transition; an attempt with a provider id is re-read
     * so a locally stale state can never be the proof.
     *
     * @return array{status: int, error: string}|null
     */
    private function molliePaidTransitionVerification(Order $order): ?array
    {
        $attempt = MolliePaymentAttempt::query()
            ->forOrder($order)
            ->orderByDesc('generation')
            ->first();

        if ($attempt === null) {
            return [
                'status' => 422,
                'error' => 'paid kann nur aus der Rechnungskette oder bei einer vom Zahlungsanbieter bestätigten Zahlung gesetzt werden.',
            ];
        }

        if ($attempt->isPaid()) {
            return null;
        }

        $paymentId = $attempt->mollie_payment_id;
        if (! is_string($paymentId) || $paymentId === '') {
            return [
                'status' => 422,
                'error' => 'paid kann nur aus der Rechnungskette oder bei einer vom Zahlungsanbieter bestätigten Zahlung gesetzt werden.',
            ];
        }

        $mollie = app(MolliePaymentService::class);

        try {
            $payment = $mollie->getPayment($paymentId);
            if (($payment['status'] ?? null) === MolliePaymentAttempt::STATUS_PAID
                && ($payment['amount_cents'] ?? null) === (int) $attempt->amount_cents
                && strtoupper((string) ($payment['currency'] ?? '')) === 'EUR') {
                return null;
            }
        } catch (MolliePaymentException $exception) {
            Log::warning('order.paid.mollie_lookup_failed', [
                'order_id' => (string) $order->getKey(),
                'attempt_id' => (string) $attempt->getKey(),
                'reason' => $exception->reason,
            ]);

            return [
                'status' => 502,
                'error' => 'Die Zahlung konnte bei Mollie nicht geprüft werden. Der Bestellstatus wurde nicht geändert.',
            ];
        }

        return [
            'status' => 422,
            'error' => 'paid kann nur aus der Rechnungskette oder bei einer vom Zahlungsanbieter bestätigten Zahlung gesetzt werden.',
        ];
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
