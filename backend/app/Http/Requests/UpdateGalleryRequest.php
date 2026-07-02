<?php

namespace App\Http\Requests;

class UpdateGalleryRequest extends GalleryRequest
{
    public function authorize(): bool
    {
        $gallery = \App\Models\Gallery::find($this->route('id'));

        return $gallery !== null && \Illuminate\Support\Facades\Gate::allows('manage', $gallery);
    }

    // Rules inherited from GalleryRequest
}
