<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class SyncGalleryAccessRequest extends FormRequest
{
    /**
     * Access syncing is only allowed for users of the actor's brand. Only a
     * persisted Super-Admin with `brand === null` may manage access across
     * brands; legacy null-brand actors fail closed. (P0-A12)
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor) {
            return false;
        }

        $authorization = app(AuthorizationService::class);
        if ($authorization->isReservedNullBrandActor($actor) || $authorization->isTransientGuest($actor)) {
            return false;
        }

        if ($authorization->isTrustedCrossBrandActor($actor)) {
            return true;
        }

        $target = User::find($this->input('user_id'));
        if (! $target) {
            // Unknown user → let the controller's findOrFail() produce a 404.
            return true;
        }

        return $target->brand === $actor->brand;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|string',
            'action' => 'required|in:attach,detach',
        ];
    }
}
