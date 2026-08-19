<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Photo;
use App\Services\AuthorizationService;

class PhotoPolicy
{
    public function view(?User $user, Photo $photo): bool
    {
        if (!$user) return false;

        return app(AuthorizationService::class)->canAccessGallery($user, $photo->gallery_id);
    }

    public function updateMetadata(?User $user, Photo $photo): bool
    {
        if (!$user) return false;

        $svc = app(AuthorizationService::class);

        if ($svc->canManageGallery($user, $photo->gallery_id)) {
            return true;
        }

        $isClientWithRights = $photo->gallery->allow_client_metadata_edit
            && $user->can_edit_metadata
            && $svc->canAccessGallery($user, $photo->gallery_id);

        $isGuestWithTransientRights = $photo->gallery->allow_client_metadata_edit
            && in_array($photo->gallery_id, $user->transient_meta_galleries ?? []);

        return $isClientWithRights || $isGuestWithTransientRights;
    }

    public function viewVersions(?User $user, Photo $photo): bool
    {
        if (!$user) return false;

        return app(AuthorizationService::class)->canManageGallery($user, $photo->gallery_id);
    }

    public function revertMetadata(?User $user, Photo $photo): bool
    {
        if (!$user) return false;

        return app(AuthorizationService::class)->canManageGallery($user, $photo->gallery_id);
    }

    public function delete(?User $user, Photo $photo): bool
    {
        if (!$user) return false;

        return app(AuthorizationService::class)->canManageGallery($user, $photo->gallery_id);
    }
}
