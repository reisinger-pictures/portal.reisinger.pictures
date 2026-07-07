<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && ($user->is_admin || $user->is_photographer);
    }

    public function rules(): array
    {
        return [
            'custom_price' => 'required|integer',
            'message' => 'required|string',
            'rights_text' => 'nullable|string|max:2000'
        ];
    }

    protected function failedAuthorization()
    {
        throw new \Illuminate\Auth\Access\AuthorizationException('Keine Berechtigung');
    }
}
