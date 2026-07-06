<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncGalleryAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|string',
            'action' => 'required|in:attach,detach'
        ];
    }
}
