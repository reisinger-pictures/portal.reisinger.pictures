<?php

namespace App\Http\Requests;

use App\Services\AuthorizationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;

class SendQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $svc = app(AuthorizationService::class);
        $user = $this->user();

        return $user && ($svc->isAdmin($user) || $svc->isPhotographer($user));
    }

    public function rules(): array
    {
        return [
            'custom_price' => 'required|integer',
            'message' => 'required|string',
            'rights_text' => 'nullable|string|max:2000',
        ];
    }

    protected function failedAuthorization()
    {
        throw new AuthorizationException('Keine Berechtigung');
    }
}
