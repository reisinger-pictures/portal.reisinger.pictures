<?php

namespace App\Support;

/**
 * The validation rule per **settings key** of the licence terms, and the money
 * keys among them.
 *
 * The rules live here and not in the controller because two consumers must
 * agree on them: the write path (the settings endpoint) and the
 * `ops:check-license-pricing` command, which checks an existing configuration
 * against the very rules the write path would apply to it. A second copy of
 * the numbers would drift from the first — and the check would then pass
 * values the server would reject.
 *
 * The rules are deliberately per **settings key** — not per the name the key
 * carries in a request. A legacy `srp_*` spelling is only an alternative name
 * for one of these keys and inherits that key's rule, so a client cannot pick
 * a weaker rule by picking an older name
 * (features/infrastructure/28-settings-key-meaning.md).
 *
 * `setup_fee`, `privacy_fee` and `extra_image_fee` are listed because the
 * endpoint *writes* them, not because they are request names — their only
 * request spelling is the legacy one (see the controller's LEGACY_ONLY_KEYS).
 */
final class LicenseTermRules
{
    /** @var array<string, string> */
    public const RULES = [
        'base_price' => 'nullable|integer|min:500',
        // The two studio-calculator money fields, in cents like every other
        // money key (owner decision 2026-09-28) and held to exactly the rule
        // `base_price` already carries. `integer` is the unit guard: a
        // fractional amount means the caller sent euros, and storing that
        // would be off by two decimal orders of magnitude. It was `numeric`
        // before, which was what let a client pick a weaker rule for a money
        // field than its own sibling in the same form — the defect the
        // settings-key-meaning decision (features/infrastructure/28) is about.
        // `min:500` (5 €) matches `base_price`; a deliberately free tier is
        // not a configuration the calculator can price, and the write path has
        // always rejected the sub-minimum for the other cent fields.
        'calc_base_price' => 'nullable|integer|min:500',
        'calc_hourly_rate' => 'nullable|integer|min:500',
        'calc_images_per_hour' => 'nullable|integer|min:1',
        'calc_outdoor_images_per_hour' => 'nullable|integer|min:1',
        'calc_flatrate_multiplier' => 'nullable|numeric|min:1|max:10',
        'term_editorial' => 'nullable|string',
        'term_commercial' => 'nullable|string',
        'term_1_year' => 'nullable|string',
        'term_unlimited' => 'nullable|string',
        'term_territory_national' => 'nullable|string',
        'term_territory_international' => 'nullable|string',
        'mult_commercial' => 'required|numeric|min:1',
        'mult_unlimited' => 'required|numeric|min:1',
        'mult_international' => 'required|numeric|min:1',
        'term_web' => 'nullable|string',
        'term_print' => 'nullable|string',
        'term_original' => 'nullable|string',
        // Resolution prices in cents, the unit the licence card submits
        // (`Math.round(euros * 100)`) and the table stores. `integer` is
        // the unit guard: a fractional amount means a caller sent euros, and
        // storing that would be off by two decimal orders of
        // magnitude. `nullable` (write-when-present) rather than
        // `required` because this endpoint is also written by clients that
        // send a partial payload — the shooting calculator's save carries
        // only `calc_*` plus the `mult_*` multipliers — and because the
        // three keys reach the table solely through the idempotent
        // `DatabaseSeeder`, so a row that predates them must not turn
        // every save into a 422. `min:0` matches the card's `min="0"`
        // input, so a deliberately free tier stays saveable.
        'price_web' => 'nullable|integer|min:0',
        'price_print' => 'nullable|integer|min:0',
        'price_original' => 'nullable|integer|min:0',
        // The three SRP fees are cent amounts like `price_*`, not euros: the
        // calculator card submits `Math.round(euros * 100)`, the seeder stores
        // 5000 / 20000 / 1500, and every consumer reads them back as
        // `Number(value)/100`. `integer` is therefore the same unit guard
        // `price_*` already carries — `numeric` would admit a fractional cent
        // (`'5000.5'`) that no client can produce and no consumer can
        // represent, and store it indistinguishably from a validated amount.
        // `min:0` stays, matching the card's `min="0"` input, so a
        // deliberately free setup fee remains saveable.
        'setup_fee' => 'nullable|integer|min:0',
        'privacy_fee' => 'nullable|integer|min:0',
        'extra_image_fee' => 'nullable|integer|min:0',
    ];

    /**
     * The money keys of the licence terms — the ones the read endpoint
     * projects as **cents** and the ones the check command inspects.
     *
     * @var list<string>
     */
    public const MONEY_KEYS = [
        'base_price',
        'calc_base_price',
        'calc_hourly_rate',
        'price_web',
        'price_print',
        'price_original',
        'setup_fee',
        'privacy_fee',
        'extra_image_fee',
    ];

    /**
     * The minimum the write path enforces for a key, or null when the key
     * carries no minimum.
     */
    public static function minimum(string $key): ?int
    {
        $rule = self::RULES[$key] ?? null;

        if ($rule === null) {
            return null;
        }

        if (preg_match('/(?:^|\|)min:(\d+)(?:\||$)/', $rule, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
