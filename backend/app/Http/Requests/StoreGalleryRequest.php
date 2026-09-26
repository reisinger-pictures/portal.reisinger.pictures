<?php

namespace App\Http\Requests;

use App\Models\Gallery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class StoreGalleryRequest extends GalleryRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && Gate::allows('create', Gallery::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'type' => 'required|in:selection,delivery',
            'gallery_group_id' => ['nullable', 'string', $this->brandScopedExists('gallery_groups')],
            'is_public' => 'boolean',
            'is_live' => 'boolean',
            'is_free_download' => 'nullable|boolean',
            'is_editorial_only' => 'nullable|boolean',
            'is_hidden' => 'nullable|boolean',
            'restricted_photographers' => 'nullable|boolean',
            'password' => 'nullable|string',
            'expires_at' => 'nullable|date',
            'org_ids' => ['sometimes', 'array'],
            'org_ids.*' => [$this->brandScopedExists('orgs')],
            'allow_client_metadata_edit' => 'boolean',
            'apply_metadata_to_photos' => 'boolean',
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

    protected function failedAuthorization()
    {
        throw new AuthorizationException('Nur Fotografen dürfen Galerien erstellen.');
    }
}
