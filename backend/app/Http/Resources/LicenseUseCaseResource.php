<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LicenseUseCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'base_price' => $this->base_price,
            'flatrate_tier' => $this->flatrate_tier,
            'sort_order' => $this->sort_order,
            'is_commercial' => $this->is_commercial,
        ];
    }
}
