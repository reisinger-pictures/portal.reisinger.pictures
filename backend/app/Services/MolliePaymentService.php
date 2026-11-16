<?php

namespace App\Services;

use App\Models\MolliePaymentAttempt;
use Illuminate\Support\Facades\Log;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Exceptions\RequestException;
use Mollie\Api\MollieApiClient;
use Mollie\Api\Resources\Payment;

/**
 * Thin, fail-closed adapter around the official Mollie API client.
 *
 * The adapter deliberately exposes only the fields the portal needs. It never
 * returns a raw provider payload and never logs a key, a request body, an
 * email address, or a payment payload: Mollie payment details can contain
 * customer and card data.
 */
class MolliePaymentService
{
    public const API_KEY_PATTERN = '/^(test|live)_[A-Za-z0-9]{25,}$/';

    public const PAYMENT_ID_PATTERN = '/^tr_[A-Za-z0-9]{6,58}$/';

    public const METHOD_PATTERN = '/^[a-z][a-z0-9]{1,31}$/';

    /**
     * Payment families that require order lines and/or issue their own
     * customer document (invoice or credit note). Mollie collects only the
     * money; the portal issues the invoice (owner decision D-63). A configured
     * forbidden id disables payment creation instead of being dropped.
     *
     * @var array<int, string>
     */
    public const FORBIDDEN_PAYMENT_METHODS = [
        'klarna',
        'in3',
        'billie',
        'riverty',
        'voucher',
        'giftcard',
    ];

    private ?MollieApiClient $client;

