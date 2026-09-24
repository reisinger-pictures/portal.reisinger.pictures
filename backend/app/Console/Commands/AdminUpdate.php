<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminUpdate extends Command
{
    protected $signature = 'admin:update';

    protected $description = 'Creates or rotates the bootstrap admin from configured credentials';

    public function handle(): int
    {
        $email = config('admin.email');
        $password = config('admin.password');

        if (! is_string($email) || trim($email) === '' || ! is_string($password) || trim($password) === '') {
            $this->error('ADMIN_EMAIL and ADMIN_PASSWORD must both be configured.');

            return self::FAILURE;
        }

        $admin = User::firstOrNew(['email' => $email]);
        if (! $admin->exists) {
            $admin->name = 'Admin';
        }

        // Always rotate the configured bootstrap password, including for an
        // existing account. This makes changing ADMIN_PASSWORD an explicit,
        // reliable credential-rotation operation on the next deployment.
        $admin->password = Hash::make($password);
        $admin->save();

        $roles = collect(UserRole::cases())
            ->map(static fn (UserRole $role): string => $role->value)
            ->all();
        $admin->roles()->syncWithoutDetaching(Role::whereIn('name', $roles)->pluck('id'));

        $this->info('Admin user created or password rotated successfully.');

        return self::SUCCESS;
    }
}
