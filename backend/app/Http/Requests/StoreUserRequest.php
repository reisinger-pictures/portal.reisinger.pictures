<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) return false;
        if ($user->is_org_admin) return true; // Allow — scoped in controller
        return \Illuminate\Support\Facades\Gate::allows('manage-users');
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
        if ($this->user() && $this->user()->is_org_admin) {
            // Org Admins are now allowed — handled in UserController::store
            return;
        }
        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden');
    }
}
