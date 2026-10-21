<?php

namespace App\Console\Commands;

use App\Enums\Brand;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use App\Support\BrandSelection;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;

/**
 * Report the settings rows a seed would overwrite.
 *
 * `backend/AGENTS.md` ("Database Setup Policy") requires that the values
 * affected by a seed in production are checked **before** the seed runs: the
 * seeder is authoritative for the keys it declares and writes them with an
 * `upsert`, so a `db:seed --force` — and `app:seed-if-fresh`, which calls the
 * same seeder — replaces every value an operator set through the UI.
 *
 * This command answers that question mechanically: it compares the seeder's
 * own values (`DatabaseSeeder::AUTHORITATIVE_SETTINGS` — the same constant the
 * seeder writes, not a copy of it) with the values that are stored now, and
 * names every key that would change. A key that differs is not an error: the
 * production price of `base_price` is deliberately not the seeder's. The
 * decision whether to seed belongs to the human; this command makes the
 * consequences visible before the decision.
 *
 * The exit code says whether anything would change: 0 = the stored values are
 * the seeder's, 1 = at least one key would be overwritten.
 */
class CheckSeededSettings extends Command
{
    protected $signature = 'ops:check-seeded-settings
                            {--brand= : The brand to resolve (default: the current context, in a console B2B)}';

    protected $description = 'Report the settings rows a seed would overwrite.';

    public function handle(SettingResolver $resolver): int
    {
        $brand = $this->resolveBrand();
        if ($brand === false) {
            return self::FAILURE;
        }

        if ($brand !== null) {
            BrandRegistry::set($brand);
        }

        $rows = [];
        $differences = [];

        foreach (DatabaseSeeder::AUTHORITATIVE_SETTINGS as $key => $seeded) {
            $current = $resolver->get($key);
            $missing = $current === null;
            $same = ! $missing && (string) $current === (string) $seeded;

            $rows[] = [
                $key,
                $this->shorten((string) $seeded),
                $missing ? '—' : $this->shorten((string) $current),
                $same ? 'gleich' : ($missing ? 'NICHT GESPEICHERT' : 'WEICHT AB'),
            ];

            if (! $same) {
                $differences[$key] = $missing;
            }
        }

        $this->line(sprintf(
            'Marke: %s — die Werte, die ein Seed überschreiben würde (Quelle: der Seeder selbst).',
            BrandRegistry::currentOrDefault()->value,
        ));
        $this->table(['key', 'Seeder', 'gespeichert', 'Zustand'], $rows);

        if ($differences !== []) {
            foreach ($differences as $key => $missing) {
                $this->line($missing
                    ? sprintf('  %s: nicht gespeichert — ein Seed legt die Zeile an.', $key)
                    : sprintf('  %s: der gespeicherte Wert weicht vom Seeder ab.', $key));
            }

            $this->error(sprintf(
                'Ein Seed würde %d von %d Werten anlegen oder überschreiben (%s).',
                count($differences),
                count(DatabaseSeeder::AUTHORITATIVE_SETTINGS),
                implode(', ', array_keys($differences)),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Alle %d vom Seeder autoritativen Werte sind gespeichert — ein Seed ändert nichts.',
            count(DatabaseSeeder::AUTHORITATIVE_SETTINGS),
        ));

        return self::SUCCESS;
    }

    /**
     * The brand to resolve: the caller's choice, null to keep the context the
     * application already has, false when the caller named a brand that does
     * not exist.
     *
     * An unknown id is refused instead of being resolved to B2B: the report
     * would otherwise list the values of one brand under the label of
     * another.
     */
    private function resolveBrand(): Brand|null|false
    {
        $id = $this->option('brand');

        if (! is_string($id) || trim($id) === '') {
            return null;
        }

        $brand = BrandSelection::resolve($id);

        if ($brand === null) {
            $this->error(sprintf(
                'Unbekannte Marke "%s" — bekannt: %s.',
                trim($id),
                implode(', ', BrandSelection::known()),
            ));

            return false;
        }

        return $brand;
    }

    /**
     * A stored value can be a whole licence text; the table shows enough to
     * recognise it without wrapping the line.
     */
    private function shorten(string $value, int $length = 46): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length - 1).'…';
    }
}
