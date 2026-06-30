<?php

namespace App\Services;

use App\Constants\TierRanks;
use App\Enums\Brand;
use App\Models\LicenseUseCase;
use App\Models\LicenseModifier;
use App\Support\BrandRegistry;

class PricingService
{
    public function calculateItemPriceCents(string $useCaseId, ?array $modifierIds, string $userFlatrateLevel): array
    {
        $useCase = LicenseUseCase::findOrFail($useCaseId);
        $this->guardBrand($useCase->brand);
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
                $this->guardBrand($mod->brand);
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

    /**
     * Defense-in-depth (spec §3.6): reject cross-brand price injection. Only enforced
     * when a brand context is set (HTTP/host); in CLI/test contexts where brand is unset
     * the guard is a no-op to avoid breaking unrelated flows.
     *
     * @param  \App\Enums\Brand|string|null  $rowBrand  the row's brand (enum via cast, or raw value)
     * @throws \RuntimeException when the row's brand does not match the current brand.
     */
    protected function guardBrand(mixed $rowBrand): void
    {
        $current = BrandRegistry::current();
        // No brand context (CLI/tests): allow.
        if ($current === null) {
            return;
        }
        if ($rowBrand === null) {
            return;
        }
        $rowValue = $rowBrand instanceof Brand ? $rowBrand->value : (string) $rowBrand;
        if ($rowValue !== $current->value) {
            throw new \RuntimeException(
                'Cross-brand access denied: row brand [' . $rowValue . '] does not match current brand [' . $current->value . '].'
            );
        }
    }
}
