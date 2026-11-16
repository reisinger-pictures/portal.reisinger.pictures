<?php

namespace App\Http\Controllers;

use App\Services\MolliePaymentCheckService;

class MolliePaymentCheckController extends Controller
{
    /**
     * Super-Admin Mollie self-test. Thin seam: no request body, no diagnostic
     * logic here — the service always answers with the five findings.
     */
    public function check(MolliePaymentCheckService $service)
    {
        return response()->json($service->run());
    }
}
