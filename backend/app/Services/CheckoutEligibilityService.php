<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

class CheckoutEligibilityService
{
    public function assertImmediateStripeAllowed(User $user): void
    {
        if ($user->password === null || $user->created_at === null) {
            throw $this->denied();
        }

        $minimumAgeHours = max(0, (int) config('app.stripe.checkout_new_account_hours', 24));
        if ($user->created_at->isAfter(now()->subHours($minimumAgeHours))) {
            throw $this->denied();
        }
    }

    private function denied(): HttpResponseException
    {
        return new HttpResponseException(response()->json([
            'error' => 'Der Account ist noch nicht lange genug aktiviert. Bitte versuche den Checkout in wenigen Minuten erneut.',
        ], 403));
    }
}
