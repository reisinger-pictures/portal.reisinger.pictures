<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\TestCase;

/**
 * AUTH-4: `stripe_customer_id` is a historical billing-identifier column.
 * Keeping it in `$fillable` would widen the mass-assignment surface for no
 * benefit (a `->update($request->validated())` path could have pointed an
 * account at another billing identity). The payment integration that once
 * wrote it is gone; the column and its uniqueness constraint stay as
 * historical schema.
 */
class UserFillableTest extends TestCase
{
    public function test_stripe_customer_id_is_not_mass_assignable(): void
    {
        $user = new User;

        $this->assertNotContains('stripe_customer_id', $user->getFillable());
        $this->assertFalse($user->isFillable('stripe_customer_id'));
    }

    public function test_fill_cannot_set_a_stripe_customer_id(): void
    {
        $user = (new User)->fill(['stripe_customer_id' => 'cus_attacker']);

        $this->assertNull($user->getAttribute('stripe_customer_id'));
    }

    public function test_force_fill_still_sets_the_stripe_customer_id(): void
    {
        $user = (new User)->forceFill(['stripe_customer_id' => 'cus_legit']);

        $this->assertSame('cus_legit', $user->getAttribute('stripe_customer_id'));
    }
}
