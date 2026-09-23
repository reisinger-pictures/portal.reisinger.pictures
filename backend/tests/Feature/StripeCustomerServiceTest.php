<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StripeCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Tests\TestCase;

class StripeCustomerServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_customer_creation_is_disabled_by_default_test_configuration(): void
    {
        $this->assertFalse(config('app.stripe.customers_enabled'));
        $user = User::factory()->create();
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($httpClient);

        $service = new StripeCustomerService(new StripeClient('test-server-side-placeholder'));

        $this->assertNull($service->getOrCreateCustomer($user));
    }

    public function test_it_creates_once_and_then_reuses_the_stored_customer(): void
    {
        config()->set('app.stripe.customers_enabled', true);
        $user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
        ]);

        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturnCallback(function (string $method, string $url, array $headers, array $parameters): array {
                $this->assertSame('post', $method);
                $this->assertSame('https://api.stripe.com/v1/customers', $url);
                $this->assertSame('ada@example.test', $parameters['email']);
                $this->assertSame('Ada Billing', $parameters['name']);
                $this->assertSame([
                    'line1' => 'Test Street 1',
                    'postal_code' => '1010',
                    'city' => 'Vienna',
                    'country' => 'AT',
                ], $parameters['address']);
                $this->assertSame(['portal_user_id' => $this->currentUserId], $parameters['metadata']);
                $this->assertContains(
                    'Idempotency-Key: customer_'.$this->currentUserId,
                    $headers,
                );

                return [
                    json_encode([
                        'id' => 'cus_created_once',
                        'object' => 'customer',
                    ], JSON_THROW_ON_ERROR),
                    200,
                    [],
                ];
            });

        $this->currentUserId = (string) $user->getKey();
        $attributes = [
            'name' => 'Ada Billing',
            'address' => [
                'line1' => 'Test Street 1',
                'postal_code' => '1010',
                'city' => 'Vienna',
                'country' => 'AT',
            ],
        ];
        ApiRequestor::setHttpClient($httpClient);
        $service = new StripeCustomerService(new StripeClient('test-server-side-placeholder'));

        $this->assertSame('cus_created_once', $service->getOrCreateCustomer($user, $attributes));
        $this->assertSame('cus_created_once', $service->getOrCreateCustomer($user, $attributes));
        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'stripe_customer_id' => 'cus_created_once',
        ]);
    }

    public function test_disabled_creation_without_mapping_fails_closed_in_production(): void
    {
        config()->set('app.stripe.customers_enabled', false);
        $user = User::factory()->create();
        $service = new StripeCustomerService(new StripeClient('test-server-side-placeholder'));
        $originalEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');

        try {
            $service->getOrCreateCustomer($user);
            $this->fail('Expected production customer mapping to fail closed.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Stripe Customer mapping is required in production.', $exception->getMessage());
        } finally {
            app()->detectEnvironment(static fn (): string => $originalEnvironment);
        }
    }

    public function test_disabled_creation_keeps_an_existing_mapping_authoritative(): void
    {
        $user = User::factory()->create(['stripe_customer_id' => 'cus_existing_while_disabled']);
        config()->set('app.stripe.customers_enabled', false);
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($httpClient);

        $service = new StripeCustomerService(new StripeClient('test-server-side-placeholder'));

        $this->assertSame('cus_existing_while_disabled', $service->getOrCreateCustomer($user));
    }

    public function test_it_reuses_an_existing_customer_without_calling_stripe(): void
    {
        config()->set('app.stripe.customers_enabled', true);
        $user = User::factory()->create(['stripe_customer_id' => 'cus_existing']);
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects($this->never())->method('request');
        ApiRequestor::setHttpClient($httpClient);

        $service = new StripeCustomerService(new StripeClient('test-server-side-placeholder'));

        $this->assertSame('cus_existing', $service->getOrCreateCustomer($user));
    }

    private string $currentUserId = '';
}
