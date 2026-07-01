<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource {
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_admin' => $this->is_admin,
            'is_photographer' => $this->is_photographer,
            'is_pending' => $this->is_pending,
            'can_edit_metadata' => $this->can_edit_metadata,
            'flatrate_level' => $this->flatrate_level,
            'is_super_admin' => $this->is_super_admin,
            'brand' => $this->brand,

            'roles' => $this->whenLoaded('roles', function() { 
                return $this->roles->map(function($r) { 
                    return ['id' => $r->id, 'name' => $r->name]; 
                }); 
            }),
            'gallery_groups' => $this->whenLoaded('galleryGroups'),
            'galleries' => GalleryResource::collection($this->whenLoaded('galleries')),
            'photographer_galleries' => GalleryResource::collection($this->whenLoaded('photographerGalleries')),
            'photographer_gallery_groups' => $this->whenLoaded('photographerGalleryGroups'),
        ];
    }
}
