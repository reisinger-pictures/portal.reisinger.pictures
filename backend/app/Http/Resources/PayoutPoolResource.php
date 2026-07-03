<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PayoutPoolResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'month' => $this->month,
            'year' => $this->year,
            'gross_amount_cents' => $this->gross_amount_cents,
            'net_pool_cents' => $this->net_pool_cents,
            'photographer_share_percent' => $this->photographer_share_percent,
            'total_unique_downloads' => $this->total_unique_downloads,
            'total_shares' => $this->total_shares,
            'value_per_share_cents' => $this->value_per_share_cents,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
