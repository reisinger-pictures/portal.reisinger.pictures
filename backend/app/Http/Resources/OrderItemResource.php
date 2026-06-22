<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'photo_id' => $this->photo_id,
            'tier' => $this->tier,
            'use_case_id' => $this->use_case_id,
            'price' => $this->price,
            'qty' => $this->qty,
            'photo' => new PhotoResource($this->whenLoaded('photo')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
