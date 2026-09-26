<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;
use App\Support\CheckoutKey;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class CheckoutRiskService
{
    private const ATTEMPT_WINDOW_SECONDS = 3600;

    public function __construct(private readonly TurnstileService $turnstile) {}

    /**
     * Apply the dedicated checkout quota only after the request has been
     * classified as an immediate positive-value Stripe checkout. Exact
     * idempotent replays return before this method and therefore do not
     * consume the PI-attempt budget; the API throttle remains independent.
     */
    public function assertCheckoutQuotaAllowed(Request $request, User $user): void
    {
        $userKey = CheckoutKey::user($user, 'checkout-quota');
        $ipHourKey = CheckoutKey::ip($request->ip(), 'checkout-quota-hour');
        $ipDayKey = CheckoutKey::ip($request->ip(), 'checkout-quota-day');

        // Count every classified attempt against every bucket before checking
        // any one of them, so changing IP cannot bypass the user bucket and
        // changing user cannot bypass the IP buckets.
        $userAttempts = (int) RateLimiter::hit($userKey, 3600);
        $ipHourAttempts = (int) RateLimiter::hit($ipHourKey, 3600);
        $ipDayAttempts = (int) RateLimiter::hit($ipDayKey, 86400);

        $userLimit = max(1, (int) config('app.checkout_throttle_user_per_hour', 5));
        $ipHourLimit = max(1, (int) config('app.checkout_throttle_ip_per_hour', 10));
        $ipDayLimit = max(1, (int) config('app.checkout_throttle_ip_per_day', 30));

        if ($userAttempts > $userLimit) {
            $this->rejectQuota($userKey);
        }
        if ($ipHourAttempts > $ipHourLimit) {
            $this->rejectQuota($ipHourKey);
        }
        if ($ipDayAttempts > $ipDayLimit) {
            $this->rejectQuota($ipDayKey);
        }
    }

    /**
     * Record a failure only after the webhook has passed strict identity
     * validation and the bounded order telemetry update. The customer IP for
     * this counter is the persisted checkout/order snapshot. The webhook
     * request IP is Stripe ingress and must never be used as a customer signal.
     * Cache failures are deliberately best-effort so they cannot cause a
     * payment retry to increment the durable order counter twice.
     */
    public function recordVerifiedPaymentFailure(Order $order): void
    {
        $user = $order->user;
        if (! $user instanceof User) {
            return;
        }

        try {
            $window = $this->failureWindowSeconds();
            RateLimiter::hit(CheckoutKey::user($user, 'checkout-failure'), $window);

            $customerIp = trim((string) ($order->ip_address ?? ''));
            if ($customerIp !== '') {
                RateLimiter::hit(CheckoutKey::ip($customerIp, 'checkout-failure'), $window);
            }
        } catch (\Throwable $exception) {
            Log::warning('Checkout failure velocity counter unavailable', [
                'exception_class' => $exception::class,
            ]);
        }
    }

    /**
     * Gate an immediate, positive-value Stripe PaymentIntent checkout.
     *
     * CheckoutService must call this only after determining that the request is
     * not a quote, invoice/Lieferschein, or settled free order, immediately
     * before creating the Stripe PaymentIntent.
     *
     * The current call counts toward both hourly counters. With the default
     * user threshold of 3, calls one and two pass, while call three and every
     * later call in that hour require Turnstile.
     */
    public function assertImmediateStripeAllowed(Request $request, User $user): void
    {
        if (! $this->turnstile->isEnabled()) {
            return;
        }

        $userAttempts = RateLimiter::hit(
            $this->userAttemptKey($user),
            self::ATTEMPT_WINDOW_SECONDS
        );
        $ipAttempts = RateLimiter::hit(
            $this->ipAttemptKey($request),
            self::ATTEMPT_WINDOW_SECONDS
        );

        $userThreshold = max(1, (int) config('app.turnstile_user_threshold_per_hour', 3));
        $ipThreshold = max(1, (int) config('app.turnstile_ip_threshold_per_hour', 5));

        $failureVelocityRequiresChallenge = $this->hasHighFailureVelocity($request, $user);

        if ($userAttempts < $userThreshold
            && $ipAttempts < $ipThreshold
            && ! $failureVelocityRequiresChallenge) {
            return;
        }

        $token = $request->input('turnstile_token');
        if (! is_string($token) || trim($token) === '') {
            $this->rejectMissingToken();
        }

        // This is the trusted checkout-request IP for Siteverify. It is
        // intentionally separate from the persisted order IP used by verified
        // webhook failure velocity.
        $this->turnstile->assertValid($token, $user, $request->ip());
    }

    private function userAttemptKey(User $user): string
    {
        return CheckoutKey::user($user, 'checkout-risk-attempt');
    }

    private function ipAttemptKey(Request $request): string
    {
        return CheckoutKey::ip($request->ip(), 'checkout-risk-attempt');
    }

    private function hasHighFailureVelocity(Request $request, User $user): bool
    {
        try {
            $userThreshold = max(1, (int) config('app.turnstile_failure_user_threshold_per_hour', 3));
            $ipThreshold = max(1, (int) config('app.turnstile_failure_ip_threshold_per_hour', 5));

            return (int) RateLimiter::attempts(
                CheckoutKey::user($user, 'checkout-failure')
            ) >= $userThreshold
                || (int) RateLimiter::attempts(
                    CheckoutKey::ip($request->ip(), 'checkout-failure')
                ) >= $ipThreshold;
        } catch (\Throwable $exception) {
            Log::warning('Checkout failure velocity lookup unavailable', [
                'exception_class' => $exception::class,
            ]);

            return false;
        }
    }

    private function failureWindowSeconds(): int
    {
        return max(60, (int) config('app.turnstile_failure_window_seconds', 3600));
    }

    private function rejectQuota(string $key): never
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Checkout ist vorübergehend ausgelastet. Bitte versuche es später erneut.',
        ], 429, [
            'Retry-After' => (string) max(1, RateLimiter::availableIn($key)),
        ]));
    }

    private function rejectMissingToken(): never
    {
        throw new HttpResponseException(response()->json([
            'error' => 'Bitte bestätigen Sie die Sicherheitsprüfung.',
            'turnstile_required' => true,
        ], 403));
    }
}
