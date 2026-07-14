<?php
namespace App\Http\Resources;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GalleryResource extends JsonResource {
    public function toArray(Request $request): array {
        return [
            'id' => $this->id,
            'gallery_group_id' => $this->gallery_group_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'full_path' => $this->full_path,
            'type' => $this->type,
            'is_live' => $this->is_live,
            'is_public' => $this->is_public,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
            'licensing_mode' => $this->licensing_mode,
            'effective_licensing_mode' => $this->effective_licensing_mode,
        ];
    }
}
