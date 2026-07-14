<?php

namespace App\Http\Requests;

use App\Models\Coupon;
use App\Models\Org;
use App\Support\BrandRegistry;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;

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
        $isPhotographer = $user && $user->is_photographer && !$user->is_super_admin && !$user->is_admin;

        $scopeTypes = $isPhotographer
            ? 'in:gallery,meta_gallery,photographer'
            : 'in:global,gallery,meta_gallery,photographer,organisation';

        $rules = [
            'code' => 'sometimes|string|max:50',
            'type' => 'sometimes|string|in:fixed,percentage',
            'value' => 'sometimes|numeric|min:0|max:9999999.99',
            'max_items' => 'nullable|integer|min:1|max:999',
            'scope_type' => 'sometimes|string|' . $scopeTypes,
            'scope_id' => 'nullable|string|required_if:scope_type,gallery,meta_gallery',
            'max_uses_global' => 'nullable|integer|min:1',
            'max_uses_per_account' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
            'active' => 'boolean',
            'used_count' => 'nullable|integer|min:0',
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

            if (!empty($data['code'])) {
                $brandValue = BrandRegistry::currentId();
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
                if (!$orgExists) {
                    $validator->errors()->add('scope_id', 'Org not found for this brand.');
                }
            }
        });
    }
}
