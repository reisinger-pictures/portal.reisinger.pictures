<?php

namespace App\Http\Controllers;

use App\Services\CheckoutService;
use App\Services\SettingResolver;
use App\Http\Requests\CheckoutRequest;
use Illuminate\Support\Facades\Gate;

class CheckoutController extends Controller
{
    public function checkout(CheckoutRequest $request, CheckoutService $checkoutService, SettingResolver $resolver)
    {
        $user = auth('api')->user();

        $holder = $resolver->get('bank_holder');
        $iban = $resolver->get('bank_iban');
        $street = $resolver->get('company_street');
        if (empty($holder) || empty($iban) || empty($street)) {
            return response()->json(['error' => 'Der Betreiber hat noch keine vollständigen Rechnungsdaten (Impressum & Bank) hinterlegt. Kauf derzeit nicht möglich.'], 400);
        }

        $validated = $request->validated();

        if (Gate::denies('purchase-upgrades')) {
            return response()->json(['error' => __('messages.upgrade_not_allowed')], 403);
        }

        $paymentMethod = $request->payment_method ?? 'stripe';
        if ($paymentMethod === 'invoice' && Gate::denies('purchase-on-invoice')) {
            return response()->json(['error' => 'Kauf auf Rechnung nicht erlaubt.'], 403);
        }

        $hasQuotesOnly = collect($request->items)->every(fn($i) => isset($i['isQuote']) && $i['isQuote']);
        if (!$hasQuotesOnly && !$request->withdrawal_waived) {
            return response()->json(['error' => 'Sie müssen auf Ihr Widerrufsrecht verzichten, um digitale Bilddaten zu kaufen.'], 422);
        }

        return $checkoutService->processCheckout($request, $user, $paymentMethod);
    }
}
