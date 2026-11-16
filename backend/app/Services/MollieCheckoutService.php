<?php

namespace App\Services;

use App\Models\MolliePaymentAttempt;
use App\Models\Order;
use App\Support\BrandRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mollie payment-attempt lifecycle.
 *
 * This service owns the only path that may create a Mollie payment and the only
 * path that may turn a verified Mollie payment into a paid order. The customer
 * redirect, the return view, and any request parameter are presentation only.
 */
class MollieCheckoutService
{
    private const CHECKOUT_HOSTS = ['mollie.com'];

    private const ALLOWED_CREATE_STATUSES = [
        MolliePaymentAttempt::STATUS_OPEN,
        MolliePaymentAttempt::STATUS_PENDING,
        MolliePaymentAttempt::STATUS_AUTHORIZED,
        MolliePaymentAttempt::STATUS_PAID,
        MolliePaymentAttempt::STATUS_FAILED,
        MolliePaymentAttempt::STATUS_CANCELED,
        MolliePaymentAttempt::STATUS_EXPIRED,
    ];

    private readonly MolliePaymentService $mollie;

    private readonly InvoiceMailDispatcher $invoiceMailDispatcher;

    public function __construct(
        ?MolliePaymentService $mollie = null,
        ?InvoiceMailDispatcher $invoiceMailDispatcher = null,
    ) {
        $this->mollie = $mollie ?? app(MolliePaymentService::class);
        $this->invoiceMailDispatcher = $invoiceMailDispatcher ?? app(InvoiceMailDispatcher::class);
    }

    /**
     * Start a new attempt or resume the existing one for an order.
     *
     * The attempt row is committed before the remote create. A retry with the
     * same attempt therefore resends the same Idempotency-Key while Mollie
     * still caches it; an unresolved outcome outside that window is marked
     * ambiguous and never replaced automatically.
     *
     * Only `create` and `resume_create` need a usable configuration, and that
     * check sits inside `decideAttempt()` before any row is written. A resume
     * of a known hosted link and a paid replay create nothing at the provider,
     * so a missing webhook or a broken method list must not answer `503` to a
     * customer whose payment already exists — and
     * `mollie:reconcile-payments` settles such an order without any webhook
     * configuration.
     *
     * `$mollieMethod` is the customer's choice from the configured allowlist.
     * It is absent only for an older client; then the first allowlist entry is
     * the default. A value outside the allowlist never reaches the provider.
     */
    public function startOrResume(Order $order, ?string $mollieMethod = null): JsonResponse
    {
        $methods = $this->mollie->paymentMethods();
        $candidate = $mollieMethod === null ? ($methods[0] ?? null) : strtolower(trim($mollieMethod));
        $method = $candidate !== null && in_array($candidate, $methods, true) ? $candidate : null;

        $decision = $this->decideAttempt(
            $order,
            $method,
            $this->mollie->mode(),
            $this->mollie->credentialFingerprint(),
        );
        if ($decision instanceof JsonResponse) {
            return $decision;
        }

        /** @var MolliePaymentAttempt $attempt */
        $attempt = $decision['attempt'];

        if ($decision['action'] === 'resume_link') {
            return $this->pendingResponse($attempt);
        }

        if ($decision['action'] === 'settle_paid') {
            return $this->settleVerifiedPaidAttempt($attempt);
        }

        return $this->createRemotePayment($attempt);
    }

    /**
     * Bind a provider payment ID to an attempt whose create answer was lost.
     *
     * Only an attempt that has no payment ID can be bound, and only when the
     * fetched payment carries this attempt's own non-PII metadata and exact
     * amount/currency/mode. The unique index is the final guard.
     *
     * @param  array<string, mixed>  $payment
     */
    public function bindMissingPaymentId(MolliePaymentAttempt $attempt, array $payment): ?MolliePaymentAttempt
    {
        if ($attempt->mollie_payment_id !== null) {
            return $attempt;
        }

        $paymentId = $payment['id'] ?? null;
        $metadata = $payment['metadata'] ?? [];
        if (! is_string($paymentId)
            || $paymentId === ''
            || ! is_array($metadata)
            || (string) ($metadata['attempt_id'] ?? '') !== (string) $attempt->id
            || (string) ($metadata['order_id'] ?? '') !== (string) $attempt->order_id
            || (string) ($metadata['generation'] ?? '') !== (string) $attempt->generation
            || ($payment['mode'] ?? null) !== $attempt->mode
            || ($payment['amount_cents'] ?? null) !== (int) $attempt->amount_cents
            || strtoupper((string) ($payment['currency'] ?? '')) !== 'EUR') {
            return null;
        }

        $bound = MolliePaymentAttempt::query()
            ->whereKey($attempt->getKey())
            ->whereNull('mollie_payment_id')
            ->update([
                'mollie_payment_id' => $paymentId,
                'last_reconciled_at' => now(),
                'updated_at' => now(),
            ]);
        if ($bound !== 1) {
            $attempt->refresh();

            return $attempt->mollie_payment_id === $paymentId ? $attempt : null;
        }

        return $attempt->refresh();
    }

