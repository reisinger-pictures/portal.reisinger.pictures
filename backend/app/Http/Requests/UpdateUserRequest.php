<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Berechtigungsprüfung erfolgt im Controller
    }

    public function rules(): array
    {
        return [
            'role_ids' => 'array',
            'role_ids.*' => 'exists:roles,id',
            'gallery_group_ids' => 'array',
            'gallery_ids' => 'array',
            'can_edit_metadata' => 'boolean',
            'flatrate_level' => 'nullable|string|in:none,web,print,original'
        ];
    }
}
