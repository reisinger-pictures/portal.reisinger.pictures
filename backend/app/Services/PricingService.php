<?php

namespace App\Services;

use App\Constants\TierRanks;
use App\Models\LicenseUseCase;
use App\Models\LicenseModifier;

class PricingService
{
    public function calculateItemPriceCents(string $useCaseId, ?array $modifierIds, string $userFlatrateLevel): array
    {
        $useCase = LicenseUseCase::findOrFail($useCaseId);
        $basePriceCents = (int) $useCase->base_price;
        $tier = $useCase->flatrate_tier ?? 'web';
        
        $ranks = TierRanks::RANKS;
        $userRank = $ranks[$userFlatrateLevel] ?? 0;
        $reqRank = $ranks[$tier] ?? 0;
        
        $isBaseCovered = $userRank >= $reqRank;
        $coveredBasePriceCents = $isBaseCovered ? 0 : $basePriceCents;

        $surchargeAmountCents = 0;
        $modifierNames = [];
        
        if (!empty($modifierIds)) {
            $modifiers = LicenseModifier::whereIn('id', $modifierIds)->get();
            foreach ($modifiers as $mod) {
                $modifierNames[] = $mod->name;
                if ($isBaseCovered && $mod->is_included_in_flatrate) continue;
                $surchargeAmountCents += (int) round($basePriceCents * ((float)$mod->percent_surcharge / 100));
            }
        }

        return [
            'total_cents' => $coveredBasePriceCents + $surchargeAmountCents,
            'tier' => $tier,
            'use_case_name' => $useCase->name,
            'modifier_names' => $modifierNames
        ];
    }
}
