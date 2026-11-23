<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Exceptions\DocumentContextMismatchException;
use App\Http\Controllers\Controller;
use App\Models\EurDocument;
use App\Support\BoundFileEncryption;
use Illuminate\Http\Response;
use LSNepomuceno\Signet\Signet;

/**
 * EAR · Test-only: erzeugt eine echte PAdES-Signatur über einen Portal-Beleg.
 *
 * Nur in `local`/`testing` registriert (routes/api.php) und zusätzlich hier
 * abgesichert. Das Zertifikat ist selbstsigniert und wird pro Prozess neu
 * erzeugt; es hat keine Vertrauensstellung und dient ausschließlich dazu, der
 * E2E-Suite einen echten CMS-Signaturpfad zu liefern. Es gibt keine öffentliche
 * `/verify`- und keine Produktions-Signierfunktion.
 */
final class EurTestSignatureController extends Controller
{
    public function sign(BoundFileEncryption $encryption, int $id): Response
    {
        abort_unless(app()->environment('local', 'testing'), 404);

        $document = EurDocument::forCurrentBrand()->findOrFail($id);

        try {
            $original = $encryption->read(
                $document->storage_path,
                (string) $document->getRawOriginal('brand'),
                (string) $document->id,
            );
        } catch (DocumentContextMismatchException) {
            abort(404, 'Belegdatei nicht gefunden');
        }

        [$certificate, $privateKey] = $this->testCertificate();
        $signed = (new Signet)->newSignature()
            ->certificateFromPem($certificate, $privateKey)
            ->pdfContents($original, 'signiert.pdf')
            ->info(name: 'EAR E2E Testsigner', reason: 'E2E')
            ->sign();

        return response($signed->contents(), 200, ['Content-Type' => 'application/pdf']);
    }

    /**
     * @return array{string, string} PEM certificate and private key
     */
    private function testCertificate(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'EAR E2E Testsigner'], $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);

        if ($key === false || $csr === false || $x509 === false) {
            throw new \RuntimeException('Could not generate the E2E test certificate.');
        }

        openssl_x509_export($x509, $certificate);
        openssl_pkey_export($key, $privateKey);

        return [(string) $certificate, (string) $privateKey];
    }
}
