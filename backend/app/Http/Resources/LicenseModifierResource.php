<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LicenseModifierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'percent_surcharge' => $this->percent_surcharge,
            'is_included_in_flatrate' => $this->is_included_in_flatrate,
            'sort_order' => $this->sort_order,
        ];
    }
}
