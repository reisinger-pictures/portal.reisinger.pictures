<?php

namespace App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class StripeCheckoutKillSwitch
{
    public function enabled(): bool
    {
        $configured = config('app.stripe.checkout_enabled', true);
        if (! is_bool($configured)) {
            Log::error('Invalid STRIPE_CHECKOUT_ENABLED configuration; checkout is fail-closed.', [
                'configuration_type' => get_debug_type($configured),
            ]);

            return false;
        }

        return $configured;
    }

    public function assertEnabled(): void
    {
        if ($this->enabled()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'error' => 'Der Checkout ist vorübergehend nicht verfügbar. Bitte versuche es später erneut.',
        ], 503, [
            'Retry-After' => '60',
        ]));
    }
}
