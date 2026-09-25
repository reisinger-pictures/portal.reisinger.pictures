<?php

namespace App\Http\Requests;

use App\Services\AuthorizationService;
use App\Services\ContractPricingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class GenerateManualInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && app(AuthorizationService::class)->isSuperAdmin($user);
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
            'items.*.price' => 'required|integer|min:0|max:'.ContractPricingService::MAX_SAFE_INTEGER,
            'items.*.qty' => 'required|numeric|min:0.01|max:'.ContractPricingService::MAX_SAFE_INTEGER,
            'items.*.quantity_scale' => 'nullable|integer|in:'.ContractPricingService::MANUAL_QUANTITY_SCALE,
            'terms_html' => 'nullable|string',
        ];
    }

    protected function failedAuthorization()
    {
        throw new AuthorizationException('Keine Berechtigung');
    }
}
