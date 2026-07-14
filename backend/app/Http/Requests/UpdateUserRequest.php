<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Berechtigungsprüfung erfolgt im Controller
    }

    public function rules(): array
    {
        return [
            'role_ids' => 'array',
            'role_ids.*' => 'exists:roles,id',
            'gallery_group_ids' => 'array',
            'gallery_ids' => 'array',
            'can_edit_metadata' => 'boolean',
            'flatrate_level' => 'nullable|string|in:none,web,print,original',
            'can_purchase_upgrades' => 'boolean',
            'brand' => ['nullable', 'string', Rule::in(array_keys(config('brands', [])))]
        ];
    }

    public function after(): array
    {
        return [
            function () {
                // Only validate brand when role_ids is present (updating roles).
                if (!$this->has('role_ids')) {
                    return;
                }

                $validated = $this->validated();
                $selectedRoleNames = Role::whereIn('id', $validated['role_ids'] ?? [])
                    ->pluck('name')
                    ->all();
                $isSuperAdmin = in_array(UserRole::SUPER_ADMIN->value, $selectedRoleNames, true);

                if ($isSuperAdmin) {
                    // Super-Admin: brand must be null (cross-brand).
                    if ($this->input('brand') !== null) {
                        $this->validator->errors()->add(
                            'brand',
                            'Super-Administratoren sind immer cross-brand (keine Brand-Zuweisung).'
                        );
                    }
                } else {
                    $brand = $this->input('brand');
                    if ($brand === null || $brand === '') {
                        $brandIds = implode(', ', array_keys(config('brands', [])));
                        $this->validator->errors()->add(
                            'brand',
                            "Für diese Rolle ist eine Brand-Zuweisung ({$brandIds}) erforderlich."
                        );
                    }
                }
            }
        ];
    }
}
