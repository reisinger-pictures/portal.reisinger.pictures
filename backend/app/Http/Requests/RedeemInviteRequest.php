<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => 'required|string',
            'name' => 'nullable|string',
            'email' => 'nullable|email',
            'password' => 'nullable|string',
            'accept_privacy' => 'required|accepted'
        ];
    }
}
