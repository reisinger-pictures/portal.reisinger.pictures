<?php

namespace App\Http\Controllers;

use App\Services\StripeKeyCheckService;
use Illuminate\Http\Request;

class StripeKeyCheckController extends Controller
{
    /**
     * Super-Admin Stripe self-test. Thin seam: validate `bundle_mode`, hand it
     * to the service, return `{"checks": …}`. No diagnostic logic lives here.
     *
     * `bundle_mode` is `nullable|in:test,live`: `null` (or absent) is NOT a 422 —
     * it is the D-24 failure class ("no VITE_STRIPE_PUBLIC_KEY in the bundle")
     * and must surface as a finding from the service.
     */
    public function check(Request $request, StripeKeyCheckService $service)
    {
        $validated = $request->validate([
            'bundle_mode' => 'nullable|in:test,live',
        ]);

        return response()->json($service->run($validated['bundle_mode'] ?? null));
    }
}
