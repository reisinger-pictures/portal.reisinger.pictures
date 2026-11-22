<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * EÜR · Clean-Room-Übergabe — Update-Erkennung über MANIFEST.json.
 *
 * Framework-/DB-frei (PHPUnit\Framework\TestCase, NICHT Tests\TestCase).
 * Der gepinnte Dokumentenstand ist schreibgeschützt; jede inhaltliche Änderung
 * muss diesen Test rot werden lassen, bis die Version bewusst nachgezogen ist.
 */
class CleanRoomFixtureIntegrityTest extends TestCase
{
    private const CLEANROOM_DIR = __DIR__.'/../../../features/eur/cleanroom';

    private array $manifest;

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifest = $this->readJson(self::CLEANROOM_DIR.'/MANIFEST.json');
        $this->fixture = $this->readJson(self::CLEANROOM_DIR.'/cleanroom-parity-2026.json');
    }

    public function test_every_pinned_document_matches_manifest_checksum_and_size(): void
    {
        $this->assertCount($this->manifest['file_count'], $this->manifest['files']);

        foreach ($this->manifest['files'] as $entry) {
            $path = self::CLEANROOM_DIR.'/'.$entry['name'];
            $this->assertFileExists($path, $entry['name']);
            $this->assertSame($entry['sha256'], hash_file('sha256', $path), $entry['name']);
            $this->assertSame($entry['bytes'], filesize($path), $entry['name']);
        }
    }

    public function test_every_copied_artifact_matches_manifest_checksum_and_size(): void
    {
        $this->assertSame(2, $this->manifest['schema_version']);
        $this->assertSame('2026.2', $this->manifest['artifact_version']);
        $this->assertSame('75f230c07010db91755f78fdbd9ae591d52c7e85', $this->manifest['artifact_source_commit']);
        $this->assertCount($this->manifest['artifact_count'], $this->manifest['artifacts']);

        foreach ($this->manifest['artifacts'] as $entry) {
            $path = self::CLEANROOM_DIR.'/artifacts/'.$entry['path'];
            $this->assertFileExists($path, $entry['path']);
            $this->assertSame($entry['sha256'], hash_file('sha256', $path), $entry['path']);
            $this->assertSame($entry['bytes'], filesize($path), $entry['path']);
        }
    }

    public function test_handoff_document_matches_manifest_checksum(): void
    {
        $path = self::CLEANROOM_DIR.'/HANDOFF.md';

        $this->assertFileExists($path);
        $this->assertSame($this->manifest['handoff_sha256'], hash_file('sha256', $path));
    }

    public function test_provenance_document_matches_manifest_checksum(): void
    {
        $path = self::CLEANROOM_DIR.'/PROVENANCE.md';

        $this->assertFileExists($path);
        $this->assertSame($this->manifest['provenance_sha256'], hash_file('sha256', $path));
    }

    public function test_fixture_is_pinned_to_business_year_2026(): void
    {
        $this->assertSame(2026, $this->manifest['business_year']);
        $this->assertSame(2026, $this->fixture['oracle']['year']);
        $this->assertSame(2026, $this->fixture['basis']['business_year']);
    }

    public function test_vector_count_is_fixed_at_five_scenarios_and_nineteen_cells(): void
    {
        $cells = array_sum(array_map(
            static fn (array $scenario): int => count($scenario['expected']),
            $this->fixture['scenarios']
        ));

        $this->assertSame($this->manifest['parity_scenarios'], count($this->fixture['scenarios']));
        $this->assertSame($this->manifest['parity_cells'], $cells);
        $this->assertCount(19, $this->fixture['oracle']['checks']);
    }

    public function test_every_expected_cell_carries_an_integer_canonical_value(): void
    {
        $allowedUnits = ['cents', 'minutes', 'per-mille', 'per-10000', 'percent'];

        foreach ($this->fixture['scenarios'] as $scenario) {
            $this->assertNotEmpty($scenario['inputs'], $scenario['id']);

            foreach ($scenario['expected'] as $cell) {
                $label = $scenario['id'].'/'.$cell['cell'];
                $this->assertIsInt($cell['canonical'], $label);
                $this->assertContains($cell['unit'], $allowedUnits, $label);
                $this->assertNotSame('', $cell['verify'], $label);
            }
        }
    }

    /**
     * Wirksamkeitsnachweis: ein verändertes Byte genügt, damit die
     * Manifest-Prüfung nicht mehr grün ist. Die gepinnte Datei wird dabei
     * nicht angefasst; geprüft wird gegen eine temporäre Kopie.
     */
    public function test_changed_artifact_byte_is_detected(): void
    {
        $entry = $this->manifest['artifacts'][0];
        $source = self::CLEANROOM_DIR.'/artifacts/'.$entry['path'];

        $temp = tempnam(sys_get_temp_dir(), 'eur-artifact-');
        $this->assertIsString($temp);
        copy($source, $temp);

        try {
            $contents = (string) file_get_contents($temp);
            file_put_contents($temp, $contents."\n");

            $this->assertNotSame($entry['sha256'], hash_file('sha256', $temp));
        } finally {
            @unlink($temp);
        }
    }

    private function readJson(string $path): array
    {
        $this->assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        return $data;
    }
}
