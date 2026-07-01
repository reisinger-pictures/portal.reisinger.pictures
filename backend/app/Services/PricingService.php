<?php

namespace App\Services;

use App\Contracts\PricingStrategy;
use App\Models\User;

class PricingService
{
    private PricingStrategy $strategy;

    public function __construct(PricingStrategy $strategy)
    {
        $this->strategy = $strategy;
    }

    /**
     * Convenience method for single-item price calculation (RP compatibility).
     *
     * Delegates to the injected PricingStrategy, wrapping the call in a single-item cart.
     *
     * @param  string  $useCaseId
     * @param  array|null  $modifierIds
     * @param  string  $userFlatrateLevel
     * @return array{total_cents: int, tier: string, use_case_name: string, modifier_names: array}
     */
    public function calculateItemPriceCents(string $useCaseId, ?array $modifierIds, string $userFlatrateLevel): array
    {
        $user = new User();
        $user->flatrate_level = $userFlatrateLevel;

        $result = $this->strategy->calculateCart([
            [
                'id' => 0,
                'license_use_case_id' => $useCaseId,
                'license_modifier_ids' => $modifierIds ?? [],
                'is_quote' => false,
            ],
        ], $user);

        $item = $result['items'][0] ?? [];

        return [
            'total_cents' => $item['priceCents'] ?? 0,
            'tier' => $item['tier'] ?? '',
            'use_case_name' => $item['useCaseName'] ?? '',
            'modifier_names' => $item['modifierNames'] ?? [],
        ];
    }
}
