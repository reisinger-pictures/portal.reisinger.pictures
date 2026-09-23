<?php

namespace Tests\Feature\Checkout;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Services\CheckoutEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Tests\TestCase;

class CheckoutEligibilityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_age_uses_the_single_stripe_hours_configuration(): void
    {
        config(['app.stripe.checkout_new_account_hours' => 24]);
        $this->assertSame(24, (int) config('app.stripe.checkout_new_account_hours'));

        $user = User::factory()->create(['created_at' => now()->subHours(23)]);
        $this->assertDenied($user);
    }

    public function test_passwordless_account_cannot_start_stripe_checkout(): void
    {
        $user = User::factory()->create([
            'password' => null,
            'created_at' => now()->subDay(),
        ]);

        $this->assertDenied($user);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_recent_unprivileged_account_is_denied_before_minimum_age(): void
    {
        config(['app.stripe.checkout_new_account_hours' => 24]);
        $user = User::factory()->create(['created_at' => now()]);

        $this->assertDenied($user);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_older_activated_account_is_allowed(): void
    {
        config(['app.stripe.checkout_new_account_hours' => 24]);
        $user = User::factory()->create(['created_at' => now()->subHours(25)]);

        app(CheckoutEligibilityService::class)->assertImmediateStripeAllowed($user);

        $this->addToAssertionCount(1);
    }

    public function test_privileged_accounts_are_not_implicitly_exempt_from_account_age_gate(): void
    {
        config(['app.stripe.checkout_new_account_hours' => 24]);
        $user = User::factory()->create(['created_at' => now()]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::POWER_USER->value]));

        $this->assertDenied($user);
    }

    private function assertDenied(User $user): void
    {
        try {
            app(CheckoutEligibilityService::class)->assertImmediateStripeAllowed($user);
            $this->fail('Expected checkout eligibility to be denied.');
        } catch (HttpResponseException $exception) {
            $this->assertSame(403, $exception->getResponse()->getStatusCode());
        }
    }
}
