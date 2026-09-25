<?php

namespace Tests\Unit\Http\Requests;

use App\Http\Requests\GenerateManualInvoiceRequest;
use App\Http\Requests\StoreContractRequest;
use App\Http\Requests\UpdateContractRequest;
use App\Services\ContractPricingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ContractPricingRequestRulesTest extends TestCase
{
    public function test_contract_store_and_update_rules_accept_the_boundary_and_reject_larger_values(): void
    {
        $maximum = ContractPricingService::MAX_SAFE_INTEGER;
        $payload = [
            'available_roles' => ['Model'],
            'items' => [[
                'type' => 'item',
                'description' => 'Leistung',
                'qty' => $maximum,
                'price' => $maximum,
            ]],
            'discounts' => [[
                'type' => 'discount_fixed',
                'description' => 'Rabatt',
                'price' => $maximum,
            ]],
        ];

        $this->assertNumericRulesAcceptBoundaryAndRejectAbove(
            new StoreContractRequest,
            $payload,
        );
        $this->assertNumericRulesAcceptBoundaryAndRejectAbove(
            new UpdateContractRequest,
            $payload,
        );
    }

    public function test_manual_invoice_rules_accept_the_boundary_and_reject_larger_values(): void
    {
        $maximum = ContractPricingService::MAX_SAFE_INTEGER;
        $payload = [
            'invoice_number' => 'R-2026-001',
            'date' => '2026-09-24',
            'due_date' => 'Zahlbar sofort.',
            'items' => [[
                'type' => 'item',
                'description' => 'Leistung',
                'qty' => $maximum,
                'quantity_scale' => ContractPricingService::MANUAL_QUANTITY_SCALE,
                'price' => $maximum,
            ]],
        ];

        $request = new GenerateManualInvoiceRequest;
        $this->assertNumericRulesAcceptBoundaryAndRejectAbove($request, $payload);

        $legacyFractionalPayload = $payload;
        $legacyFractionalPayload['items'][0]['qty'] = 0.25;
        unset($legacyFractionalPayload['items'][0]['quantity_scale']);
        $this->assertFalse(Validator::make($legacyFractionalPayload, $request->rules())->fails());

        $unsupportedScalePayload = $payload;
        $unsupportedScalePayload['items'][0]['quantity_scale'] = 4;
        $this->assertTrue(Validator::make($unsupportedScalePayload, $request->rules())
            ->fails('items.0.quantity_scale'));

        $discountScalePayload = $payload;
        $discountScalePayload['items'][] = [
            'type' => 'discount_percent',
            'description' => 'Rabatt',
            'qty' => 100,
            'quantity_scale' => ContractPricingService::MANUAL_QUANTITY_SCALE,
            'price' => 1000,
        ];
        $this->assertFalse(Validator::make($discountScalePayload, $request->rules())->fails());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNumericRulesAcceptBoundaryAndRejectAbove(
        FormRequest $request,
        array $payload,
    ): void {
        $rules = $request->rules();
        $this->assertFalse(Validator::make($payload, $rules)->fails());

        $pricePayload = $payload;
        $pricePayload['items'][0]['price'] = ContractPricingService::MAX_SAFE_INTEGER + 1;
        $this->assertTrue(Validator::make($pricePayload, $rules)->fails('items.0.price'));

        $quantityPayload = $payload;
        $quantityPayload['items'][0]['qty'] = ContractPricingService::MAX_SAFE_INTEGER + 1;
        $this->assertTrue(Validator::make($quantityPayload, $rules)->fails('items.0.qty'));

        if (array_key_exists('discounts', $payload)) {
            $discountPayload = $payload;
            $discountPayload['discounts'][0]['price'] = ContractPricingService::MAX_SAFE_INTEGER + 1;
            $this->assertTrue(Validator::make($discountPayload, $rules)->fails('discounts.0.price'));
        }
    }
}
