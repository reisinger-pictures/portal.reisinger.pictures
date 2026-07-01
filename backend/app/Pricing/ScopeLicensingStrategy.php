<?php

namespace App\Pricing;

use App\Constants\TierRanks;
use App\Contracts\PricingStrategy;
use App\Enums\Brand;
use App\Models\LicenseModifier;
use App\Models\LicenseUseCase;
use App\Models\User;
use App\Support\BrandRegistry;

class ScopeLicensingStrategy implements PricingStrategy
{
    /**
     * Calculate prices using the RP license-based model (use cases + modifiers + flatrate tiers).
     *
     * Each non-quote item is priced individually via LicenseUseCase and LicenseModifier records.
     * The result mirrors the behaviour of the original PricingService::calculateItemPriceCents().
     */
    public function calculateCart(array $items, User $user, ?string $couponCode = null): array
    {
        $pricedItems = [];
        $totalCents = 0;

        foreach ($items as $item) {
            $itemId = $item['id'] ?? 0;

            if (!empty($item['is_quote'])) {
                $pricedItems[] = [
                    'itemId' => $itemId,
                    'priceCents' => 0,
                    'tier' => 'web',
                    'useCaseName' => 'Anfrage',
                    'modifierNames' => [],
                ];
                continue;
            }

            $useCaseId = $item['license_use_case_id'] ?? '';
            $modifierIds = $item['license_modifier_ids'] ?? [];

            $result = $this->calculateSingleItem($useCaseId, $modifierIds, $user->flatrate_level ?? 'none');

            $pricedItems[] = [
                'itemId' => $itemId,
                'priceCents' => $result['total_cents'],
                'tier' => $result['tier'],
                'useCaseName' => $result['use_case_name'],
                'modifierNames' => $result['modifier_names'],
            ];
            $totalCents += $result['total_cents'];
        }

        return [
            'items' => $pricedItems,
            'totalCents' => $totalCents,
            'discountCents' => 0,
            'couponId' => null,
        ];
    }

    /**
     * Replicate the original PricingService::calculateItemPriceCents() logic.
     *
     * @param  string  $useCaseId
     * @param  array   $modifierIds
     * @param  string  $userFlatrateLevel
     * @return array{total_cents: int, tier: string, use_case_name: string, modifier_names: array}
     */
    private function calculateSingleItem(string $useCaseId, array $modifierIds, string $userFlatrateLevel): array
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
                if ($isBaseCovered && $mod->is_included_in_flatrate) {
                    continue;
                }
                $surchargeAmountCents += (int) round($basePriceCents * ((float) $mod->percent_surcharge / 100));
            }
        }

        return [
            'total_cents' => $coveredBasePriceCents + $surchargeAmountCents,
            'tier' => $tier,
            'use_case_name' => $useCase->name,
            'modifier_names' => $modifierNames,
        ];
    }

    /**
     * Defense-in-depth: reject cross-brand price injection.
     *
     * @param  \App\Enums\Brand|string|null  $rowBrand
     * @throws \RuntimeException when the row's brand does not match the current brand.
     */
    protected function guardBrand(mixed $rowBrand): void
    {
        $current = BrandRegistry::current();
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
