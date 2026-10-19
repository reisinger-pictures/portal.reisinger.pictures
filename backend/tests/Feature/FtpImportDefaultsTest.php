<?php

namespace Tests\Feature;

use App\Services\FtpInboxImporter;
use Tests\TestCase;

/**
 * The documented import defaults, pinned by value (D-35/D-36/D-37).
 *
 * A behavioural test can only show that *a* batch of 20 files is imported: with
 * `max_file_bytes` set to `'not-a-number'`, a run over a 1 KiB fixture looks
 * identical whether the fallback is 20 MiB, 0 or 1 GiB. The difference only
 * shows up in production, where it is either a rejection of a legitimate upload
 * or an unbounded read. So the numbers themselves are asserted here, and the
 * behavioural test (`FtpImportTest`) stays what it is: proof that the resolved
 * limits are actually applied.
 *
 * Every shape of "unusable" is covered, because they are not equivalent in PHP:
 * `env('X')` with `X=` yields `''`, `(int) ''` is 0, `(int) 'not-a-number'` is 0
 * and `(int) '0'` is 0 — while `null` (the variable absent) is a fourth case.
 */
class FtpImportDefaultsTest extends TestCase
{
    /**
     * The defaults, written once as the assertion target.
     *
     * @var array{max_files: int, max_file_bytes: int, max_batch_bytes: int}
     */
    private const IMPORT_DEFAULTS = [
        'max_files' => 20,
        'max_file_bytes' => 20 * 1024 * 1024,
        'max_batch_bytes' => 100 * 1024 * 1024,
    ];

    public function test_every_unusable_value_falls_back_to_the_documented_defaults(): void
    {
        $importer = app(FtpInboxImporter::class);

        foreach ([null, '', '   ', 'not-a-number', 0, '0', -1, '-5', 0.5] as $unusable) {
            config([
                'ftp.import.max_files' => $unusable,
                'ftp.import.max_file_bytes' => $unusable,
                'ftp.import.max_batch_bytes' => $unusable,
                'ftp.import.chain_max_batches' => $unusable,
                'ftp.import.chain_max_failures' => $unusable,
                'ftp.import.quarantine_after_attempts' => $unusable,
            ]);

            $context = 'Unusable value: '.var_export($unusable, true);

            $this->assertSame(self::IMPORT_DEFAULTS, $importer->importLimits(), $context);
            $this->assertSame(
                ['max_batches' => 25, 'max_failures' => 3],
                $importer->chainLimits(),
                $context,
            );
            $this->assertSame(3, $importer->quarantineAfterAttempts(), $context);
        }
    }

    /**
     * The other direction: a configured value must win over the default. Without
     * this half, a reader that always returned the defaults would pass the test
     * above.
     */
    public function test_a_configured_value_is_read_instead_of_the_default(): void
    {
        config([
            'ftp.import.max_files' => '3',
            'ftp.import.max_file_bytes' => '1024',
            'ftp.import.max_batch_bytes' => '2048',
            'ftp.import.chain_max_batches' => '7',
            'ftp.import.chain_max_failures' => '2',
            'ftp.import.quarantine_after_attempts' => '5',
        ]);

        $importer = app(FtpInboxImporter::class);

        $this->assertSame(
            ['max_files' => 3, 'max_file_bytes' => 1024, 'max_batch_bytes' => 2048],
            $importer->importLimits(),
        );
        $this->assertSame(['max_batches' => 7, 'max_failures' => 2], $importer->chainLimits());
        $this->assertSame(5, $importer->quarantineAfterAttempts());
    }
}
