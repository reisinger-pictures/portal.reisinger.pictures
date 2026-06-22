<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGalleryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Berechtigungsprüfung erfolgt im Controller via Gate
    }

    public function rules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'slug' => 'nullable|string|max:255',
            'type' => 'nullable|in:selection,delivery',
            'is_live' => 'nullable|boolean',
            'is_public' => 'nullable|boolean',
            'is_free_download' => 'nullable|boolean',
            'is_editorial_only' => 'nullable|boolean',
            'is_hidden' => 'nullable|boolean',
            'restricted_photographers' => 'nullable|boolean',
            'gallery_group_id' => 'nullable|string|exists:gallery_groups,id',
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
            'password' => 'nullable|string',
            'expires_at' => 'nullable|date',
        ];
    }
}
