<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PAY-2: the dev/tunnel secret file may only be used in local/testing. On a
 * production-like host an empty webhook secret must stay a hard 400 even if a
 * leftover or mounted file exists.
 */
class WebhookSecretFallbackTest extends TestCase
{
    use RefreshDatabase;

    private string $secretFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secretFile = storage_path('app/private/stripe_secret.txt');
    }

    /**
     * @param  callable(string): void  $callback
     */
    private function withSecretFile(string $secret, callable $callback): void
    {
        $existed = file_exists($this->secretFile);
        $originalContents = $existed ? (string) file_get_contents($this->secretFile) : null;

        if (! is_dir(dirname($this->secretFile))) {
            mkdir(dirname($this->secretFile), 0755, true);
        }
        file_put_contents($this->secretFile, $secret);

        try {
            $callback($secret);
        } finally {
            if ($existed) {
                file_put_contents($this->secretFile, (string) $originalContents);
            } else {
                @unlink($this->secretFile);
            }
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function signedRefundPayload(string $secret): array
    {
        $payload = [
            'id' => 'evt_secret_file_fallback',
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_secret_file_fallback',
                    'payment_intent' => 'pi_secret_file_fallback',
                    'refunded' => true,
                ],
            ],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        return [$payload, 't='.$timestamp.',v1='.$signature];
    }

    public function test_empty_secret_does_not_accept_the_dev_secret_file_in_production(): void
    {
        $this->withSecretFile('whsec_dev_secret_file', function (string $secret): void {
            $originalEnvironment = app()->environment();
            app()->detectEnvironment(static fn (): string => 'production');

            try {
                config(['services.stripe.webhook_secret' => '']);
                [$payload, $signature] = $this->signedRefundPayload($secret);

                $this->postJson('/api/webhooks/stripe', $payload, [
                    'Stripe-Signature' => $signature,
                ])
                    ->assertStatus(400)
                    ->assertJson(['error' => 'Invalid signature']);
            } finally {
                app()->detectEnvironment(static fn (): string => $originalEnvironment);
            }
        });
    }

    public function test_dev_secret_file_is_still_honoured_in_the_testing_environment(): void
    {
        $this->withSecretFile('whsec_dev_secret_file', function (string $secret): void {
            config(['services.stripe.webhook_secret' => '']);
            [$payload, $signature] = $this->signedRefundPayload($secret);

            $this->postJson('/api/webhooks/stripe', $payload, [
                'Stripe-Signature' => $signature,
            ])->assertOk();
        });
    }
}
