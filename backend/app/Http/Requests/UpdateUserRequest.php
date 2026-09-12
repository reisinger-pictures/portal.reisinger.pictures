<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use App\Http\Controllers\Concerns\EnforcesBrandIsolation;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    use EnforcesBrandIsolation;

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
            'gallery_group_ids.*' => 'exists:gallery_groups,id',
            'gallery_ids' => 'array',
            'gallery_ids.*' => 'exists:galleries,id',
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
                $actor = $this->user();
                $actorBrand = $this->brandValue($actor);

                // Brand isolation: a brand-bound actor may only reference galleries /
                // groups of their own brand (a brand-less resource is foreign), and may
                // only assign their own brand to the target user.
                if ($actorBrand !== null) {
                    $this->assertSameBrandReferences('gallery_ids', Gallery::class, 'Galerie', $actorBrand);
                    $this->assertSameBrandReferences('gallery_group_ids', GalleryGroup::class, 'Galerie-Gruppe', $actorBrand);

                    if ($this->has('brand') && $this->input('brand') !== null && $this->input('brand') !== $actorBrand) {
                        $this->validator->errors()->add(
                            'brand',
                            'Es darf nur die eigene Marke zugewiesen werden.'
                        );
                    }
                }

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

    /**
     * Ensure all referenced ids exist (enforced by the `exists` rules) and belong
     * to the actor's own brand.
     *
     * @param  class-string  $modelClass
     */
    private function assertSameBrandReferences(string $field, string $modelClass, string $label, string $actorBrand): void
    {
        $ids = $this->input($field, []);
        if (! is_array($ids) || $ids === []) {
            return;
        }

        $foreignExists = $modelClass::whereIn('id', $ids)
            ->where(function ($query) use ($actorBrand) {
                $query->where('brand', '!=', $actorBrand)->orWhereNull('brand');
            })
            ->exists();

        if ($foreignExists) {
            $this->validator->errors()->add(
                $field,
                "{$label}-Zuordnungen müssen zur eigenen Marke gehören."
            );
        }
    }
}
