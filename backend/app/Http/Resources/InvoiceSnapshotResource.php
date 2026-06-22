<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceSnapshotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoice_number,
            'customer_details' => $this->customer_details,
            'total_net' => $this->total_net,
            'total_gross' => $this->total_gross,
            'tax_rate' => $this->tax_rate,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
