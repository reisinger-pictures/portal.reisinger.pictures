<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Gallery;

class GalleryPolicy
{
    /**
     * Zentraler Check für das Verwalten einer Galerie (Updates, Löschen, Invites, Ratings).
     */
    public function manage(User $user, Gallery $gallery): bool
    {
        return $user->is_super_admin || $user->is_admin || ($user->is_photographer && $user->canPhotographerAccessGallery($gallery->id));
    }

    public function create(User $user): bool
    {
        return $user->is_super_admin || $user->is_photographer;
    }
}
