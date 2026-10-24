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
    public function __construct(private readonly StripePaymentService $stripePayment) {}

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

        try {
            $paymentIntent = $this->stripePayment->retrievePaymentIntent($paymentIntentId);
            $status = $paymentIntent['status'] ?? null;

            if ($status === 'succeeded') {
                return 'Die Bestellung ist bereits bezahlt. Eine Stornierung kann nicht über Stripe durchgeführt werden; eine Stornierung ist kein Erstattungsweg.';
            }

            if ($status !== 'canceled') {
                $this->stripePayment->cancelPaymentIntent($paymentIntentId);
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

        return substr($message, 0, 500);
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
