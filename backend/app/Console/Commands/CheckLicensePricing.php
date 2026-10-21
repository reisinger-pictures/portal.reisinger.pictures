<?php

namespace App\Console\Commands;

use App\Enums\Brand;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use App\Support\LicenseTermRules;
use App\Values\BrandConfig;
use Illuminate\Console\Command;

/**
 * Check the effective licence prices against the rules the write path applies.
 *
 * This is the counter-check before a deploy that touches `base_price`. The read
 * endpoint is super-admin only and an anonymous request answers 401, so the
 * value could only be read by hand. This command resolves the effective values
 * the way the buy flow resolves them — brand scope with the B2B fallback — and
 * checks them against the very rules the settings endpoint would apply to a
 * write. Those rules live in `LicenseTermRules::RULES`, the same table the
 * controller derives its request contract from, so the check cannot pass a
 * value the server would reject.
 *
 * The unit guard matters as much as the minimum: an amount stored in euros
 * ('35.00') reads as 35 cents, which is the defect a fresh database once
 * carried (`V004` pre-seeded '35.00'). The raw stored value is therefore
 * reported, and a non-integer amount is a violation even when the number
 * would pass the minimum.
 */
class CheckLicensePricing extends Command
{
    protected $signature = 'ops:check-license-pricing
                            {--brand= : The brand to resolve (default: the current context, in a console B2B)}
                            {--expect= : Expected values in cents, comma separated as key=value}';

    protected $description = 'Check the effective licence prices against the write path rules.';

    public function handle(SettingResolver $resolver): int
    {
        $brand = $this->resolveBrand();
        if ($brand !== null) {
            BrandRegistry::set($brand);
        }

        $expectations = $this->expectations();
        if ($expectations === null) {
            return self::FAILURE;
        }

        $rows = [];
        $violations = [];

        foreach (LicenseTermRules::MONEY_KEYS as $key) {
            $row = $this->row($resolver, $key, $expectations);
            $rows[] = $row['row'];

            if ($row['violation'] !== null) {
                $violations[] = $row['violation'];
            }
        }

        $this->line(sprintf(
            'Brand: %s — the effective values, resolved like the buy flow resolves them.',
            BrandRegistry::currentOrDefault()->value,
        ));
        $this->table(['key', 'stored', 'cents', 'EUR', 'minimum', 'state'], $rows);

        if ($violations !== []) {
            // Plain lines, not error blocks: a block wraps its text at the
            // terminal width, which would split a message in the middle.
            foreach ($violations as $violation) {
                $this->line('  '.$violation);
            }

            $this->error(sprintf(
                'Licence pricing check FAILED (%d violation(s) in %d keys).',
                count($violations),
                count(LicenseTermRules::MONEY_KEYS),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Licence pricing check passed (%d keys, 0 violations).',
            count(LicenseTermRules::MONEY_KEYS),
        ));

        return self::SUCCESS;
    }

    /**
     * One money key: the stored value, the effective amount in cents and the
     * verdict against the write path's rule and the caller's expectation.
     *
     * @param  array<string, int>  $expectations
     * @return array{row: list<string>, violation: string|null}
     */
    private function row(SettingResolver $resolver, string $key, array $expectations): array
    {
        $minimum = LicenseTermRules::minimum($key);
        $raw = $resolver->get($key);

        if ($raw === null) {
            return [
                'row' => [$key, '—', '—', '—', $minimum === null ? '—' : (string) $minimum, 'MISSING'],
                'violation' => sprintf('%s: no value', $key),
            ];
        }

        $stored = (string) $raw;
        $cents = (int) $stored;
        $state = 'ok';
        $violation = null;

        if (preg_match('/^-?\d+$/', $stored) !== 1) {
            $state = 'NOT AN INTEGER';
            $violation = sprintf(
                '%s: not an integer amount of cents ("%s")',
                $key,
                $stored,
            );
        }

        if ($violation === null && $minimum !== null && $cents < $minimum) {
            $state = 'BELOW THE MINIMUM';
            $violation = sprintf(
                '%s: below the minimum (%d < %d cents)',
                $key,
                $cents,
                $minimum,
            );
        }

        if ($violation === null && array_key_exists($key, $expectations) && $cents !== $expectations[$key]) {
            $state = 'DIFFERS';
            $violation = sprintf(
                '%s: %d cents, expected %d cents',
                $key,
                $cents,
                $expectations[$key],
            );
        }

        return [
            'row' => [
                $key,
                $stored,
                (string) $cents,
                number_format($cents / 100, 2, '.', ''),
                $minimum === null ? '—' : (string) $minimum,
                $state,
            ],
            'violation' => $violation,
        ];
    }

    /**
     * The brand to resolve: the caller's choice, or null to keep the context
     * the application already has (a console run has none, so the resolver
     * falls back to B2B — the brand the production portal runs).
     */
    private function resolveBrand(): Brand|BrandConfig|null
    {
        $id = $this->option('brand');

        if (! is_string($id) || trim($id) === '') {
            return null;
        }

        $id = trim($id);
        $enum = Brand::tryFrom($id);

        if ($enum !== null) {
            return $enum;
        }

        // A brand that has no enum case yet (a white-label portal) still has a
        // settings scope; the resolver falls back to B2B for every key the
        // brand does not carry itself.
        return new BrandConfig(
            id: $id,
            name: $id,
            theme: Brand::B2B->value,
            portalName: $id,
            impressumUrl: null,
            logoPath: null,
        );
    }

    /**
     * The expected values in cents, or null when the caller passed something
     * that cannot be read (the error is printed then).
     *
     * @return array<string, int>|null
     */
    private function expectations(): ?array
    {
        $raw = $this->option('expect');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $expectations = [];

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);

            if ($pair === '') {
                continue;
            }

            if (! str_contains($pair, '=')) {
                $this->error(sprintf('The expectation "%s" is not a key=value pair.', $pair));

                return null;
            }

            [$key, $value] = explode('=', $pair, 2);
            $key = trim($key);
            $value = trim($value);

            if (! in_array($key, LicenseTermRules::MONEY_KEYS, true)) {
                $this->error(sprintf('"%s" is not a licence money key.', $key));

                return null;
            }

            if (preg_match('/^\d+$/', $value) !== 1) {
                $this->error(sprintf('The expectation for "%s" is not an amount in cents.', $key));

                return null;
            }

            $expectations[$key] = (int) $value;
        }

        return $expectations;
    }
}
