<?php

namespace Tests\Support;

use Closure;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

trait MocksStripeClient
{
    protected function mockStripePaymentIntentSuccess(): ClientInterface
    {
        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->method('request')
            ->willReturnCallback(function (string $method, string $absUrl, array $headers, array $params, bool $hasFile) {
                $body = json_encode([
                    'id' => 'pi_test_'.md5($absUrl.json_encode($params)),
                    'object' => 'payment_intent',
                    'status' => 'requires_payment_method',
                    'client_secret' => 'pi_test_secret',
                    'amount' => (int) ($params['amount'] ?? 0),
                    'currency' => $params['currency'] ?? 'eur',
                    'created' => time(),
                    'customer' => $params['customer'] ?? null,
                    'metadata' => $params['metadata'] ?? [],
                    'latest_charge' => [
                        'balance_transaction' => [
                            'fee' => 0,
                        ],
                    ],
                ]);

                return [$body, 200, []];
            });

        ApiRequestor::setHttpClient($clientMock);

        return $clientMock;
    }

    protected function resetStripeHttpClient(): void
    {
        ApiRequestor::setHttpClient(null);
    }

    /**
     * Install a URL-routing Stripe HTTP mock over the same
     * ApiRequestor::setHttpClient() seam the rest of the suite uses. The handler
     * receives the absolute Stripe URL and returns a `[body, status, headers]`
     * tuple (`json_encode($body)` the body). Reset it in tearDown() with
     * {@see resetStripeHttpClient()}.
     */
    protected function mockStripeApi(Closure $handler): void
    {
        $clientMock = $this->createMock(ClientInterface::class);
        $clientMock->method('request')
            ->willReturnCallback(function (string $method, string $absUrl, array $headers, array $params, bool $hasFile) use ($handler): array {
                return $handler($absUrl);
            });

        ApiRequestor::setHttpClient($clientMock);
    }
}
