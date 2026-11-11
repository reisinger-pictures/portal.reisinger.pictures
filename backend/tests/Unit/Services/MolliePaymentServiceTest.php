<?php

namespace Tests\Unit\Services;

use App\Models\MolliePaymentAttempt;
use App\Services\MolliePaymentException;
use App\Services\MolliePaymentService;
use Mollie\Api\Fake\MockMollieClient;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Requests\CancelPaymentRequest;
use Mollie\Api\Http\Requests\CreatePaymentRequest;
use Mollie\Api\Http\Requests\GetPaymentRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MolliePaymentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        config([
            'services.mollie.key' => null,
            'services.mollie.method' => null,
        ]);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function centsProvider(): array
    {
        return [
            'zero' => [0, '0.00'],
            'one cent' => [1, '0.01'],
            'ten cents' => [10, '0.10'],
            'one euro' => [100, '1.00'],
            'real price' => [14900, '149.00'],
            'large but valid' => [99999999999, '999999999.99'],
        ];
    }

    #[DataProvider('centsProvider')]
    public function test_decimal_from_cents_is_exact(int $cents, string $expected): void
    {
        $this->assertSame($expected, MolliePaymentService::decimalFromCents($cents));
    }

    public function test_decimal_from_cents_never_uses_a_float(): void
    {
        // 0.29 and 0.57 are classic binary-float artifacts; integer conversion
        // must not produce "0.28"/"0.56".
        $this->assertSame('0.29', MolliePaymentService::decimalFromCents(29));
        $this->assertSame('0.57', MolliePaymentService::decimalFromCents(57));
    }

    /**
     * @return array<string, array{0: mixed, 1: int|null}>
     */
    public static function decimalProvider(): array
    {
        return [
            'two decimals' => ['12.34', 1234],
            'one decimal' => ['12.3', 1230],
            'no decimals' => ['12', 1200],
            'zero' => ['0.00', 0],
            'padded' => [' 5.05 ', 505],
            'three decimals' => ['12.345', null],
            'comma separator' => ['12,34', null],
            'comma thousands' => ['1,234.00', null],
            'negative' => ['-1.00', null],
            'empty' => ['', null],
            'garbage' => ['abc', null],
            'null' => [null, null],
            'float' => [12.34, null],
        ];
    }

    #[DataProvider('decimalProvider')]
    public function test_cents_from_decimal_is_fail_closed(mixed $value, ?int $expected): void
    {
        $this->assertSame($expected, MolliePaymentService::centsFromDecimal($value));
    }

    public function test_is_not_configured_without_a_valid_key_and_method(): void
    {
        $service = new MolliePaymentService(new MockMollieClient);

        config(['services.mollie.key' => null, 'services.mollie.method' => 'creditcard']);
        $this->assertFalse($service->isConfigured());

        config(['services.mollie.key' => 'sk_test_short', 'services.mollie.method' => 'creditcard']);
        $this->assertFalse($service->isConfigured());

        config(['services.mollie.key' => 'test_'.str_repeat('a', 30), 'services.mollie.method' => '']);
        $this->assertFalse($service->isConfigured());

        config(['services.mollie.key' => 'test_'.str_repeat('a', 30), 'services.mollie.method' => 'Credit Card!']);
        $this->assertFalse($service->isConfigured());
    }

    public function test_mode_and_fingerprint_follow_the_configured_key(): void
    {
        $service = new MolliePaymentService(new MockMollieClient);
        config([
            'services.mollie.key' => 'live_'.str_repeat('b', 30),
            'services.mollie.method' => 'ideal',
        ]);

        $this->assertTrue($service->isConfigured());
        $this->assertSame('live', $service->mode());
        $this->assertSame('ideal', $service->paymentMethod());
        $fingerprint = $service->credentialFingerprint();
        $this->assertNotNull($fingerprint);
        $this->assertSame(64, strlen($fingerprint));
        $this->assertStringNotContainsString('live_', $fingerprint);

        config(['services.mollie.key' => 'test_'.str_repeat('b', 30)]);
        $this->assertNotSame($fingerprint, $service->credentialFingerprint());
    }

    public function test_create_payment_sends_exact_cents_and_whitelisted_metadata(): void
    {
        config([
            'services.mollie.key' => 'test_'.str_repeat('a', 30),
            'services.mollie.method' => 'creditcard',
        ]);

        $client = MockMollieClient::fake([
            CreatePaymentRequest::class => MockResponse::payment(
                id: 'tr_create123456',
                status: 'open',
                amount: new Money('EUR', '149.00'),
                overrides: [
                    'mode' => 'test',
                    'metadata' => [
                        'order_id' => 'order-1',
                        'attempt_id' => 'attempt-1',
                        'generation' => '2',
                        'amount_cents' => '14900',
                        'currency' => 'EUR',
                        'customer_email' => 'should-not-be-sent-back',
                    ],
                    '_links' => ['checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/tr_create123456', 'type' => 'text/html']],
                ],
            ),
        ], retainRequests: true);

        $attempt = new MolliePaymentAttempt([
            'id' => 'attempt-1',
            'order_id' => 'order-1',
            'generation' => 2,
            'idempotency_key' => 'idem-key-123',
            'amount_cents' => 14900,
            'currency' => 'EUR',
        ]);

        $payment = (new MolliePaymentService($client))
            ->createPayment($attempt, 'https://portal.example/checkout/return', 'https://portal.example/api/webhooks/mollie');

        $this->assertSame('open', $payment['status']);
        $this->assertSame(14900, $payment['amount_cents']);
        $this->assertSame('EUR', $payment['currency']);
        // The safe view never passes unknown metadata through.
        $this->assertArrayNotHasKey('customer_email', $payment['metadata']);
        $this->assertArrayNotHasKey('details', $payment);
        $this->assertArrayNotHasKey('billing_address', $payment);

        $recorded = $client->getHttpClient()->recorded();
        $this->assertCount(1, $recorded);
        [$pendingRequest] = $recorded[0];
        $payload = $pendingRequest->payload()->all();
        $this->assertSame(['currency' => 'EUR', 'value' => '149.00'], $payload['amount']);
        $this->assertSame('creditcard', $payload['method']);
        $this->assertSame('idem-key-123', $pendingRequest->headers()->get('Idempotency-Key'));
        $this->assertSame('https://portal.example/checkout/return', $payload['redirectUrl']);
        $this->assertSame('https://portal.example/api/webhooks/mollie', $payload['webhookUrl']);
    }

    public function test_create_payment_refuses_an_unconfigured_service_without_a_request(): void
    {
        $client = MockMollieClient::fake([], retainRequests: true);
        config(['services.mollie.key' => null, 'services.mollie.method' => null]);

        $attempt = new MolliePaymentAttempt([
            'id' => 'attempt-1',
            'order_id' => 'order-1',
            'generation' => 1,
            'idempotency_key' => 'idem-key-123',
            'amount_cents' => 100,
            'currency' => 'EUR',
        ]);

        try {
            (new MolliePaymentService($client))
                ->createPayment($attempt, 'https://portal.example/x', 'https://portal.example/y');
            $this->fail('An unconfigured service must refuse to create a payment.');
        } catch (MolliePaymentException $exception) {
            $this->assertSame('not_configured', $exception->reason);
            $this->assertFalse($exception->retryable);
        }

        $this->assertCount(0, $client->getHttpClient()->recorded());
    }

    public function test_get_payment_rejects_a_malformed_id_without_a_request(): void
    {
        $client = MockMollieClient::fake([], retainRequests: true);
        config(['services.mollie.key' => 'test_'.str_repeat('a', 30), 'services.mollie.method' => 'ideal']);

        try {
            (new MolliePaymentService($client))->getPayment('not-a-payment-id');
            $this->fail('A malformed payment id must not reach the provider.');
        } catch (MolliePaymentException $exception) {
            $this->assertSame('unknown_payment', $exception->reason);
            $this->assertFalse($exception->retryable);
        }

        $this->assertCount(0, $client->getHttpClient()->recorded());
    }

    /**
     * @return array<string, array{0: MockResponse, 1: string, 2: bool}>
     */
    public static function errorProvider(): array
    {
        return [
            'not found' => [MockResponse::notFound(), 'unknown_payment', false],
            'unprocessable' => [MockResponse::unprocessableEntity(), 'rejected', false],
            'conflict' => [MockResponse::error(409, 'Conflict', 'already exists'), 'conflict', false],
            'rate limited' => [MockResponse::error(429, 'Too Many Requests', 'retry later'), 'rate_limited', true],
            'server error' => [MockResponse::error(500, 'Server Error', 'boom'), 'provider_unavailable', true],
        ];
    }

    #[DataProvider('errorProvider')]
    public function test_get_payment_maps_provider_errors_to_a_closed_vocabulary(
        MockResponse $response,
        string $reason,
        bool $retryable,
    ): void {
        config(['services.mollie.key' => 'test_'.str_repeat('a', 30), 'services.mollie.method' => 'ideal']);
        $client = MockMollieClient::fake([GetPaymentRequest::class => $response]);

        try {
            (new MolliePaymentService($client))->getPayment('tr_error123456');
            $this->fail('A failed provider lookup must throw.');
        } catch (MolliePaymentException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame($retryable, $exception->retryable);
        }
    }

    public function test_cancel_payment_uses_the_authenticated_client(): void
    {
        config(['services.mollie.key' => 'test_'.str_repeat('a', 30), 'services.mollie.method' => 'ideal']);
        $client = MockMollieClient::fake([
            CancelPaymentRequest::class => MockResponse::payment(
                id: 'tr_cancel123456',
                status: 'canceled',
                amount: new Money('EUR', '10.00'),
                overrides: ['mode' => 'test'],
            ),
        ]);

        $payment = (new MolliePaymentService($client))->cancelPayment('tr_cancel123456');
        $this->assertSame('canceled', $payment['status']);
        $this->assertSame(1000, $payment['amount_cents']);
    }
}
