<?php

namespace Tests\Unit\Support;

use App\Exceptions\DocumentContextMismatchException;
use App\Services\ModelFileStore;
use App\Support\BoundFileEncryption;
use Ercsctt\FileEncryption\Facades\FileEncrypter;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Envelope-level tenant/document binding for stored accounting documents.
 *
 * Storage::fake('local') and the FileEncrypter facade need the booted
 * application, so these tests extend Tests\TestCase even though they live in
 * the Unit suite; no database, HTTP or queue is involved.
 */
final class BoundFileEncryptionTest extends TestCase
{
    private const PLAIN_MARKER = 'VERTRAULICHER-BELEG-INHALT';

    private BoundFileEncryption $binding;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        // Same explicit test key pattern as ModelFileEncryptionTest. The value
        // is the documented phpunit.xml test key (base64 of 32 'X' bytes).
        config(['file-encryption.key' => 'base64:'.base64_encode(str_repeat('X', 32))]);
        app()->forgetInstance('file.encrypter');
        FileEncrypter::clearResolvedInstances();

        $this->binding = new BoundFileEncryption();
    }

    public function test_roundtrip_with_matching_context_returns_exact_bytes(): void
    {
        $path = 'documents/tenant-a/receipt-1.bin';
        $plain = self::PLAIN_MARKER."\x00\x01binär\n".random_bytes(64);

        $this->binding->store($path, $plain, 'tenant-a', 'doc-1');

        $this->assertSame($plain, $this->binding->read($path, 'tenant-a', 'doc-1'));
        $this->assertTrue($this->binding->isBound($path));
    }

    public function test_wrong_tenant_id_is_refused(): void
    {
        $path = 'documents/tenant-a/receipt-2.bin';
        $this->binding->store($path, self::PLAIN_MARKER, 'tenant-a', 'doc-1');

        try {
            $this->binding->read($path, 'tenant-b', 'doc-1');
            $this->fail('Expected the binding check to refuse a foreign tenant.');
        } catch (DocumentContextMismatchException $e) {
            $this->assertStringNotContainsString(self::PLAIN_MARKER, $e->getMessage());
        }
    }

    public function test_wrong_document_id_is_refused(): void
    {
        $path = 'documents/tenant-a/receipt-3.bin';
        $this->binding->store($path, self::PLAIN_MARKER, 'tenant-a', 'doc-1');

        try {
            $this->binding->read($path, 'tenant-a', 'doc-2');
            $this->fail('Expected the binding check to refuse a foreign document.');
        } catch (DocumentContextMismatchException $e) {
            $this->assertStringNotContainsString(self::PLAIN_MARKER, $e->getMessage());
        }
    }

    public function test_ciphertext_copied_into_another_tenants_slot_is_refused(): void
    {
        $source = 'documents/tenant-a/receipt-4.bin';
        $target = 'documents/tenant-b/receipt-4.bin';
        $this->binding->store($source, self::PLAIN_MARKER, 'tenant-a', 'doc-a');

        // Model the DB-dump/backup relocation: identical ciphertext bytes, new
        // storage slot, and the receiving tenant asks under its own context.
        Storage::disk('local')->copy($source, $target);

        try {
            $this->binding->read($target, 'tenant-b', 'doc-b');
            $this->fail('Expected a relocated ciphertext to be refused.');
        } catch (DocumentContextMismatchException $e) {
            $this->assertStringNotContainsString(self::PLAIN_MARKER, $e->getMessage());
        }
    }

    #[DataProvider('invalidEnvelopeProvider')]
    public function test_invalid_envelope_is_refused_without_leaking_plaintext(string $envelope): void
    {
        $path = 'documents/tenant-a/invalid-'.bin2hex(random_bytes(4)).'.bin';

        // Encrypt the crafted envelope with the package directly (via the
        // existing store helper): GCM authentication succeeds, only our
        // envelope contract rejects it. No plaintext is ever returned.
        app(ModelFileStore::class)->putEncrypted($path, $envelope);

        $this->assertFalse($this->binding->isBound($path));

        try {
            $this->binding->read($path, 'tenant-a', 'doc-1');
            $this->fail('Expected a malformed envelope to be refused.');
        } catch (DocumentContextMismatchException $e) {
            $this->assertStringNotContainsString(self::PLAIN_MARKER, $e->getMessage());
        }
    }

    /** @return array<string, array{string}> */
    public static function invalidEnvelopeProvider(): array
    {
        return [
            'no version prefix' => ['unrelated '.self::PLAIN_MARKER],
            'unknown version' => ['KUBND:v2:{"tenant_id":"tenant-a","document_id":"doc-1"}'."\n".self::PLAIN_MARKER],
            'truncated json' => ['KUBND:v1:{"tenant_id":"tenant-a" '."\n".self::PLAIN_MARKER],
            'missing document id' => ['KUBND:v1:{"tenant_id":"tenant-a"}'."\n".self::PLAIN_MARKER],
            'no header line break' => ['KUBND:v1:{"tenant_id":"tenant-a","document_id":"doc-1"}'.self::PLAIN_MARKER],
        ];
    }

    public function test_disk_contains_only_ciphertext_and_no_plaintext_temp_file(): void
    {
        $path = 'documents/tenant-a/receipt-5.bin';
        $this->binding->store($path, self::PLAIN_MARKER, 'tenant-a', 'doc-1');

        $disk = Storage::disk('local');
        $raw = $disk->get($path);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString(self::PLAIN_MARKER, $raw);
        $this->assertStringNotContainsString('tenant-a', $raw);
        $this->assertTrue(FileEncrypter::isEncrypted($disk->path($path)));

        $leftovers = array_values(array_filter(
            $disk->allFiles('documents'),
            static fn (string $file): bool => str_contains($file, '.plain-'),
        ));
        $this->assertSame([], $leftovers);
    }

    public function test_decryption_failure_is_mapped_to_the_binding_exception(): void
    {
        $path = 'documents/tenant-a/receipt-6.bin';
        $this->binding->store($path, self::PLAIN_MARKER, 'tenant-a', 'doc-1');

        // Rotate to a different key: GCM authentication now fails, and the
        // package's DecryptException must not escape as a separate contract.
        config(['file-encryption.key' => 'base64:'.base64_encode(str_repeat('Y', 32))]);
        app()->forgetInstance('file.encrypter');
        FileEncrypter::clearResolvedInstances();

        try {
            $this->binding->read($path, 'tenant-a', 'doc-1');
            $this->fail('Expected a decryption failure to be mapped to the binding exception.');
        } catch (DocumentContextMismatchException $e) {
            $this->assertStringNotContainsString(self::PLAIN_MARKER, $e->getMessage());
        }
    }

    public function test_empty_context_values_are_rejected_on_write(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->binding->store('documents/tenant-a/empty.bin', self::PLAIN_MARKER, '', 'doc-1');
    }

    public function test_large_payload_roundtrips_across_encryption_chunks(): void
    {
        $path = 'documents/tenant-a/large.bin';
        $plain = random_bytes(200_000);

        $this->binding->store($path, $plain, 'tenant-a', 'doc-1');

        $this->assertSame($plain, $this->binding->read($path, 'tenant-a', 'doc-1'));
    }
}
