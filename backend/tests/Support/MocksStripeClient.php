<?php

namespace Tests\Support;

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
                    'id' => 'pi_test_' . md5($absUrl . json_encode($params)),
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
}