    /**
     * Reconcile a payment that was retrieved with the authenticated credential.
     *
     * @param  array<string, mixed>  $payment
     * @return array{status: 'paid'|'already_paid'|'observed'|'ignored'|'retryable', reason: ?string}
     */
    public function applyVerifiedPayment(MolliePaymentAttempt $attempt, array $payment): array
    {
        $mismatch = $this->identityMismatch($attempt, $payment);
        if ($mismatch !== null) {
            return ['status' => 'ignored', 'reason' => $mismatch];
        }

        $status = (string) ($payment['status'] ?? '');
        $paid = $status === MolliePaymentAttempt::STATUS_PAID;

        if ($paid) {
            $result = $this->applyPaid($attempt, $payment);
            $this->applyMonetaryReversal($attempt, $payment);

            return $result;
        }

        $this->observeNonPaid($attempt, $status, $payment);

        return ['status' => 'observed', 'reason' => $status !== '' ? $status : 'unknown_status'];
    }

    /**
     * Re-enter the durable paid transition for an attempt that is already
     * verified paid. Used when the process failed after the attempt was marked
     * paid but before the order/mail side effects completed.
     */
    public function settleVerifiedPaidAttempt(MolliePaymentAttempt $attempt): JsonResponse
    {
        $result = DB::transaction(function () use ($attempt): array {
            $lockedOrder = Order::query()
                ->with(['user', 'invoiceSnapshot'])
                ->lockForUpdate()
                ->find($attempt->order_id);
            if ($lockedOrder === null) {
                return ['order' => null, 'ordered' => false];
            }

            if ($lockedOrder->status !== 'pending_payment') {
                return ['order' => $lockedOrder, 'ordered' => false];
            }

            $updated = Order::query()
                ->whereKey($lockedOrder->getKey())
                ->where('status', 'pending_payment')
                ->update(['status' => 'paid']);

            if ($updated === 1) {
                $lockedOrder->status = 'paid';
            } else {
                $lockedOrder->refresh();
            }

            return ['order' => $lockedOrder, 'ordered' => $updated === 1];
        });

        $order = $result['order'];
        if ($order === null) {
            return $this->conflict('Der Checkout existiert nicht mehr.');
        }

        if ($result['ordered'] && $order->user !== null && $order->invoiceSnapshot !== null) {
            try {
                $this->invoiceMailDispatcher->queueOnce($order, $order->user);
            } catch (Throwable $exception) {
                Log::warning('Mollie paid order invoice mail enqueue failed', [
                    'order_id' => (string) $order->getKey(),
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return $this->paidReplay($order);
    }

    /**
     * Mark a create whose answer never arrived as ambiguous once Mollie's
     * idempotency window has passed. A webhook can still bind and settle that
     * payment later; until then the attempt must never be replaced.
     */
    public function abandonUnresolvedCreate(MolliePaymentAttempt $attempt): bool
    {
        $windowMinutes = max(5, (int) config('services.mollie.idempotency_window_minutes', 60));

        if ($attempt->status !== MolliePaymentAttempt::STATUS_CREATING
            || $attempt->mollie_payment_id !== null
            || $attempt->created_at === null
            || ! $attempt->created_at->lt(now()->subMinutes($windowMinutes))) {
            return false;
        }

        $this->markAmbiguous($attempt);

        return true;
    }

    /**
     * Bind the provider payment ID for a create response. The conditional
     * update refuses to overwrite a payment ID installed by a concurrent
     * webhook or create retry.
     *
     * @param  array<string, mixed>  $payment
     */
    public function persistCreatedPayment(MolliePaymentAttempt $attempt, array $payment): MolliePaymentAttempt
    {
        $paymentId = $payment['id'] ?? null;
        $checkoutUrl = $payment['checkout_url'] ?? null;
        $status = $this->normaliseStatus($payment['status'] ?? null);
        if (! is_string($paymentId) || $paymentId === '' || ! is_string($checkoutUrl) || $checkoutUrl === '') {
            throw new MolliePaymentException('invalid_response', retryable: false);
        }

        DB::transaction(function () use ($attempt, $paymentId, $checkoutUrl, $status): void {
            $locked = MolliePaymentAttempt::query()->lockForUpdate()->find($attempt->getKey());
            if ($locked === null) {
                throw new MolliePaymentException('attempt_missing', retryable: false);
            }

            if ($locked->mollie_payment_id !== null && $locked->mollie_payment_id !== $paymentId) {
                throw new MolliePaymentException('payment_id_conflict', retryable: false);
            }

            $locked->mollie_payment_id = $paymentId;
            $locked->checkout_url = $checkoutUrl;
            $locked->status = $status ?? MolliePaymentAttempt::STATUS_OPEN;
            $locked->last_reconciled_at = now();
            $locked->save();
        });

        return $attempt->refresh();
    }

    public function buildRedirectUrl(Order $order): string
    {
        $base = BrandRegistry::frontendUrl($order->brand);

        return $base.'/checkout/return?order_id='.rawurlencode((string) $order->getKey());
    }

    /**
     * The URL Mollie delivers the settlement notification to, or `null` when
     * none is configured.
     *
     * There is deliberately no `APP_URL` fallback: a wrong guess would only be
     * discovered when Mollie refuses it. The value is mandatory in every
     * environment and is checked by `MolliePaymentService::canCreatePayment()`
     * before any payment exists.
     */
    public function buildWebhookUrl(): ?string
    {
        $configured = config('services.mollie.webhook_url');
        if (! is_string($configured) || trim($configured) === '') {
            return null;
        }

        return trim($configured);
    }

    /**
     * The refusal for a configuration that may not create a payment, or `null`
     * when it may.
     *
     * `CheckoutService` calls this before it writes an order: that transaction
     * consumes an invoice number and leaves a `pending_payment` order nothing
     * would settle, so a refused checkout must not reach it. The same check
     * runs again inside `decideAttempt()` before an attempt row exists, which
     * is what keeps "no attempt row on refusal" true for every other caller.
     *
     * It applies to creation only. An existing hosted link and a paid replay
     * create nothing at the provider and stay available while creation is
     * refused.
     */
    public function creationRefusal(?Order $order = null): ?JsonResponse
    {
        // `canCreatePayment()` already requires a usable HTTPS webhook URL, so
        // this is the single authority; a second scheme check here could only
        // drift from it. The refusal still names the concrete defect through
        // `unavailableReason()`.
        if ($this->mollie->canCreatePayment()) {
            return null;
        }

        Log::warning('Mollie payment creation disabled by configuration', [
            'order_id' => $order === null ? null : (string) $order->getKey(),
            'reason' => $this->unavailableReason(),
        ]);

        return $this->unavailable();
    }

    /**
     * Name the missing piece so the log line says what to fix.
     */
    private function unavailableReason(): string
    {
        return $this->mollie->configurationIssue() ?? 'webhook_url_invalid';
    }

    /**
     * Validate the URLs that leave the server. The redirect may use plain HTTP
     * in local/testing, where the dev server has no certificate; Mollie cannot
     * reach an HTTP webhook in any environment, so the webhook is always HTTPS.
     */
    public function assertUsableUrls(string $redirectUrl, string $webhookUrl): void
    {
        if (! $this->isWebUrl($redirectUrl)) {
            throw new MolliePaymentException('invalid_url', retryable: false);
        }

        if (! $this->isWebUrl($webhookUrl)
            || strtolower((string) parse_url($webhookUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new MolliePaymentException('invalid_url', retryable: false);
        }
    }

    /**
     * @return array{action: 'create'|'resume_create'|'resume_link'|'settle_paid', attempt: MolliePaymentAttempt}|JsonResponse
     */
    private function decideAttempt(
        Order $order,
        ?string $method,
        ?string $mode,
        ?string $fingerprint,
    ): array|JsonResponse {
        $windowMinutes = max(5, (int) config('services.mollie.idempotency_window_minutes', 60));
        $maxAttempts = max(1, (int) config('services.mollie.max_attempts', 5));

        return DB::transaction(function () use ($order, $method, $mode, $fingerprint, $windowMinutes, $maxAttempts): array|JsonResponse {
            $lockedOrder = Order::query()->lockForUpdate()->find($order->getKey());
            if ($lockedOrder === null) {
                return $this->conflict('Der Checkout existiert nicht mehr.');
            }
            $lockedOrder->loadMissing('invoiceSnapshot');
            if ($lockedOrder->status === 'paid') {
                // A paid replay creates nothing: it must stay available while
                // payment creation is refused.
                return $this->paidReplay($lockedOrder);
            }
            if ($lockedOrder->status !== 'pending_payment') {
                return $this->conflict('Dieser Checkout-Versuch ist bereits abgeschlossen. Bitte aktualisiere den Warenkorb.');
            }

            $attempts = MolliePaymentAttempt::query()
                ->forOrder($lockedOrder)
                ->orderByDesc('generation')
                ->lockForUpdate()
                ->get();

            $latest = $attempts->first();

            if ($latest === null) {
                return $this->decideCreate($lockedOrder, 1, $method, $mode, $fingerprint);
            }

            if ($latest->isPaid()) {
                // The attempt was verified paid; a crash may have happened
                // before the order transition or its mail enqueue. Re-enter
                // the durable, idempotent settlement instead of answering a
                // "paid" client response for an order that is still pending.
                return ['action' => 'settle_paid', 'attempt' => $latest];
            }

            if ($latest->status === MolliePaymentAttempt::STATUS_AMBIGUOUS) {
                return $this->ambiguousResponse($lockedOrder);
            }

            if ($latest->status === MolliePaymentAttempt::STATUS_CREATING) {
                if ($fingerprint === null
                    || ! hash_equals($latest->credential_fingerprint, $fingerprint)
                    || $latest->created_at === null
                    || $latest->created_at->lt(now()->subMinutes($windowMinutes))) {
                    $this->markAmbiguous($latest);

                    return $this->ambiguousResponse($lockedOrder);
                }

                // A resume of the create may still reach the provider, so it
                // needs the same availability as a new create. Nothing has
                // been written yet, which keeps "no attempt on refusal" true.
                $refusal = $this->creationRefusal($lockedOrder);
                if ($refusal !== null) {
                    return $refusal;
                }

                return ['action' => 'resume_create', 'attempt' => $latest];
            }

            if ($latest->isActive()) {
                if (is_string($latest->checkout_url) && $latest->checkout_url !== '') {
                    // The payment already exists at Mollie; only its persisted
                    // link is handed out, so no configuration is needed.
                    return ['action' => 'resume_link', 'attempt' => $latest];
                }

                // A known provider payment without a usable hosted URL is not
                // replaceable; an operator has to reconcile it.
                $this->markAmbiguous($latest);

                return $this->ambiguousResponse($lockedOrder);
            }

            if ($latest->isRetryable()) {
                if ($attempts->count() >= $maxAttempts) {
                    return $this->conflict('Für diese Bestellung sind zu viele Zahlungsversuche fehlgeschlagen. Bitte wende dich an den Support.');
                }

                return $this->decideCreate($lockedOrder, (int) $latest->generation + 1, $method, $mode, $fingerprint);
            }

            return $this->ambiguousResponse($lockedOrder);
        });
    }

    /**
     * Decide a `create` action, refusing before the attempt row exists when the
     * configuration cannot create a payment or the method is not allowed.
     *
     * Returning a `JsonResponse` from inside the surrounding transaction
     * commits it, so this check must run before `newAttempt()` — not after.
     *
     * @return array{action: 'create', attempt: MolliePaymentAttempt}|JsonResponse
     */
    private function decideCreate(
        Order $order,
        int $generation,
        ?string $method,
        ?string $mode,
        ?string $fingerprint,
    ): array|JsonResponse {
        $refusal = $this->creationRefusal($order);
        if ($refusal !== null) {
            return $refusal;
        }

        if ($method === null) {
            return $this->methodUnavailable();
        }

        if ($mode === null || $fingerprint === null) {
            // `creationRefusal()` names exactly this state as
            // `credentials_missing`; this guard only narrows the types.
            return $this->unavailable();
        }

        return [
            'action' => 'create',
            'attempt' => $this->newAttempt($order, $generation, $mode, $fingerprint, $method),
        ];
    }

    private function newAttempt(Order $order, int $generation, string $mode, string $fingerprint, string $method): MolliePaymentAttempt
    {
        $amountCents = (int) $order->total_amount;
        if ($amountCents <= 0) {
            throw new MolliePaymentException('invalid_amount', retryable: false);
        }

        return MolliePaymentAttempt::create([
            'order_id' => (string) $order->getKey(),
            'generation' => $generation,
            'idempotency_key' => (string) Str::uuid(),
            'mode' => $mode,
            'credential_fingerprint' => $fingerprint,
            'amount_cents' => $amountCents,
            'currency' => 'EUR',
            'status' => MolliePaymentAttempt::STATUS_CREATING,
            'snapshot' => [
                'version' => 1,
                'order_id' => (string) $order->getKey(),
                'order_total_cents' => $amountCents,
                'gross_cents' => $amountCents,
                'currency' => 'EUR',
                'generation' => $generation,
                'payment_method' => $method,
                'brand' => BrandRegistry::normalizeId($order->brand),
                'invoice_number' => $order->invoiceSnapshot?->invoice_number,
                'withdrawal_waived' => (bool) $order->withdrawal_waived,
                'withdrawal_consent_at' => $order->withdrawal_consent_at?->toISOString(),
                'created_from' => 'checkout',
            ],
        ]);
    }

    private function createRemotePayment(MolliePaymentAttempt $attempt): JsonResponse
    {
        $order = Order::query()->with('invoiceSnapshot')->find($attempt->order_id);
        if ($order === null) {
            return $this->conflict('Der Checkout existiert nicht mehr.');
        }

        $redirectUrl = $this->buildRedirectUrl($order);
        $webhookUrl = $this->buildWebhookUrl();
        if ($webhookUrl === null) {
            // `creationRefusal()` already refuses this before an attempt row
            // exists, but the create must never send a payment whose
            // settlement nobody delivers.
            return $this->unavailable();
        }

        try {
            $this->assertUsableUrls($redirectUrl, $webhookUrl);
            $payment = $this->mollie->createPayment($attempt, $redirectUrl, $webhookUrl, $this->attemptMethod($attempt));
            $this->assertCreateResponse($attempt, $payment);
            $attempt = $this->persistCreatedPayment($attempt, $payment);
        } catch (MolliePaymentException $exception) {
            Log::warning('Mollie payment create failed', [
                'order_id' => (string) $attempt->order_id,
                'attempt_id' => (string) $attempt->id,
                'reason' => $exception->reason,
                'retryable' => $exception->retryable,
            ]);

            if (! $exception->retryable) {
                // A definitive rejection means no payment exists; a conflict or
                // an invalid response may have created one. Only the definite
                // case may move to a retryable generation.
                if (in_array($exception->reason, ['rejected', 'invalid_amount'], true)) {
                    $this->markStatus($attempt, MolliePaymentAttempt::STATUS_FAILED);
                } else {
                    $this->markAmbiguous($attempt);
                }
            }

            return $this->unavailable();
        } catch (Throwable $exception) {
            Log::error('Mollie payment create failed unexpectedly', [
                'order_id' => (string) $attempt->order_id,
                'attempt_id' => (string) $attempt->id,
                'exception_class' => $exception::class,
            ]);

            return $this->unavailable();
        }

        if ($attempt->isPaid()) {
            return $this->paidReplay($order);
        }

        if (in_array($attempt->status, [
            MolliePaymentAttempt::STATUS_FAILED,
            MolliePaymentAttempt::STATUS_CANCELED,
            MolliePaymentAttempt::STATUS_EXPIRED,
        ], true)) {
            return $this->paymentFailedResponse();
        }

        return $this->pendingResponse($attempt);
    }

    /**
     * The method an attempt may send. The snapshot is authoritative: a
     * resumed attempt must not silently switch the method the customer chose.
     * Attempts created before the snapshot carried the method fall back to the
     * current first allowlist entry (the deprecated single variable in a
     * pre-list environment).
     */
    private function attemptMethod(MolliePaymentAttempt $attempt): string
    {
        $snapshot = $attempt->snapshot;
        $method = is_array($snapshot) ? ($snapshot['payment_method'] ?? null) : null;
        if (is_string($method) && $method !== '') {
            return $method;
        }

        return $this->mollie->paymentMethods()[0] ?? '';
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function assertCreateResponse(MolliePaymentAttempt $attempt, array $payment): void
    {
        $status = $this->normaliseStatus($payment['status'] ?? null);
        $amount = $payment['amount_cents'] ?? null;
        $currency = $payment['currency'] ?? null;
        $mode = $payment['mode'] ?? null;
        $checkoutUrl = $payment['checkout_url'] ?? null;

        if (! is_int($amount)
            || $amount !== (int) $attempt->amount_cents
            || ! is_string($currency)
            || strtoupper($currency) !== 'EUR'
            || ! is_string($mode)
            || $mode !== $attempt->mode
            || ! is_string($checkoutUrl)
            || ! $this->isAllowedUrl($checkoutUrl, self::CHECKOUT_HOSTS)
            || ! in_array($status, self::ALLOWED_CREATE_STATUSES, true)) {
            throw new MolliePaymentException('invalid_response', retryable: false);
        }
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function identityMismatch(MolliePaymentAttempt $attempt, array $payment): ?string
    {
        $paymentId = $payment['id'] ?? null;
        $storedPaymentId = $attempt->mollie_payment_id;

        if (! is_string($paymentId) || $paymentId === '' || ! is_string($storedPaymentId) || $storedPaymentId === '') {
            return 'obsolete_payment';
        }
        if (! hash_equals($storedPaymentId, $paymentId)) {
            return 'payment_id_mismatch';
        }

        $metadata = $payment['metadata'] ?? [];
        if (! is_array($metadata)
            || (string) ($metadata['order_id'] ?? '') !== (string) $attempt->order_id
            || (string) ($metadata['attempt_id'] ?? '') !== (string) $attempt->id
            || (string) ($metadata['generation'] ?? '') !== (string) $attempt->generation) {
            return 'metadata_mismatch';
        }

        if (($payment['mode'] ?? null) !== $attempt->mode) {
            return 'mode_mismatch';
        }
        if (($payment['amount_cents'] ?? null) !== (int) $attempt->amount_cents) {
            return 'amount_mismatch';
        }
        if (strtoupper((string) ($payment['currency'] ?? '')) !== 'EUR') {
            return 'currency_mismatch';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array{status: 'paid'|'already_paid'|'ignored'|'retryable', reason: ?string}
     */
    private function applyPaid(MolliePaymentAttempt $attempt, array $payment): array
    {
        $paymentId = (string) ($payment['id'] ?? '');

        $transition = DB::transaction(function () use ($attempt, $paymentId): string {
            $lockedOrder = Order::query()
                ->with(['user', 'invoiceSnapshot'])
                ->lockForUpdate()
                ->find($attempt->order_id);

            if ($lockedOrder === null) {
                return 'missing_order';
            }

            $lockedAttempt = MolliePaymentAttempt::query()->lockForUpdate()->find($attempt->getKey());
            if ($lockedAttempt === null) {
                return 'missing_attempt';
            }

            $duplicatePaid = MolliePaymentAttempt::query()
                ->forOrder($lockedOrder)
                ->where('id', '!=', $lockedAttempt->getKey())
                ->where('status', MolliePaymentAttempt::STATUS_PAID)
                ->exists();

            if ($lockedAttempt->status !== MolliePaymentAttempt::STATUS_PAID) {
                $lockedAttempt->mollie_payment_id ??= $paymentId;
                $lockedAttempt->status = MolliePaymentAttempt::STATUS_PAID;
                $lockedAttempt->paid_at = now();
                $lockedAttempt->last_reconciled_at = now();
                $lockedAttempt->save();
            }

            if ($lockedOrder->status === 'paid') {
                return 'already_paid';
            }
            if ($lockedOrder->status !== 'pending_payment') {
                // A refund, dispute, cancellation, or admin transition is
                // terminal for this payment. Never reopen access from a stale
                // provider event; keep the payment as reconciliation evidence.
                return 'order_not_pending';
            }

            $updated = Order::query()
                ->whereKey($lockedOrder->getKey())
                ->where('status', 'pending_payment')
                ->update(['status' => 'paid']);

            if ($updated !== 1) {
                return 'not_updated';
            }

            if ($duplicatePaid) {
                Log::critical('Mollie: more than one attempt for one order is paid; operator refund required', [
                    'order_id' => (string) $lockedOrder->getKey(),
                    'attempt_id' => (string) $lockedAttempt->getKey(),
                    'payment_id' => $paymentId,
                ]);
            }

            return 'paid';
        });

        if ($transition === 'paid' || $transition === 'already_paid') {
            $paidOrder = Order::query()->with(['user', 'invoiceSnapshot'])->find($attempt->order_id);
            if ($paidOrder === null) {
                return ['status' => 'ignored', 'reason' => 'missing_order'];
            }

            try {
                if ($paidOrder->user !== null && $paidOrder->invoiceSnapshot !== null) {
                    $this->invoiceMailDispatcher->queueOnce($paidOrder, $paidOrder->user);
                }
            } catch (Throwable $exception) {
                Log::warning('Mollie paid order invoice mail enqueue failed', [
                    'order_id' => (string) $paidOrder->getKey(),
                    'exception_class' => $exception::class,
                ]);

                return ['status' => 'retryable', 'reason' => 'mail_failed'];
            }

            return [
                'status' => $transition === 'paid' ? 'paid' : 'already_paid',
                'reason' => null,
            ];
        }

        if ($transition === 'not_updated') {
            $fresh = Order::query()->find($attempt->order_id);
            if ($fresh?->status === 'paid') {
                return ['status' => 'already_paid', 'reason' => null];
            }
        }

        return ['status' => 'ignored', 'reason' => $transition];
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function observeNonPaid(MolliePaymentAttempt $attempt, string $status, array $payment): void
    {
        $normalised = $this->normaliseStatus($status);
        if ($normalised === null || $normalised === MolliePaymentAttempt::STATUS_PAID) {
            return;
        }

        DB::transaction(function () use ($attempt, $normalised): void {
            $locked = MolliePaymentAttempt::query()->lockForUpdate()->find($attempt->getKey());
            if ($locked === null || $locked->isPaid()) {
                return;
            }

            $locked->status = $normalised;
            $locked->last_reconciled_at = now();
            $locked->save();
        });

        $this->applyMonetaryReversal($attempt, $payment);
    }

    /**
     * A refund or chargeback is a positive provider fact that revokes access.
     * Only full reversal changes the order; partial refunds keep access and are
     * left to the existing payout/refund handling.
     *
     * @param  array<string, mixed>  $payment
     */
    private function applyMonetaryReversal(MolliePaymentAttempt $attempt, array $payment): void
    {
        $amount = (int) $attempt->amount_cents;
        $chargedBack = is_int($payment['amount_charged_back_cents'] ?? null)
            ? $payment['amount_charged_back_cents']
            : 0;
        $refunded = is_int($payment['amount_refunded_cents'] ?? null)
            ? $payment['amount_refunded_cents']
            : 0;

        $target = match (true) {
            $amount > 0 && $chargedBack >= $amount => 'disputed',
            $amount > 0 && $refunded >= $amount => 'refunded',
            default => null,
        };
        if ($target === null) {
            return;
        }

        DB::transaction(function () use ($attempt, $target): void {
            $lockedOrder = Order::query()->lockForUpdate()->find($attempt->order_id);
            if ($lockedOrder === null) {
                return;
            }

            // A won dispute restores `paid` through the existing dispute flow;
            // a refund is terminal. A later stale paid notification must not
            // reopen either terminal state.
            if ($target === 'refunded' && $lockedOrder->status === 'disputed') {
                $allowed = ['paid', 'pending_payment', 'invoice_created', 'overdue', 'delivery_note', 'archived_in_collective', 'disputed'];
            } elseif ($target === 'disputed') {
                $allowed = ['paid', 'pending_payment', 'invoice_created', 'overdue', 'delivery_note', 'archived_in_collective'];
            } else {
                $allowed = ['paid', 'pending_payment', 'invoice_created', 'overdue', 'delivery_note', 'archived_in_collective'];
            }

            if (! in_array($lockedOrder->status, $allowed, true)) {
                return;
            }

            Order::query()
                ->whereKey($lockedOrder->getKey())
                ->where('status', $lockedOrder->status)
                ->update(['status' => $target]);
        });
    }

    private function markAmbiguous(MolliePaymentAttempt $attempt): void
    {
        $this->markStatus($attempt, MolliePaymentAttempt::STATUS_AMBIGUOUS);
    }

    private function markStatus(MolliePaymentAttempt $attempt, string $status): void
    {
        MolliePaymentAttempt::query()
            ->whereKey($attempt->getKey())
            ->where('status', '!=', MolliePaymentAttempt::STATUS_PAID)
            ->update(['status' => $status, 'updated_at' => now()]);
        $attempt->refresh();
    }

    private function normaliseStatus(mixed $status): ?string
    {
        if ($status instanceof \BackedEnum) {
            $status = $status->value;
        }
        if (! is_string($status) || $status === '') {
            return null;
        }

        return in_array($status, [
            MolliePaymentAttempt::STATUS_OPEN,
            MolliePaymentAttempt::STATUS_PENDING,
            MolliePaymentAttempt::STATUS_AUTHORIZED,
            MolliePaymentAttempt::STATUS_PAID,
            MolliePaymentAttempt::STATUS_FAILED,
            MolliePaymentAttempt::STATUS_CANCELED,
            MolliePaymentAttempt::STATUS_EXPIRED,
        ], true) ? $status : null;
    }

    private function pendingResponse(MolliePaymentAttempt $attempt): JsonResponse
    {
        $order = Order::query()->with('invoiceSnapshot')->find($attempt->order_id);

        return response()->json([
            'success' => true,
            'payment_pending' => true,
            'requires_action' => false,
            'checkout_url' => $attempt->checkout_url,
            'order_id' => (string) $attempt->order_id,
            'invoice_number' => $order?->invoiceSnapshot?->invoice_number,
            'status' => 'pending_payment',
            'poll_url' => '/api/orders/'.rawurlencode((string) $attempt->order_id),
        ]);
    }

    private function paymentFailedResponse(): JsonResponse
    {
        return response()->json([
            'error' => 'Die Zahlung wurde nicht abgeschlossen. Bitte versuche es erneut.',
            'payment_failed' => true,
        ], 409);
    }

    private function paidReplay(Order $order): JsonResponse
    {
        return response()->json([
            'success' => true,
            'order_id' => (string) $order->getKey(),
            'invoice_number' => $order->invoiceSnapshot?->invoice_number,
            'status' => 'paid',
        ]);
    }

    private function ambiguousResponse(Order $order): JsonResponse
    {
        Log::warning('Mollie payment attempt is ambiguous and needs operator reconciliation', [
            'order_id' => (string) $order->getKey(),
        ]);

        return response()->json([
            'error' => 'Der Zahlungsvorgang konnte nicht eindeutig abgeschlossen werden. Bitte wende dich an den Support, damit die Zahlung geprüft wird.',
            'payment_ambiguous' => true,
        ], 503, ['Retry-After' => '60']);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json([
            'error' => 'Die Zahlung ist vorübergehend nicht verfügbar. Bitte versuche es in wenigen Minuten erneut.',
        ], 503, ['Retry-After' => '60']);
    }

    private function methodUnavailable(): JsonResponse
    {
        return response()->json([
            'error' => 'Die gewählte Zahlungsart ist nicht verfügbar.',
            'errors' => ['mollie_method' => ['Die gewählte Zahlungsart ist nicht verfügbar.']],
        ], 422);
    }

    private function conflict(string $message): JsonResponse
    {
        return response()->json([
            'error' => $message,
            'idempotency_conflict' => true,
        ], 409);
    }

    /**
     * @param  array<int, string>  $allowedHosts
     */
    private function isAllowedUrl(string $url, array $allowedHosts): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $local = app()->environment(['local', 'testing']);

        if ($scheme !== 'https' && ! ($local && in_array($scheme, ['http', 'https'], true))) {
            return false;
        }

        if (! $this->isAllowedHost($host, $allowedHosts)) {
            return false;
        }

        return true;
    }

    /**
     * Mollie may answer with a checkout host below its own domain. A host is
     * accepted only when it is the domain itself or a subdomain of it, never
     * when it merely ends with the same characters (`notmollie.com`).
     *
     * @param  array<int, string>  $allowedHosts
     */
    private function isAllowedHost(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    private function isWebUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $local = app()->environment(['local', 'testing']);

        return $scheme === 'https' || ($local && $scheme === 'http');
    }
}