    public function __construct(?MollieApiClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * Whether a new payment may be created: credential, usable HTTPS webhook
     * URL, and at least one allowed method.
     *
     * Only creation needs all three. Reading or settling a payment that
     * already exists needs the credential alone (`hasCredential()`), so a
     * broken method list or webhook must never strand money that was already
     * collected.
     */
    public function canCreatePayment(): bool
    {
        return $this->configurationIssue() === null;
    }

    /**
     * Whether the server credential is present and well-formed.
     *
     * The webhook verification, the reconciliation command and the customer
     * status lookup use this: they talk to Mollie about an existing payment
     * and need neither a webhook URL nor a method list.
     */
    public function hasCredential(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * Name the missing or unusable configuration so a refusal can log it.
     *
     * The list itself is validated by `paymentMethods()`; an invalid or
     * forbidden entry empties it on purpose, which fails the whole checkout
     * closed rather than silently offering the remaining methods.
     */
    public function configurationIssue(): ?string
    {
        if ($this->apiKey() === null) {
            return 'credentials_missing';
        }

        if ($this->paymentMethods() === []) {
            return 'methods_missing';
        }

        return $this->webhookUrlIssue();
    }

    /**
     * The webhook-URL half of {@see configurationIssue()}, so a diagnostic can
     * report it without a configured key or method list shadowing the answer.
     * Null means the configured URL passes the same presence and HTTPS rules
     * the checkout gate applies.
     */
    public function webhookUrlIssue(): ?string
    {
        $webhookUrl = config('services.mollie.webhook_url');
        if (! is_string($webhookUrl) || trim($webhookUrl) === '') {
            return 'webhook_url_missing';
        }

        return $this->webhookUrlIsUsable() ? null : 'webhook_url_invalid';
    }

    /**
     * The credential mode (`test` or `live`). Never derived from a payment
     * response; the configured server key is the authority.
     */
    public function mode(): ?string
    {
        $key = $this->apiKey();

        return $key === null ? null : explode('_', $key, 2)[0];
    }

    /**
     * A non-reversible fingerprint of the configured credential. Stored on the
     * attempt so a key rotation is detectable without persisting the key.
     */
    public function credentialFingerprint(): ?string
    {
        $key = $this->apiKey();

        return $key === null ? null : hash('sha256', 'mollie-key|'.$key);
    }

    /**
     * The validated, customer-visible allowlist in configured order.
     *
     * Any malformed or forbidden entry fails the complete list closed: the
     * checkout must not keep operating with a silently reduced method set.
     * The log line names the offending id (never a credential).
     *
     * @return array<int, string>
     */
    public function paymentMethods(): array
    {
        $inspection = $this->inspectMethodList();

        if ($inspection['issue'] !== null) {
            $this->logMethodListIssue($inspection['issue']);

            return [];
        }

        return $inspection['methods'];
    }

    /**
     * The first configured entry that disables the whole list, for diagnostics.
     *
     * `paymentMethods()` fails the list closed and keeps the offending id only
     * in the log; a super-admin self-test has to name it to the operator, so it
     * needs the same inspection without the side effect of a second log line.
     *
     * @return array{method: string|null, reason: string}|null
     */
    public function methodListIssue(): ?array
    {
        return $this->inspectMethodList()['issue'];
    }

    /**
     * The validated allowlist plus the first blocking issue, without logging.
     *
     * @return array{methods: array<int, string>, issue: array{method: string|null, reason: string}|null}
     */
    private function inspectMethodList(): array
    {
        $configured = config('services.mollie.methods');
        if (! is_array($configured)) {
            return ['methods' => [], 'issue' => ['method' => null, 'reason' => 'not_a_list']];
        }

        $methods = [];
        foreach ($configured as $value) {
            $method = is_string($value) ? strtolower(trim($value)) : '';
            if ($method === '') {
                continue;
            }

            if (preg_match(self::METHOD_PATTERN, $method) !== 1) {
                return ['methods' => [], 'issue' => ['method' => $method, 'reason' => 'invalid_format']];
            }

            if (in_array($method, self::FORBIDDEN_PAYMENT_METHODS, true)) {
                return ['methods' => [], 'issue' => ['method' => $method, 'reason' => 'forbidden_method']];
            }

            if (! in_array($method, $methods, true)) {
                $methods[] = $method;
            }
        }

        return ['methods' => $methods, 'issue' => null];
    }

    /**
     * @param  array{method: string|null, reason: string}  $issue
     */
    private function logMethodListIssue(array $issue): void
    {
        if ($issue['reason'] === 'not_a_list') {
            Log::error('Mollie payment method list is not an array; online payment creation is disabled');

            return;
        }

        Log::error(
            $issue['reason'] === 'forbidden_method'
                ? 'Mollie payment method list contains a forbidden BNPL id; online payment creation is disabled'
                : 'Mollie payment method list contains an invalid id; online payment creation is disabled',
            [
                'method' => $issue['method'],
                'reason' => $issue['reason'],
            ],
        );
    }

    /**
     * Create the payment for a committed attempt. The caller has already
     * persisted the immutable snapshot; this method only sends it.
     *
     * @return array<string, mixed>
     *
     * @throws MolliePaymentException
     */
    public function createPayment(
        MolliePaymentAttempt $attempt,
        string $redirectUrl,
        string $webhookUrl,
        string $method,
    ): array {
        if (! $this->canCreatePayment()) {
            throw new MolliePaymentException('not_configured', retryable: false);
        }

        if (! in_array($method, $this->paymentMethods(), true)) {
            throw new MolliePaymentException('method_not_allowed', retryable: false);
        }

        $client = $this->client();

        try {
            $client->setIdempotencyKey($attempt->idempotency_key);
            $payment = $client->payments->create([
                'description' => 'Bestellung '.$attempt->order_id,
                'amount' => [
                    'currency' => $attempt->currency,
                    'value' => self::decimalFromCents($attempt->amount_cents),
                ],
                'redirectUrl' => $redirectUrl,
                'webhookUrl' => $webhookUrl,
                'method' => $method,
                'metadata' => [
                    'order_id' => (string) $attempt->order_id,
                    'attempt_id' => (string) $attempt->id,
                    'generation' => (string) $attempt->generation,
                    'amount_cents' => (string) $attempt->amount_cents,
                    'currency' => $attempt->currency,
                ],
            ]);
        } catch (\Throwable $exception) {
            throw $this->mapException($exception);
        } finally {
            // The key is consumed by the SDK after the next request; resetting
            // keeps a pooled client from carrying it into an unrelated call.
            $client->resetIdempotencyKey();
        }

        return $this->safePayment($payment);
    }

    /**
     * Retrieve a payment with the authenticated server credential.
     *
     * @return array<string, mixed>
     *
     * @throws MolliePaymentException
     */
    public function getPayment(string $paymentId): array
    {
        if (preg_match(self::PAYMENT_ID_PATTERN, $paymentId) !== 1) {
            throw new MolliePaymentException('unknown_payment', retryable: false);
        }

        try {
            return $this->safePayment($this->client()->payments->get($paymentId));
        } catch (\Throwable $exception) {
            throw $this->mapException($exception);
        }
    }

    /**
     * Cancel a payment that Mollie still reports as cancelable. A paid payment
     * can never be canceled, so the caller must keep the order unchanged.
     *
     * @return array<string, mixed>
     *
     * @throws MolliePaymentException
     */
    public function cancelPayment(string $paymentId): array
    {
        if (preg_match(self::PAYMENT_ID_PATTERN, $paymentId) !== 1) {
            throw new MolliePaymentException('unknown_payment', retryable: false);
        }

        try {
            return $this->safePayment($this->client()->payments->cancel($paymentId));
        } catch (\Throwable $exception) {
            throw $this->mapException($exception);
        }
    }

    /**
     * The organisation's current Mollie profile with the authenticated server
     * credential. One whitelisted call, used by the super-admin self-test to
     * prove that the configured key actually works.
     *
     * The raw SDK call and its exception mapping stay inside this adapter, so
     * no caller ever sees a provider payload or an unmapped SDK exception.
     *
     * @return array{profile_id: string|null, name: string|null, mode: string|null, status: string|null}
     *
     * @throws MolliePaymentException
     */
    public function account(): array
    {
        if (! $this->hasCredential()) {
            throw new MolliePaymentException('not_configured', retryable: false);
        }

        try {
            $profile = $this->client()->profiles->getCurrent();
        } catch (\Throwable $exception) {
            throw $this->mapException($exception);
        }

        return [
            'profile_id' => $this->stringOrNull($profile->id ?? null),
            'name' => $this->stringOrNull($profile->name ?? null),
            'mode' => $this->stringOrNull($profile->mode ?? null),
            'status' => $this->statusString($profile->status ?? null),
        ];
    }

    /**
     * Convert an integer-cent amount into Mollie's exact decimal string.
     * No float ever participates in the conversion.
     */
    public static function decimalFromCents(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = (string) abs($cents);
        $absolute = str_pad($absolute, 3, '0', STR_PAD_LEFT);
        $value = substr($absolute, 0, -2).'.'.substr($absolute, -2);

        return $negative ? '-'.$value : $value;
    }

    /**
     * Convert Mollie's decimal string back to integer cents, fail-closed on
     * anything that is not an exact two-decimal amount.
     */
    public static function centsFromDecimal(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $value, $matches) !== 1) {
            return null;
        }

        $euros = (int) $matches[1];
        $fraction = str_pad($matches[2] ?? '', 2, '0');

        return $euros * 100 + (int) $fraction;
    }

