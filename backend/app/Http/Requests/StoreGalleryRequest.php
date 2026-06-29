<?php

namespace App\Http\Requests;

class StoreGalleryRequest extends GalleryRequest
{
    public function authorize(): bool
    {
        // Nur Fotografen dürfen Galerien erstellen
        return $this->user()?->is_photographer ?? false;
    }

    public function rules(): array
    {
        return [
            'tenant_id' => 'nullable|string|exists:tenants,id',
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'type' => 'required|in:selection,delivery',
            'gallery_group_id' => 'nullable|string|exists:gallery_groups,id',
            'is_public' => 'boolean',
            'is_live' => 'boolean',
            'is_free_download' => 'nullable|boolean',
            'is_editorial_only' => 'nullable|boolean',
            'is_hidden' => 'nullable|boolean',
            'restricted_photographers' => 'nullable|boolean',
            'password' => 'nullable|string',
            'expires_at' => 'nullable|date',
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
        ];
    }

    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('Nur Fotografen dürfen Galerien erstellen.');
    }
}
