<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class SyncGalleryAccessRequest extends FormRequest
{
    /**
     * Access syncing is only allowed for users of the actor's brand. A
     * cross-brand actor (brand === null, e.g. Super-Admin) may manage access
     * across brands. (P0-A12)
     */
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor) {
            return false;
        }

        if ($actor->brand === null) {
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
