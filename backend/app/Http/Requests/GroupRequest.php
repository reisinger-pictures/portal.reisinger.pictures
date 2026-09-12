<?php

namespace App\Http\Requests;

use App\Enums\Brand;
use App\Models\GalleryGroup;
use App\Services\AuthorizationService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class GroupRequest extends FormRequest
{
    /**
     * Only admins and photographers may manage groups. Updating an existing
     * group additionally requires management rights on that group
     * (brand-isolated — see AuthorizationService::canManageGalleryGroup()).
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        $svc = app(AuthorizationService::class);
        if (! $svc->isAdmin($user) && ! $svc->isPhotographer($user)) {
            return false;
        }

        $groupId = $this->route('id');
        if ($groupId === null) {
            // Creating a group carries no client-supplied brand: the brand is
            // assigned server-side from the request host (GalleryService).
            return true;
        }

        $group = GalleryGroup::find($groupId);
        if (! $group) {
            // Let the controller's findOrFail() produce a 404.
            return true;
        }

        return $svc->canManageGalleryGroup($user, $group);
    }

    public function rules(): array
    {
        $brand = $this->brandForValidation();

        $parentRule = ['nullable', 'string'];
        $orgRule = ['nullable'];

        if ($brand !== null) {
            $parentRule[] = Rule::exists('gallery_groups', 'id')->where('brand', $brand);
            $orgRule[] = Rule::exists('orgs', 'id')->where('brand', $brand);
        } else {
            $parentRule[] = 'exists:gallery_groups,id';
            $orgRule[] = 'exists:orgs,id';
        }

        return [
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
            'parent_id' => $parentRule,
            'is_public' => 'nullable|boolean',
            'is_free_download' => 'nullable|boolean',
            'is_editorial_only' => 'nullable|boolean',
            'is_hidden' => 'nullable|boolean',
            'restricted_photographers' => 'nullable|boolean',
            'org_id' => $orgRule,
        ];
    }

    /**
     * Brand a parent_id/org_id reference must belong to. For updates this is the
     * target group's brand; for creates it is the acting user's brand (falling
     * back to the host brand for cross-brand users).
     */
    private function brandForValidation(): ?string
    {
        $groupId = $this->route('id');
        if ($groupId !== null) {
            $group = GalleryGroup::find($groupId);
            if ($group) {
                return $this->normalizeBrand($group->brand);
            }
        }

        $user = $this->user();

        return $this->normalizeBrand($user?->brand) ?? BrandRegistry::currentOrDefault()->value;
    }

    private function normalizeBrand(mixed $brand): ?string
    {
        if ($brand === null) {
            return null;
        }

        return $brand instanceof Brand ? $brand->value : (string) $brand;
    }
}
