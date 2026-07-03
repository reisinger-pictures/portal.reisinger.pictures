<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PhotoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'keywords' => $this->keywords,
            'location' => $this->location,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'iso_country' => $this->iso_country,
            'artist' => $this->artist,
            'copyright' => $this->copyright,
            'lr_uuid' => $this->lr_uuid,
            'gallery_id' => $this->gallery_id,
            'file_path' => $this->file_path,
            'width' => $this->width,
            'height' => $this->height,
            'thumb_url' => $this->thumb_url,
            'url' => $this->url,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'gallery' => $this->whenLoaded('gallery'),
            'is_editorial_only' => $this->is_editorial_only,
            'is_hidden' => $this->is_hidden,
            'effective_is_editorial_only' => $this->effective_is_editorial_only,
            'effective_is_hidden' => $this->effective_is_hidden,
            'is_downscaled' => $this->is_downscaled,
            'captured_at' => $this->captured_at?->toISOString(),
            'last_accessed_at' => $this->last_accessed_at,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
