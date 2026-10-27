<?php

namespace App\Http\Resources;

use App\Models\PayoutCorrection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PhotographerStatementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'sequence_number' => $this->sequence_number,
            'month' => $this->month,
            'year' => $this->year,
            'total_shares_earned' => $this->total_shares_earned,
            'pool_earnings_cents' => $this->pool_earnings_cents,
            'delta_surcharge_earnings_cents' => $this->delta_surcharge_earnings_cents,
            'earned_amount_cents' => $this->earned_amount_cents,
            'rolled_over_amount_cents' => $this->rolled_over_amount_cents,
            'total_payable_cents' => $this->total_payable_cents,
            'corrections' => $this->corrections()
                ->with('order.invoiceSnapshot')
                ->get()
                ->map(static fn (PayoutCorrection $correction): array => [
                    'id' => $correction->id,
                    'order_id' => $correction->order_id,
                    'order_reference' => $correction->order?->invoiceSnapshot?->invoice_number
                        ?? $correction->order_id,
                    'amount_cents' => (int) $correction->amount_cents,
                    'closed_month' => (int) $correction->closed_month,
                    'closed_year' => (int) $correction->closed_year,
                    'settled_month' => (int) $correction->settled_month,
                    'settled_year' => (int) $correction->settled_year,
                    'status' => $correction->settled_at === null ? 'pending' : 'settled',
                ])
                ->values(),
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
