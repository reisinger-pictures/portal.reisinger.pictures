<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
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
            'payment_method' => 'nullable|string|in:stripe,invoice',
            'quote_message' => 'nullable|string',
            'withdrawal_waived' => 'required|boolean',
        ];
    }
}