    private function apiKey(): ?string
    {
        $key = config('services.mollie.key');
        if (! is_string($key)) {
            return null;
        }

        $key = trim($key);

        return preg_match(self::API_KEY_PATTERN, $key) === 1 ? $key : null;
    }

    /**
     * Mollie validates the webhook URL at create time and refuses an
     * unreachable one, so a payment may only be created with an HTTPS URL.
     * There is deliberately no environment-specific local fallback.
     */
    private function webhookUrlIsUsable(): bool
    {
        $webhookUrl = config('services.mollie.webhook_url');
        if (! is_string($webhookUrl)) {
            return false;
        }

        $parts = parse_url(trim($webhookUrl));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        return strtolower($parts['scheme']) === 'https';
    }

    private function client(): MollieApiClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $key = $this->apiKey();
        if ($key === null) {
            throw new MolliePaymentException('not_configured', retryable: false);
        }

        $client = new MollieApiClient;
        $client->setApiKey($key);

        return $this->client = $client;
    }

    /**
     * Whitelisted scalar payment view. `details`, billing addresses, and the
     * raw payload never leave this method.
     *
     * @return array<string, mixed>
     */
    private function safePayment(mixed $payment): array
    {
        if (! $payment instanceof Payment) {
            throw new MolliePaymentException('invalid_response', retryable: false);
        }

        return [
            'id' => $this->stringOrNull($payment->id ?? null),
            'status' => $this->statusString($payment->status ?? null),
            'mode' => $this->stringOrNull($payment->mode ?? null),
            'amount_cents' => $payment->amount !== null
                ? self::centsFromDecimal($payment->amount->value ?? null)
                : null,
            'currency' => $payment->amount !== null
                ? strtoupper((string) ($payment->amount->currency ?? ''))
                : null,
            'amount_refunded_cents' => $payment->amountRefunded !== null
                ? self::centsFromDecimal($payment->amountRefunded->value ?? null)
                : 0,
            'amount_charged_back_cents' => $payment->amountChargedBack !== null
                ? self::centsFromDecimal($payment->amountChargedBack->value ?? null)
                : 0,
            'checkout_url' => $payment->getCheckoutUrl(),
            'metadata' => $this->metadata($payment->metadata ?? null),
            'paid' => ! empty($payment->paidAt),
            'paid_at' => $this->stringOrNull($payment->paidAt ?? null),
            'is_cancelable' => $payment->isCancelable === true,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function metadata(mixed $metadata): array
    {
        $source = [];
        if (is_array($metadata)) {
            $source = $metadata;
        } elseif (is_object($metadata) && $metadata instanceof \stdClass) {
            $source = get_object_vars($metadata);
        }

        $safe = [];
        foreach (['order_id', 'attempt_id', 'generation', 'amount_cents', 'currency'] as $key) {
            $value = $source[$key] ?? null;
            if (is_scalar($value)) {
                $safe[$key] = trim((string) $value);
            }
        }

        return array_filter($safe, static fn (string $value): bool => $value !== '');
    }

    private function statusString(mixed $status): ?string
    {
        if ($status instanceof \BackedEnum) {
            return (string) $status->value;
        }

        return $this->stringOrNull($status);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function mapException(\Throwable $exception): MolliePaymentException
    {
        if ($exception instanceof MolliePaymentException) {
            return $exception;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->getStatusCode();
            $reason = match (true) {
                $status === 404 => 'unknown_payment',
                $status === 409 => 'conflict',
                $status === 422 => 'rejected',
                $status === 429 => 'rate_limited',
                $status >= 500 => 'provider_unavailable',
                default => 'request_failed',
            };

            return new MolliePaymentException($reason, retryable: $status >= 500 || $status === 429);
        }

        if ($exception instanceof ApiException) {
            // Authentication, malformed configuration, or transport failure.
            // A transport failure is retryable; the caller still refuses to
            // create a replacement payment while an attempt is unresolved.
            return new MolliePaymentException('provider_error', retryable: true);
        }

        return new MolliePaymentException('provider_error', retryable: true);
    }
}
