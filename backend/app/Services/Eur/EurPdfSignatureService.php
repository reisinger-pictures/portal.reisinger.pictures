<?php

declare(strict_types=1);

namespace App\Services\Eur;

use LSNepomuceno\Signet\Io\StringSource;
use LSNepomuceno\Signet\Signet;
use Throwable;

/**
 * EAR · Upload-Prüfung der extern signierten PDF (D-81, D-84).
 *
 * Die Bibliothek verifiziert den eingebetteten CMS-/PKCS#7-Block gegen die
 * Bytes, die der `/ByteRange` abdeckt, ohne Netzwerkzugriff und ohne
 * Trust-Prüfung. `isValid()` ist ausschließlich diese kryptografische Prüfung;
 * `isTrusted` bleibt unberührt und wird nicht ausgewertet. Es wird daher keine
 * qualifizierte elektronische Signatur behauptet.
 *
 * Zusätzlich bindet dieser Service die Prüfung an die Ursprungsbytes des
 * Portals: der erste ByteRange-Abschnitt muss bei 0 beginnen und mindestens die
 * Länge der ursprünglichen Belegdatei abdecken. Damit kann eine gültige
 * Signatur über ein fremdes Dokument nicht als Signatur unseres Belegs gelten.
 * Die Inhaltsbindung selbst (Byte-Präfix + JWT-Marker) prüft der Controller.
 */
final class EurPdfSignatureService
{
    private const BYTE_RANGE_PATTERN = '/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/';

    /**
     * @param  string  $uploadedBytes  die hochgeladene, signierte PDF
     * @param  string  $originalBytes  die unveränderte Ursprungsdatei des Portals
     */
    public function verify(string $uploadedBytes, string $originalBytes): bool
    {
        if ($originalBytes === '' || ! str_starts_with($uploadedBytes, $originalBytes)) {
            return false;
        }

        if (! $this->coversOriginalBytes($uploadedBytes, strlen($originalBytes))) {
            return false;
        }

        try {
            $report = (new Signet)->validate(new StringSource($uploadedBytes, 'signed.pdf'));
        } catch (Throwable) {
            // Unsigned, unreadable or PAdES-incompatible documents are a normal
            // rejection, not an exceptional condition of the upload path.
            return false;
        }

        if (! $report->isSigned() || ! $report->isValid()) {
            return false;
        }

        $signature = $report->latest();

        return $signature !== null
            && $signature->verified
            && $signature->coversWholeDocument
            && $signature->byteRangeSound;
    }

    /** Der letzte ByteRange muss bei 0 beginnen und die Ursprungsbytes decken. */
    private function coversOriginalBytes(string $uploadedBytes, int $originalLength): bool
    {
        if (preg_match_all(self::BYTE_RANGE_PATTERN, $uploadedBytes, $matches) < 1) {
            return false;
        }

        $last = count($matches[1]) - 1;

        return (int) $matches[1][$last] === 0
            && (int) $matches[2][$last] >= $originalLength;
    }
}
