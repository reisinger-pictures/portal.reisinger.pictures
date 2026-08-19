<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a partial brand-settings write (F3, Step 2).
 *
 * The route {brand} param is whitelisted against config('brands'); each
 * overridable field is validated on its own type. The payload is partial
 * (only changed keys are sent); a `null` value resets that override.
 */
class StoreBrandSettingsRequest extends FormRequest
{
    /**
     * Defense-in-depth: the route already guards writes with the `super_admin`
     * middleware and reads with the `management` middleware. Here we additionally
     * require a management role (admin or super-admin) on the authenticated user.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->is_admin || $user->is_super_admin;
    }

    /**
     * Make the route {brand} param available to the validator so it can be
     * checked against the configured brand keys.
     */
    protected function prepareForValidation(): void
    {
        $brand = $this->route('brand');
        if ($brand !== null) {
            $this->merge(['brand' => $brand]);
        }
    }

    public function rules(): array
    {
        return [
            'brand' => ['required', Rule::in(array_keys(config('brands')))],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'portal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'impressum_url' => ['sometimes', 'nullable', 'url'],
            'primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'frontend_url' => ['sometimes', 'nullable', 'url'],
            'from_address' => ['sometimes', 'nullable', 'email'],
            'from_name' => ['sometimes', 'nullable', 'email'],
            'accounting_email' => ['sometimes', 'nullable', 'email'],
            'features.orgs' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
