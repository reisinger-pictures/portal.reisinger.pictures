<?php

namespace App\Http\Requests;

use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) return false;
        if (app(AuthorizationService::class)->isOrgAdmin($user)) return true; // Allow — scoped in controller
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
        $user = $this->user();
        if ($user && app(AuthorizationService::class)->isOrgAdmin($user)) {
            // Org Admins are now allowed — handled in UserController::store
            return;
        }
        throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden');
    }
}
