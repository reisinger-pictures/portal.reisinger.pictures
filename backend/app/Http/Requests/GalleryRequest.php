<?php

namespace App\Http\Requests;

use App\Models\Gallery;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class GalleryRequest extends FormRequest
{
    /**
     * Build an `exists` rule constrained to the authenticated user's brand for
     * brand-bound users. Only a persisted Super-Admin with `brand = null` may
     * reference any brand; invalid null-brand actors receive no valid rows.
     */
    protected function brandScopedExists(string $table): Exists
    {
        $rule = Rule::exists($table, 'id');
        $authorization = app(AuthorizationService::class);

        $user = $this->user();
        if ($user !== null) {
            if ($authorization->isReservedNullBrandActor($user) || $authorization->isTransientGuest($user)) {
                return Rule::exists($table, 'id')->where('id', '__reserved_null_brand_actor__');
            }
        }

        if ($user !== null && ! $authorization->isTrustedCrossBrandActor($user)) {
            $brand = BrandRegistry::normalizeId($user->brand);
            if ($brand === null) {
                return Rule::exists($table, 'id')->where('id', '__reserved_null_brand_actor__');
            }
            $rule->where('brand', $brand);
        }

        return $rule;
    }

    /**
     * Normalize the privacy fields before validation.  Selection galleries
     * are a private rating surface; accepting a contradictory flag here would
     * make the invariant depend on which controller happens to call the
     * service.
     */
    protected function prepareForValidation(): void
    {
        $type = $this->input('type');
        $routeId = $this->route('id');
        if ($type === null && $routeId !== null) {
            $gallery = $routeId instanceof Gallery ? $routeId : Gallery::find($routeId);
            $type = $gallery?->type;
        }

        if ($type === 'selection') {
            $this->merge([
                'is_live' => false,
                'is_public' => false,
                'is_free_download' => false,
            ]);
        }
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
