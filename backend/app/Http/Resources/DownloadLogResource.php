<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DownloadLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_name_snapshot' => $this->user_name_snapshot,
            'gallery_id' => $this->gallery_id,
            'gallery_name_snapshot' => $this->gallery_name_snapshot,
            'order_id' => $this->order_id,
            'item_type' => $this->item_type,
            'resolution_tier' => $this->resolution_tier,
            'photo_count' => $this->photo_count,
            'thumb_url' => $this->thumb_url,
            'created_at' => $this->created_at?->toISOString(),
            'gallery' => $this->whenLoaded('gallery'),
        ];
    }
}
