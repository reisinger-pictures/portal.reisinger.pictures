<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'brand' => $this->brand,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'max_items' => $this->max_items,
            'scope_type' => $this->scope_type,
            'scope_id' => $this->scope_id,
            'max_uses_global' => $this->max_uses_global,
            'max_uses_per_account' => $this->max_uses_per_account,
            'used_count' => $this->used_count,
            'expires_at' => $this->expires_at?->toISOString(),
            'active' => $this->active,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
