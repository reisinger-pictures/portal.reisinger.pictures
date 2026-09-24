<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('admin.email', 'bootstrap-admin@example.test');
        Config::set('admin.password', 'initial-password-for-tests');
    }

    public function test_admin_update_rotates_the_existing_admin_password(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', config('admin.email'))->firstOrFail();
        $initialHash = $admin->password;
        $this->assertTrue(Hash::check('initial-password-for-tests', $initialHash));

        Config::set('admin.password', 'rotated-password-for-tests');
        $this->artisan('admin:update')->assertExitCode(Command::SUCCESS);

        $admin->refresh();
        $this->assertNotSame($initialHash, $admin->password);
        $this->assertTrue(Hash::check('rotated-password-for-tests', $admin->password));
        $this->assertFalse(Hash::check('initial-password-for-tests', $admin->password));
    }

    public function test_admin_update_fails_closed_without_a_password(): void
    {
        Config::set('admin.password');

        $this->artisan('admin:update')->assertExitCode(Command::FAILURE);

        $this->assertDatabaseMissing('users', ['email' => config('admin.email')]);
    }

    public function test_admin_update_fails_closed_without_an_email(): void
    {
        Config::set('admin.email');

        $this->artisan('admin:update')->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('users', 0);
    }
}
