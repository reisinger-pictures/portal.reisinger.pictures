<?php

namespace App\Policies;

use App\Models\Gallery;
use App\Models\User;
use App\Services\AuthorizationService;

class GalleryPolicy
{
    /**
     * Zentraler Check für das Verwalten einer Galerie (Updates, Löschen, Invites, Ratings).
     */
    public function manage(User $user, Gallery $gallery): bool
    {
        return app(AuthorizationService::class)->canManageGallery($user, $gallery->id);
    }

    public function create(User $user): bool
    {
        $svc = app(AuthorizationService::class);

        return $svc->isSuperAdmin($user) || $svc->isPhotographer($user);
    }
}
