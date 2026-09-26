<?php

namespace App\Http\Requests;

use App\Models\Gallery;
use Illuminate\Support\Facades\Gate;

class UpdateGalleryRequest extends GalleryRequest
{
    public function authorize(): bool
    {
        $gallery = Gallery::find($this->route('id'));

        return $gallery !== null && Gate::allows('manage', $gallery);
    }

    // Rules inherited from GalleryRequest
}
