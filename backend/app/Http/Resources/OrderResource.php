<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'total_net' => $this->whenLoaded('invoiceSnapshot', fn() => $this->invoiceSnapshot->total_net, 0),
            'total_gross' => $this->whenLoaded('invoiceSnapshot', fn() => $this->invoiceSnapshot->total_gross, 0),
            'tax_rate' => $this->tax_rate,
            'payment_method' => $this->payment_method,
            'is_quote_request' => $this->is_quote_request,
            'billing_name' => $this->billing_name,
            'billing_company' => $this->billing_company,
            'billing_street' => $this->billing_street,
            'billing_zip' => $this->billing_zip,
            'billing_city' => $this->billing_city,
            'user' => new UserResource($this->whenLoaded('user')),
            'invoice_snapshot' => new InvoiceSnapshotResource($this->whenLoaded('invoiceSnapshot')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
