<?php

namespace App\Http\Requests;

use App\Services\MolliePaymentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [
            'items' => 'required|array|min:1',
            'items.*.photoId' => 'required|string|exists:photos,id',
            'items.*.tier' => 'nullable|string|in:web,print,original',
            'items.*.useCaseId' => 'nullable|string|exists:license_use_cases,id',
            'items.*.modifierIds' => 'nullable|array',
            'items.*.modifierIds.*' => 'string|exists:license_modifiers,id',
            'items.*.isQuote' => 'boolean',
            'items.*.notes' => 'nullable|string',
            'billing_name' => 'required|string|max:255',
            'billing_company' => 'nullable|string|max:255',
            'billing_street' => 'required|string|max:255',
            'billing_zip' => 'required|string|max:20',
            'billing_city' => 'required|string|max:255',
            'payment_method' => 'nullable|string|in:mollie,invoice',
            'turnstile_token' => 'nullable|string|max:2048',
            'quote_message' => 'nullable|string',
            'withdrawal_waived' => 'required|boolean',
        ];

        $paymentMethod = $this->input('payment_method', 'mollie');
        if ($paymentMethod === null || $paymentMethod === 'mollie') {
            // Absent is the backward-compatible default and is resolved by the
            // controller to the first allowlist entry. A provided value must be
            // in the portal-side allowlist before any order can be touched.
            $methods = app(MolliePaymentService::class)->paymentMethods();
            $rules['mollie_method'] = ['nullable', 'string', 'max:32'];
            if ($methods !== []) {
                $rules['mollie_method'][] = Rule::in($methods);
            }
        } else {
            $rules['mollie_method'] = ['nullable', 'string', 'max:32'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'mollie_method.in' => 'Die gewählte Zahlungsart ist nicht verfügbar.',
        ];
    }
}
