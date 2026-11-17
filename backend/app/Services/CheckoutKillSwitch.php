<?php

namespace App\Services;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class CheckoutKillSwitch
{
    public function enabled(): bool
    {
        $configured = config('checkout.enabled', true);
        if (! is_bool($configured)) {
            Log::error('Invalid CHECKOUT_ENABLED configuration; checkout is fail-closed.', [
                'configuration_type' => get_debug_type($configured),
                // The raw flag is not a secret, and naming it is what makes the
                // typo (for example "flase") findable in the environment.
                'configured_value' => env('CHECKOUT_ENABLED'),
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
