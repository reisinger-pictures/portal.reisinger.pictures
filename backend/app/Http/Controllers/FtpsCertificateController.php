<?php

namespace App\Http\Controllers;

use App\Exceptions\FtpsCertificateHandshakeException;
use App\Services\FtpsCertificateHandshake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

final class FtpsCertificateController extends Controller
{
    public function download(FtpsCertificateHandshake $handshake): Response|JsonResponse
    {
        try {
            $certificate = $handshake->fetchCertificatePem();
        } catch (FtpsCertificateHandshakeException) {
            // Never log the exception or handshake payload: the certificate and
            // all TLS material are intentionally absent from application logs.
            Log::error('The live FTPS certificate could not be retrieved.');

            return response()->json([
                'error' => 'Der FTPS-Dienst ist derzeit nicht erreichbar. Bitte versuchen Sie es später erneut.',
            ], 503);
        }

        return response($certificate, 200, [
            'Content-Type' => 'application/x-pem-file',
            'Content-Disposition' => 'attachment; filename="ROOT.PEM"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
