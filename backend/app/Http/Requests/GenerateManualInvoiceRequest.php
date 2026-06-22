<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GenerateManualInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_super_admin ?? false;
    }

    public function rules(): array
    {
        return [
            'invoice_number' => 'required|string',
            'date' => 'required|date',
            'due_date' => 'required|string',
            'type' => 'nullable|string|in:invoice,offer',
            'service_date' => 'nullable|string',
            'validity' => 'nullable|string',
            'customer_name' => 'nullable|string',
            'customer_company' => 'nullable|string',
            'customer_street' => 'nullable|string',
            'customer_zip' => 'nullable|string',
            'customer_city' => 'nullable|string',
            'customer_country' => 'nullable|string',
            'customer_email' => 'nullable|email',
            'customer_uid' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.type' => 'required|string|in:item,discount_fixed,discount_percent',
            'items.*.description' => 'required|string',
            'items.*.notes' => 'nullable|string',
            'items.*.price' => 'required|numeric',
            'items.*.qty' => 'required|numeric|min:0.01',
            'terms_html' => 'nullable|string'
        ];
    }

    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('Keine Berechtigung');
    }
}
