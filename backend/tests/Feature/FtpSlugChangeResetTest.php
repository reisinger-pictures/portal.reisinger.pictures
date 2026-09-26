<?php

namespace Tests\Feature;

use App\Exceptions\SftpGoException;
use App\Models\User;
use App\Services\FtpCredentialService;
use App\Services\SftpGoClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-M34: slug change = reset. Deleting the old account, updating the user,
 * and provisioning the new account with a fresh password must be one
 * operation. The password is returned once and stored nowhere.
 */
class FtpSlugChangeResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_user_proxies_to_client(): void
    {
        $client = $this->mock(SftpGoClient::class);
        $client->shouldReceive('deleteUser')->once()->with('old-slug');

        (new FtpCredentialService($client))->deleteUser('old-slug');

        $this->assertTrue(true);
    }

    public function test_delete_propagates_client_failure(): void
    {
        $client = $this->mock(SftpGoClient::class);
        $client->shouldReceive('deleteUser')->once()
            ->andThrow(new SftpGoException(
                SftpGoException::REASON_NOT_FOUND,
                'delete failed'
            ));

        $this->expectException(SftpGoException::class);

        (new FtpCredentialService($client))->deleteUser('old-slug');
    }

    public function test_provision_returns_generated_password(): void
    {
        config(['filesystems.disks.ftp_inbox.root' => '/home/webadmin/websites/ftp']);

        $user = User::factory()->create(['ftp_slug' => 'valid-slug']);

        $client = $this->mock(SftpGoClient::class);
        $client->shouldReceive('provisionUser')->once()
            ->with('valid-slug', \Mockery::type('string'), \Mockery::type('string'))
            ->andReturn(['id' => 42]);

        $svc = new FtpCredentialService($client);
        $password = $svc->provisionAndShow($user);

        $this->assertMatchesRegularExpression('/^[a-z0-9]{16,24}$/', $password);
    }

    public function test_password_is_never_persisted(): void
    {
        config(['filesystems.disks.ftp_inbox.root' => '/home/webadmin/websites/ftp']);

        $user = User::factory()->create(['ftp_slug' => 'valid-slug']);

        $client = $this->mock(SftpGoClient::class);
        $client->shouldReceive('provisionUser')->once()->andReturn(['id' => 42]);

        $svc = new FtpCredentialService($client);
        $password = $svc->provisionAndShow($user);

        $this->assertDatabaseMissing('users', [
            'id' => $user->id,
            'ftp_password' => $password,
        ]);
        $this->assertStringNotContainsString($password, json_encode($user->getAttributes()));
    }

    public function test_slug_change_flow_in_order(): void
    {
        config(['filesystems.disks.ftp_inbox.root' => '/home/webadmin/websites/ftp']);

        $user = User::factory()->create(['ftp_slug' => 'old-slug']);

        $client = $this->mock(SftpGoClient::class);
        $order = [];

        $client->shouldReceive('deleteUser')->once()->with('old-slug')
            ->andReturnUsing(function () use (&$order) {
                $order[] = 'delete';
            });
        $client->shouldReceive('provisionUser')->once()
            ->andReturnUsing(function () use (&$order) {
                $order[] = 'provision';

                return ['id' => 42];
            });

        $svc = new FtpCredentialService($client);
        $svc->deleteUser('old-slug');
        $user->update(['ftp_slug' => 'new-slug']);
        $password = $svc->provisionAndShow($user->fresh());

        $this->assertSame(['delete', 'provision'], $order);
        $this->assertSame('new-slug', $user->fresh()->ftp_slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{16,24}$/', $password);
    }
}
