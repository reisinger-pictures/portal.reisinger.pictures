<?php

namespace App\Http\Controllers;

use App\Models\MolliePaymentAttempt;
use App\Services\MollieCheckoutService;
use App\Services\MolliePaymentException;
use App\Services\MolliePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Mollie webhook ingress.
 *
 * Mollie's classic webhook posts only the payment id as a form field. That id
 * is never proof of anything: the payment is fetched with the authenticated
 * server credential, bound to our own attempt metadata, and only then handed
 * to the single verified-payment transition. A duplicate delivery is
 * harmless because that transition is idempotent and compare-and-swap on the
 * order status.
 */
class MollieWebhookController extends Controller
{
    public function __construct(
        private readonly MolliePaymentService $mollie,
        private readonly MollieCheckoutService $mollieCheckout,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $paymentId = $request->input('id');

        if (! is_string($paymentId)
            || preg_match(MolliePaymentService::PAYMENT_ID_PATTERN, $paymentId) !== 1) {
            Log::warning('mollie.webhook.invalid_payment_id');

            // An invalid payload will never become valid by retrying.
            return response()->json(['ok' => true]);
        }

        if (! $this->mollie->isConfigured()) {
            Log::error('mollie.webhook.not_configured');

            // Mollie retries a non-2xx answer, which is correct here: without
            // a credential the payment cannot be verified or denied.
            return response()->json(['error' => 'payment_provider_unavailable'], 503);
        }

        try {
            $payment = $this->mollie->getPayment($paymentId);
        } catch (MolliePaymentException $exception) {
            Log::warning('mollie.webhook.payment_lookup_failed', [
                'payment_id' => $paymentId,
                'reason' => $exception->reason,
            ]);

            return $exception->retryable
                ? response()->json(['error' => 'payment_provider_unavailable'], 503)
                : response()->json(['ok' => true]);
        }

        $attempt = MolliePaymentAttempt::query()
            ->where('mollie_payment_id', $paymentId)
            ->first();

        if ($attempt === null) {
            // A create may have reached Mollie while its answer was lost. The
            // payment carries our own non-PII attempt metadata; bind it only
            // when that metadata and the exact amount/mode match the attempt.
            $metadata = $payment['metadata'] ?? [];
            $attemptId = is_array($metadata) ? ($metadata['attempt_id'] ?? null) : null;
            $attempt = is_string($attemptId) && $attemptId !== ''
                ? MolliePaymentAttempt::query()->find($attemptId)
                : null;

            if ($attempt === null) {
                Log::warning('mollie.webhook.unknown_payment', [
                    'payment_id' => $paymentId,
                ]);

                return response()->json(['ok' => true]);
            }

            $attempt = $this->mollieCheckout->bindMissingPaymentId($attempt, $payment);
            if ($attempt === null) {
                Log::warning('mollie.webhook.bind_rejected', [
                    'payment_id' => $paymentId,
                    'attempt_id' => $attemptId,
                ]);

                return response()->json(['ok' => true]);
            }
        }

        $result = $this->mollieCheckout->applyVerifiedPayment($attempt, $payment);

        if ($result['status'] === 'retryable') {
            Log::warning('mollie.webhook.retryable', [
                'payment_id' => $paymentId,
                'attempt_id' => (string) $attempt->getKey(),
                'reason' => $result['reason'],
            ]);

            return response()->json(['error' => 'payment_not_settled'], 503);
        }

        if ($result['status'] === 'ignored') {
            Log::warning('mollie.webhook.identity_ignored', [
                'payment_id' => $paymentId,
                'attempt_id' => (string) $attempt->getKey(),
                'reason' => $result['reason'],
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
