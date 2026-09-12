<?php

namespace App\Http\Requests;

use App\Enums\Brand;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class GalleryRequest extends FormRequest
{
    /**
     * Build an `exists` rule constrained to the authenticated user's brand for
     * brand-bound users. Cross-brand users (brand = null, e.g. super_admin)
     * may reference any brand.
     */
    protected function brandScopedExists(string $table): Exists
    {
        $rule = Rule::exists($table, 'id');

        $user = $this->user();
        if ($user !== null && $user->brand !== null) {
            $brand = $user->brand instanceof Brand ? $user->brand->value : (string) $user->brand;
            $rule->where('brand', $brand);
        }

        return $rule;
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255',
            'type' => 'nullable|in:selection,delivery',
            'gallery_group_id' => ['nullable', 'string', $this->brandScopedExists('gallery_groups')],
            'is_public' => 'nullable|boolean',
            'is_live' => 'nullable|boolean',
            'is_free_download' => 'nullable|boolean',
            'is_editorial_only' => 'nullable|boolean',
            'is_hidden' => 'nullable|boolean',
            'restricted_photographers' => 'nullable|boolean',
            'password' => 'nullable|string',
            'expires_at' => 'nullable|date',
            'org_ids' => ['sometimes', 'array'],
            'org_ids.*' => [$this->brandScopedExists('orgs')],
            'allow_client_metadata_edit' => 'nullable|boolean',
            'apply_metadata_to_photos' => 'nullable|boolean',
            'default_title' => 'nullable|string',
            'default_description' => 'nullable|string',
            'default_keywords' => 'nullable|string',
            'default_location' => 'nullable|string',
            'default_city' => 'nullable|string',
            'default_state' => 'nullable|string',
            'default_country' => 'nullable|string',
            'default_iso_country' => 'nullable|string|max:2',
            'licensing_mode' => 'nullable|in:scope_licensing,volume_licensing',
            'volume_preset_id' => 'nullable|numeric|exists:volume_presets,id',
        ];
    }
}
