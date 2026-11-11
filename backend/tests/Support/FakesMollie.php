<?php

namespace Tests\Support;

use App\Services\MolliePaymentService;
use Closure;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockMollieHttpAdapter;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;

/**
 * Test seam for the Mollie integration.
 *
 * The SDK's own fake client is installed behind the portal's
 * MolliePaymentService, so tests exercise the real adapter (exact cents,
 * metadata, idempotency key, closed error mapping) without any network call.
 */
trait FakesMollie
{
    protected function configureMollie(string $method = 'creditcard'): void
    {
        config([
            'services.mollie.key' => 'test_'.str_repeat('a', 30),
            'services.mollie.method' => $method,
            'services.mollie.webhook_url' => 'https://portal.example.test/api/webhooks/mollie',
            'services.mollie.idempotency_window_minutes' => 60,
            'services.mollie.max_attempts' => 5,
        ]);
    }

    protected function disablesMollie(): void
    {
        config([
            'services.mollie.key' => null,
            'services.mollie.method' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $responses
     */
    protected function fakeMollie(array $responses, bool $retainRequests = false): MockMollieClient
    {
        $client = MockMollieClient::fake($responses, $retainRequests);
        $this->app->instance(MolliePaymentService::class, new MolliePaymentService($client));

        return $client;
    }

    /**
     * The Idempotency-Key values of every recorded payment create, in order.
     *
     * @return array<int, string>
     */
    protected function mollieRecordedIdempotencyKeys(MockMollieClient $client): array
    {
        $adapter = $client->getHttpClient();
        if (! $adapter instanceof MockMollieHttpAdapter) {
            return [];
        }

        $keys = [];
        foreach ($adapter->recorded() as [$pendingRequest]) {
            if (! $pendingRequest->getRequest() instanceof CreatePaymentRequest) {
                continue;
            }

            $key = $pendingRequest->headers()->get('Idempotency-Key');
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * A create responder that answers with the exact amount and metadata the
     * adapter sent, so the adapter's own response validation stays active.
     */
    protected function mollieCreateResponder(string $status = 'open', array $overrides = []): Closure
    {
        return function (PendingRequest $request) use ($status, $overrides): MockResponse {
            $payload = $request->payload()?->all() ?? [];
            $metadata = $payload['metadata'] ?? [];
            $mode = 'test';
            $attemptId = is_array($metadata) ? (string) ($metadata['attempt_id'] ?? '') : '';

            return $this->molliePaymentResponse(
                id: $this->molliePaymentIdFor($attemptId),
                status: $status,
                amount: $this->mollieAmountFromPayload($payload['amount'] ?? null),
                metadata: is_array($metadata) ? $metadata : [],
                mode: $mode,
                overrides: $overrides,
            );
        };
    }

    /**
     * @param  array<string, string>  $metadata
     * @param  array<string, mixed>  $overrides
     */
    protected function molliePaymentResponse(
        string $id,
        string $status,
        Money $amount,
        array $metadata = [],
        string $mode = 'test',
        ?string $checkoutUrl = null,
        array $overrides = [],
    ): MockResponse {
        return MockResponse::payment(
            id: $id,
            status: $status,
            amount: $amount,
            overrides: array_merge([
                'mode' => $mode,
                'metadata' => $metadata,
                'paidAt' => $status === 'paid' ? '2026-01-01T00:00:00+00:00' : null,
                'isCancelable' => in_array($status, ['open', 'pending'], true),
                '_links' => [
                    'checkout' => [
                        'href' => $checkoutUrl ?? 'https://www.mollie.com/checkout/select-method/'.$id,
                        'type' => 'text/html',
                    ],
                ],
            ], $overrides),
        );
    }

    protected function mollieAmount(int $cents): Money
    {
        return new Money('EUR', MolliePaymentService::decimalFromCents($cents));
    }

    /**
     * Convert whatever the adapter put into the request body into a Money.
     * The SDK serialises `amount` as `{currency, value}`; a Money object can
     * appear when a caller builds the request with one directly.
     */
    private function mollieAmountFromPayload(mixed $amount): Money
    {
        if ($amount instanceof Money) {
            return $amount;
        }

        if (is_array($amount)
            && is_string($amount['currency'] ?? null)
            && is_scalar($amount['value'] ?? null)) {
            return new Money(
                strtoupper($amount['currency']),
                (string) $amount['value'],
            );
        }

        return new Money('EUR', '0.00');
    }

    /**
     * A stable, unique payment id per attempt. Multiple attempts in one test
     * must not collide on the unique provider-id index.
     */
    private function molliePaymentIdFor(string $attemptId): string
    {
        $seed = $attemptId !== '' ? $attemptId : bin2hex(random_bytes(16));

        return 'tr_'.substr(hash('sha256', 'mollie-fake|'.$seed), 0, 16);
    }
}
