<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Photo;

class PhotoPolicy
{
    public function view(?User $user, Photo $photo): bool
    {
        if (!$user) return false;
        return $user->canAccessGallery($photo->gallery_id);
    }

    public function updateMetadata(?User $user, Photo $photo): bool
    {
        if (!$user) return false;

        $isPhotographer = $user->is_super_admin || $user->is_admin || ($user->is_photographer && $user->canPhotographerAccessGallery($photo->gallery_id));
        if ($isPhotographer) {
            return true;
        }

        $isClientWithRights = $photo->gallery->allow_client_metadata_edit 
            && $user->can_edit_metadata 
            && $user->canAccessGallery($photo->gallery_id);
            
        $isGuestWithTransientRights = $photo->gallery->allow_client_metadata_edit 
            && in_array($photo->gallery_id, $user->transient_meta_galleries ?? []);

        return $isClientWithRights || $isGuestWithTransientRights;
    }

    public function viewVersions(?User $user, Photo $photo): bool
    {
        if (!$user) return false;
        return $user->is_super_admin || $user->is_admin || ($user->is_photographer && $user->canPhotographerAccessGallery($photo->gallery_id));
    }

    public function revertMetadata(?User $user, Photo $photo): bool
    {
        if (!$user) return false;
        return $user->is_super_admin || $user->is_admin || ($user->is_photographer && $user->canPhotographerAccessGallery($photo->gallery_id));
    }

    public function delete(?User $user, Photo $photo): bool
    {
        if (!$user) return false;
        return $user->is_super_admin || $user->is_admin || ($user->is_photographer && $user->canPhotographerAccessGallery($photo->gallery_id));
    }
}
