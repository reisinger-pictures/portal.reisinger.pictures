<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GalleryGroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'parent_id' => $this->parent_id,
            'is_public' => $this->is_public,
            'is_free_download' => $this->is_free_download,
            'is_editorial_only' => $this->is_editorial_only,
            'is_hidden' => $this->is_hidden,
            'restricted_photographers' => $this->restricted_photographers,
            'children' => GalleryGroupResource::collection($this->whenLoaded('children')),
            'galleries' => GalleryResource::collection($this->whenLoaded('galleries')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
