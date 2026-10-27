<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

class FtpCertificateHandshakeTest extends TestCase
{
    use RefreshDatabase;

    /** @var resource|null */
    private $serverProcess = null;

    /** @var array<int, resource> */
    private array $serverPipes = [];

    /** @var array<int, string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        $this->stopServer();
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }

        $this->temporaryFiles = [];

        parent::tearDown();
    }

    public function test_explicit_ftps_download_returns_only_the_live_public_certificate(): void
    {
        [$certificatePem, $certificatePath, $privateKeyPath] = $this->createCertificatePair();
        $port = $this->unusedLoopbackPort();
        $this->startServer($port, 'explicit', $certificatePath, $privateKeyPath);
        $this->configureEndpoint($port, '1');

        $response = $this->downloadAsPhotographer();

        $this->assertDownloadedCertificate($response, $certificatePem);
    }

    public function test_implicit_ftps_download_returns_only_the_live_public_certificate(): void
    {
        [$certificatePem, $certificatePath, $privateKeyPath] = $this->createCertificatePair();
        $port = $this->unusedLoopbackPort();
        $this->startServer($port, 'implicit', $certificatePath, $privateKeyPath);
        $this->configureEndpoint($port, '2');

        $response = $this->downloadAsPhotographer();

        $this->assertDownloadedCertificate($response, $certificatePem);
    }

    public function test_a_reply_with_a_bare_line_feed_is_rejected(): void
    {
        [, $certificatePath, $privateKeyPath] = $this->createCertificatePair();
        $port = $this->unusedLoopbackPort();
        $this->startServer($port, 'bare_lf', $certificatePath, $privateKeyPath);
        $this->configureEndpoint($port, '1');

        $response = $this->downloadAsPhotographer();

        $response->assertStatus(503);
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $response->getContent());
    }

    public function test_a_refused_connection_returns_service_unavailable_without_a_stale_certificate(): void
    {
        $this->configureEndpoint($this->unusedLoopbackPort(), '1');

        $response = $this->downloadAsPhotographer();

        $response->assertStatus(503)
            ->assertJsonPath('error', 'Der FTPS-Dienst ist derzeit nicht erreichbar. Bitte versuchen Sie es später erneut.');
        $this->assertStringNotContainsString('BEGIN CERTIFICATE', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());
    }

    private function assertDownloadedCertificate($response, string $certificatePem): void
    {
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/x-pem-file')
            ->assertHeader('Content-Disposition', 'attachment; filename="ROOT.PEM"');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($certificatePem, $response->getContent());
        $this->assertStringNotContainsString('PRIVATE KEY', $response->getContent());
    }

    private function downloadAsPhotographer()
    {
        $photographer = User::factory()->create();
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        return $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->get('/api/management/ftp/certificate');
    }

    private function configureEndpoint(int $port, string $tlsMode): void
    {
        config([
            'services.ftp_transport.internal_host' => '127.0.0.1',
            'services.ftp_transport.internal_ftps_port' => $port,
            'services.ftp_transport.ftps_tls_mode' => $tlsMode,
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function createCertificatePair(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        Assert::assertNotFalse($privateKey);

        $request = openssl_csr_new(['commonName' => 'localhost'], $privateKey, ['digest_alg' => 'sha256']);
        Assert::assertNotFalse($request);
        $certificate = openssl_csr_sign($request, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        Assert::assertNotFalse($certificate);

        $certificatePem = '';
        Assert::assertTrue(openssl_x509_export($certificate, $certificatePem, true));
        $privateKeyPem = '';
        Assert::assertTrue(openssl_pkey_export($privateKey, $privateKeyPem));

        $directory = sys_get_temp_dir().'/ftps-certificate-test-'.bin2hex(random_bytes(8));
        Assert::assertTrue(mkdir($directory, 0700));
        $certificatePath = $directory.'/server-certificate.pem';
        $privateKeyPath = $directory.'/server-private-key.pem';
        Assert::assertNotFalse(file_put_contents($certificatePath, $certificatePem, LOCK_EX));
        Assert::assertNotFalse(file_put_contents($privateKeyPath, $privateKeyPem, LOCK_EX));

        $this->temporaryFiles = [$certificatePath, $privateKeyPath, $directory];

        return [$certificatePem, $certificatePath, $privateKeyPath];
    }

    private function unusedLoopbackPort(): int
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        Assert::assertIsResource($listener);

        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        Assert::assertIsString($address);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    private function startServer(int $port, string $mode, string $certificatePath, string $privateKeyPath): void
    {
        $command = [
            PHP_BINARY,
            base_path('tests/Fixtures/ftps-loopback-server.php'),
            '127.0.0.1',
            (string) $port,
            $mode,
            $certificatePath,
            $privateKeyPath,
        ];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes, base_path());
        Assert::assertIsResource($process, 'The loopback TLS child process could not be started.');

        $this->serverProcess = $process;
        $this->serverPipes = $pipes;
        fclose($this->serverPipes[0]);

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $read = [$this->serverPipes[1], $this->serverPipes[2]];
            $write = [];
            $except = [];
            $ready = stream_select($read, $write, $except, 0, 100_000);

            if ($ready === false) {
                break;
            }

            foreach ($read as $pipe) {
                $line = fgets($pipe);
                if ($pipe === $this->serverPipes[1] && $line === "READY\n") {
                    return;
                }
            }

            if (! proc_get_status($this->serverProcess)['running']) {
                break;
            }
        }

        $error = stream_get_contents($this->serverPipes[2]);
        $this->stopServer();
        Assert::fail('The loopback FTPS server did not become ready: '.trim($error));
    }

    private function stopServer(): void
    {
        foreach ($this->serverPipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        $this->serverPipes = [];

        if (is_resource($this->serverProcess)) {
            $status = proc_get_status($this->serverProcess);
            if ($status['running']) {
                proc_terminate($this->serverProcess);
            }
            proc_close($this->serverProcess);
        }

        $this->serverProcess = null;
    }
}
