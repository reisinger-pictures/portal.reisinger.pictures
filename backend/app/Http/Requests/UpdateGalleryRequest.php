<?php

namespace App\Http\Requests;

class UpdateGalleryRequest extends GalleryRequest
{
    public function authorize(): bool
    {
        return true; // Berechtigungsprüfung erfolgt im Controller via Gate
    }

    // Rules inherited from GalleryRequest
}
