<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && \Illuminate\Support\Facades\Gate::allows('manage-users');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email'
        ];
    }

    protected function failedAuthorization()
    {
        $user = $this->user();
        if ($user && $user->is_customer_manager) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Not implemented for Customer Managers yet.');
        }
        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden');
    }
}
