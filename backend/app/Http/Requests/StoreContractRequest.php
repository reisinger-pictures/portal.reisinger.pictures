<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => 'nullable|string|in:contract,template',
            'expires_at' => 'nullable|date|after:now',
            'billing_details' => 'nullable|array',
            'billing_details.name' => 'nullable|string|max:255',
            'billing_details.company' => 'nullable|string|max:255',
            'billing_details.street' => 'nullable|string|max:255',
            'billing_details.zip' => 'nullable|string|max:20',
            'billing_details.city' => 'nullable|string|max:255',
            'billing_details.country' => 'nullable|string|max:255',
            'billing_details.email' => 'nullable|email|max:255',
            'billing_details.uid' => 'nullable|string|max:50',
            'items' => 'nullable|array',
            'items.*.type' => 'required|string|in:item,discount_fixed,discount_percent',
            'items.*.description' => 'required|string|max:255',
            'items.*.notes' => 'nullable|string|max:2000',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'required|integer|min:0',
            'discounts' => 'nullable|array',
            'discounts.*.type' => 'required|string|in:discount_fixed,discount_percent',
            'discounts.*.description' => 'required|string|max:255',
            'discounts.*.notes' => 'nullable|string|max:2000',
            'discounts.*.price' => 'required|integer|min:0',
            'terms_html' => 'nullable|string',
            'available_roles' => 'required|array|min:1',
            'available_roles.*' => 'required|string|max:255',
            'allow_multiple_roles_per_signer' => 'boolean',
            'closes_at' => 'nullable|date',
        ];
    }
}
