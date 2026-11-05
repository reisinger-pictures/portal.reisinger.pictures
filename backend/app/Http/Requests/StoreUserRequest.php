<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }
        if (app(AuthorizationService::class)->isOrgAdmin($user)) {
            return true;
        } // Allow — scoped in controller

        return Gate::allows('manage-users');
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (is_string($value) && User::query()->matchingEmail($value)->exists()) {
                        $fail(__('validation.unique', ['attribute' => $attribute]));
                    }
                },
            ],
        ];
    }

    protected function failedAuthorization()
    {
        $user = $this->user();
        if ($user && app(AuthorizationService::class)->isOrgAdmin($user)) {
            // Org Admins are now allowed — handled in UserController::store
            return;
        }
        throw new AuthorizationException('Forbidden');
    }
}
