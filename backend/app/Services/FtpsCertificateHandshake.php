<?php

namespace App\Services;

use App\Exceptions\FtpsCertificateHandshakeException;
use OpenSSLCertificate;
use Throwable;

/**
 * Reads the public certificate presented by the running SFTPGo FTPS binding.
 *
 * The service deliberately has no filesystem path or private-key access: the
 * only certificate it can return is the peer certificate from this connection.
 */
final class FtpsCertificateHandshake
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const MAX_REPLY_LINES = 100;

    public function fetchCertificatePem(): string
    {
        $host = config('services.ftp_transport.internal_host', 'sftpgo');
        $port = config('services.ftp_transport.internal_ftps_port', 989);
        $configuredMode = config('services.ftp_transport.ftps_tls_mode');
        $mode = $this->resolveMode($configuredMode);

        if (! is_string($host) || trim($host) === '' || str_contains($host, "\r") || str_contains($host, "\n")) {
            throw new FtpsCertificateHandshakeException('The internal FTPS host is not configured.');
        }

        if (! is_int($port) && (! is_string($port) || ! ctype_digit($port))) {
            throw new FtpsCertificateHandshakeException('The internal FTPS port is invalid.');
        }

        $port = (int) $port;
        if ($port < 1 || $port > 65535) {
            throw new FtpsCertificateHandshakeException('The internal FTPS port is invalid.');
        }

        $host = trim($host);
        $sniHost = trim($host, '[]');
        $socketHost = str_contains($host, ':') && ! str_starts_with($host, '[')
            ? '['.$host.']'
            : $host;
        $scheme = $mode === 'implicit' ? 'tls' : 'tcp';
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                // This is a certificate export, not a trust decision. The
                // self-signed certificate is intentionally captured as served.
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $sniHost,
            ],
        ]);

        $errorNumber = 0;
        $errorMessage = '';
        $stream = @stream_socket_client(
            sprintf('%s://%s:%d', $scheme, $socketHost, $port),
            $errorNumber,
            $errorMessage,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (! is_resource($stream)) {
            throw new FtpsCertificateHandshakeException('Could not connect to the internal FTPS service.');
        }

        try {
            stream_set_timeout($stream, self::CONNECT_TIMEOUT_SECONDS);

            if ($mode === 'explicit') {
                $this->readFtpReply($stream, '220');
                $this->writeAll($stream, "AUTH TLS\r\n");
                $this->readFtpReply($stream, '234');

                if (@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new FtpsCertificateHandshakeException('The explicit FTPS TLS handshake failed.');
                }
            }

            $parameters = stream_context_get_params($stream);
            $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
            if (! $certificate instanceof OpenSSLCertificate) {
                throw new FtpsCertificateHandshakeException('The FTPS service did not present a certificate.');
            }

            $pem = '';
            if (! @openssl_x509_export($certificate, $pem, true) || ! str_starts_with($pem, '-----BEGIN CERTIFICATE-----')) {
                throw new FtpsCertificateHandshakeException('The FTPS peer certificate could not be exported.');
            }

            return $pem;
        } catch (FtpsCertificateHandshakeException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new FtpsCertificateHandshakeException('The FTPS certificate handshake failed.', 0, $exception);
        } finally {
            fclose($stream);
        }
    }

    private function resolveMode(mixed $mode): string
    {
        return match (is_string($mode) || is_int($mode) ? (string) $mode : null) {
            '1', 'explicit' => 'explicit',
            '2', 'implicit' => 'implicit',
            default => throw new FtpsCertificateHandshakeException('The FTPS TLS mode is not configured.'),
        };
    }

    /** @param resource $stream */
    private function readFtpReply($stream, string $expectedCode): void
    {
        $firstLine = $this->readFtpLine($stream);
        if (preg_match('/^(\d{3})([ -])/', $firstLine, $matches) !== 1 || $matches[1] !== $expectedCode) {
            throw new FtpsCertificateHandshakeException('The FTPS service returned an unexpected FTP reply.');
        }

        if ($matches[2] === ' ') {
            return;
        }

        for ($lineNumber = 1; $lineNumber < self::MAX_REPLY_LINES; $lineNumber++) {
            $line = $this->readFtpLine($stream);
            if (str_starts_with($line, $expectedCode.' ')) {
                return;
            }
        }

        throw new FtpsCertificateHandshakeException('The FTPS service returned an incomplete FTP reply.');
    }

    /** @param resource $stream */
    private function readFtpLine($stream): string
    {
        $line = @fgets($stream, 8192);
        $metadata = stream_get_meta_data($stream);

        if ($line === false || ($metadata['timed_out'] ?? false)) {
            throw new FtpsCertificateHandshakeException('Timed out reading the FTPS service reply.');
        }

        // RFC 959 terminates a reply line with CRLF. A bare LF is a malformed
        // reply: accepting it would let a stray newline stand in for the end of
        // the AUTH TLS answer and shift every later read.
        if (! str_ends_with($line, "\r\n")) {
            throw new FtpsCertificateHandshakeException('The FTPS service returned a malformed FTP reply.');
        }

        return rtrim($line, "\r\n");
    }

    /** @param resource $stream */
    private function writeAll($stream, string $data): void
    {
        $remaining = $data;
        while ($remaining !== '') {
            $written = @fwrite($stream, $remaining);
            if ($written === false || $written === 0) {
                throw new FtpsCertificateHandshakeException('Could not send the FTPS AUTH TLS command.');
            }

            $remaining = substr($remaining, $written);
        }
    }
}
