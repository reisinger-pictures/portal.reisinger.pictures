<?php

declare(strict_types=1);

namespace Tests\Unit\Eur;

use App\Services\Eur\EurPdfSignatureService;
use Dompdf\Dompdf;
use LSNepomuceno\Signet\Signet;
use PHPUnit\Framework\TestCase;

/** D-84: CMS-/PAdES-Prüfung ohne Trust- oder QES-Behauptung. */
final class EurPdfSignatureServiceTest extends TestCase
{
    private EurPdfSignatureService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new EurPdfSignatureService;
    }

    public function test_a_real_pades_signature_over_the_original_bytes_verifies(): void
    {
        $original = $this->pdf();
        $signed = $this->sign($original);

        $this->assertTrue($this->service->verify($signed, $original));
    }

    public function test_unsigned_and_tampered_documents_are_rejected(): void
    {
        $original = $this->pdf();
        $signed = $this->sign($original);
        $tampered = $signed;
        $position = strpos($tampered, '/Contents <');
        $this->assertNotFalse($position);
        $hexStart = $position + strlen('/Contents <');
        $tampered[$hexStart] = $tampered[$hexStart] === 'A' ? 'B' : 'A';

        $this->assertFalse($this->service->verify($original, $original));
        $this->assertFalse($this->service->verify($signed.$original, $original));
        $this->assertFalse($this->service->verify($tampered, $original));
    }

    public function test_a_valid_signature_over_foreign_bytes_is_rejected(): void
    {
        $original = $this->pdf();
        $foreign = $this->pdf('foreign');
        $foreignSigned = $this->sign($foreign);

        $this->assertFalse($this->service->verify($foreignSigned, $original));
    }

    private function pdf(string $content = 'PAdES'): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml("<h1>{$content}</h1>");
        $dompdf->render();

        return $dompdf->output();
    }

    private function sign(string $bytes): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'EAR Unit Testsigner'], $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        self::assertNotFalse($key);
        self::assertNotFalse($csr);
        self::assertNotFalse($x509);
        openssl_x509_export($x509, $certificate);
        openssl_pkey_export($key, $privateKey);

        return (new Signet)->newSignature()
            ->certificateFromPem((string) $certificate, (string) $privateKey)
            ->pdfContents($bytes, 'signiert.pdf')
            ->sign()
            ->contents();
    }
}
