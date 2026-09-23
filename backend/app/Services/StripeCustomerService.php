<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;
use UnexpectedValueException;

class StripeCustomerService
{
    private StripeClient $stripe;

    public function __construct(?StripeClient $stripe = null)
    {
        $this->stripe = $stripe ?? new StripeClient(config('services.stripe.secret'));
    }

    /**
     * @param  array{name?: string, address?: array<string, ?string>}  $attributes
     */
    public function getOrCreateCustomer(User $user, array $attributes = []): ?string
    {
        if (! config('app.stripe.customers_enabled', true)) {
            // An existing mapping remains authoritative even when creation is
            // disabled. A live production checkout without one must fail
            // closed; only non-production environments may use the null path.
            if (is_string($user->stripe_customer_id) && $user->stripe_customer_id !== '') {
                return $user->stripe_customer_id;
            }
            if (app()->environment('production')) {
                throw new \RuntimeException('Stripe Customer mapping is required in production.');
            }

            return null;
        }

        return DB::transaction(function () use ($user, $attributes): ?string {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (is_string($lockedUser->stripe_customer_id) && $lockedUser->stripe_customer_id !== '') {
                return $lockedUser->stripe_customer_id;
            }

            $customerAttributes = [
                'email' => $lockedUser->email,
                'name' => $attributes['name'] ?? $lockedUser->name,
                'metadata' => ['portal_user_id' => (string) $lockedUser->getKey()],
            ];
            $address = array_filter(
                $attributes['address'] ?? [],
                fn (?string $value): bool => $value !== null && $value !== '',
            );
            if ($address !== []) {
                $customerAttributes['address'] = $address;
            }

            $customer = $this->stripe->customers->create($customerAttributes, [
                'idempotency_key' => 'customer_'.$lockedUser->getKey(),
            ]);

            $customerId = $customer->id;
            if (! is_string($customerId) || $customerId === '') {
                throw new UnexpectedValueException('Stripe Customer creation returned no customer ID.');
            }

            $lockedUser->forceFill(['stripe_customer_id' => $customerId])->save();
            $user->setAttribute('stripe_customer_id', $customerId);

            return $customerId;
        });
    }
}
