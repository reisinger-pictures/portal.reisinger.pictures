<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

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
            // Brand: 'rp', 'srp', or null (cross-brand only for Super-Admin).
            // U-02: non-super-admin roles MUST have a brand set; super-admin MUST have brand=null.
            'brand' => 'nullable|string|in:rp,srp'
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
                    // Non-super-admin: brand must be 'rp' or 'srp' (never null).
                    $brand = $this->input('brand');
                    if ($brand === null || $brand === '') {
                        $this->validator->errors()->add(
                            'brand',
                            'Für diese Rolle ist eine Brand-Zuweisung (rp oder srp) erforderlich.'
                        );
                    }
                }
            }
        ];
    }
}
