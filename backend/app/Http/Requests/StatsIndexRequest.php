<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StatsIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tier' => 'nullable|string|in:web,print,original'
        ];
    }
}
