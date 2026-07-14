<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class GalleryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255',
            'type' => 'nullable|in:selection,delivery',
            'gallery_group_id' => 'nullable|string|exists:gallery_groups,id',
            'is_public' => 'nullable|boolean',
            'is_live' => 'nullable|boolean',
            'is_free_download' => 'nullable|boolean',
            'is_editorial_only' => 'nullable|boolean',
            'is_hidden' => 'nullable|boolean',
            'restricted_photographers' => 'nullable|boolean',
            'password' => 'nullable|string',
            'expires_at' => 'nullable|date',
            'org_ids' => ['sometimes', 'array'],
            'org_ids.*' => ['exists:orgs,id'],
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
        ];
    }
}
