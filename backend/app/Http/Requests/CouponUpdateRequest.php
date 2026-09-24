<?php

namespace App\Http\Requests;

use App\Models\Coupon;
use App\Models\Org;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CouponUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(['success' => false, 'error' => $validator->errors()->first()], 422)
        );
    }

    public function rules(): array
    {
        $user = $this->user();
        $svc = app(AuthorizationService::class);
        $isPhotographer = $user && $svc->isPhotographer($user) && ! $svc->isSuperAdmin($user) && ! $svc->isAdmin($user);

        $scopeTypes = $isPhotographer
            ? 'in:gallery,meta_gallery,photographer'
            : 'in:global,gallery,meta_gallery,photographer,organisation';

        $rules = [
            'code' => 'sometimes|string|max:50',
            'type' => 'sometimes|string|in:fixed,percentage,photo_package',
            'value' => 'nullable|numeric|min:0|max:9999999.99',
            'max_items' => 'nullable|integer|min:1|max:999',
            'package_quantity' => 'nullable|integer',
            'package_price_cents' => 'nullable|numeric',
            'scope_type' => 'sometimes|string|'.$scopeTypes,
            'scope_id' => 'nullable|string|required_if:scope_type,gallery,meta_gallery',
            'max_uses_global' => 'nullable|integer|min:1',
            'max_uses_per_account' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
            'active' => 'boolean',
            // Server-owned redemption counter; clients must never reset it.
            'used_count' => 'prohibited',
        ];

        if ($isPhotographer) {
            $rules['max_uses_global'] = 'prohibited';
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $req = $this;
            $data = $req->all();

            if (($data['type'] ?? null) === 'percentage' && ($data['value'] ?? 0) > 100) {
                $validator->errors()->add('value', 'Percentage value must not exceed 100.');
            }

            // `value` is required for fixed/percentage coupons; it is unused for photo_package.
            if (in_array($data['type'] ?? null, ['fixed', 'percentage'], true)
                && (! isset($data['value']) || $data['value'] === '')) {
                $validator->errors()->add('value', 'Value is required for fixed and percentage coupons.');
            }

            // photo_package requires package_quantity (≥1) and package_price_cents (≥0).
            if (($data['type'] ?? null) === 'photo_package') {
                if (! isset($data['package_quantity']) || $data['package_quantity'] === '' || (int) ($data['package_quantity'] ?? 0) < 1) {
                    $validator->errors()->add('package_quantity', 'Package quantity must be at least 1.');
                }
                if (! isset($data['package_price_cents']) || $data['package_price_cents'] === '' || (float) ($data['package_price_cents'] ?? -1) < 0) {
                    $validator->errors()->add('package_price_cents', 'Package price must not be negative.');
                }
            }

            $brandValue = BrandRegistry::currentId();

            if (! empty($data['code'])) {
                $id = $req->route('id');
                $query = Coupon::where('brand', $brandValue)->where('code', $data['code']);
                if ($id) {
                    $query->where('id', '!=', $id);
                }
                if ($query->exists()) {
                    $validator->errors()->add('code', 'A coupon with this code already exists for this brand.');
                }
            }

            if (($data['scope_type'] ?? null) === 'organisation') {
                $orgExists = Org::where('id', $data['scope_id'] ?? '')->where('brand', $brandValue)->exists();
                if (! $orgExists) {
                    $validator->errors()->add('scope_id', 'Org not found for this brand.');
                }
            }
        });
    }
}
